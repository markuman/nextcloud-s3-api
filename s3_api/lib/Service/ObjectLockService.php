<?php

declare(strict_types=1);

namespace OCA\S3Api\Service;

use OCP\DB\Exception as DbException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\Folder;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * A short-lived mutex around a single object key.
 *
 * Needed because a conditional write has to read the current state and write
 * based on it as one indivisible step. Without that, two concurrent
 * `If-None-Match: *` requests can both see "absent" and both create the object,
 * so a client using conditional writes for compare-and-swap silently loses an
 * update.
 *
 * `ILockingProvider` does not fit: an admin can switch it off
 * (`filelocking.enabled` false installs NoopLockingProvider, which locks
 * nothing), and it does not wait for a lock, it throws at once. Without a
 * memcache for locking it is the database anyway (DBLockingProvider).
 * Inserting a row against a unique index is atomic in every supported
 * database, so that is the primitive used, with waiting and stale locks
 * handled here.
 */
class ObjectLockService {

	/**
	 * How long a lock may be held before another request may break it.
	 *
	 * Only relevant when a process dies between acquire and release: the row
	 * would otherwise block the key forever. Breaking a lock whose holder is
	 * still alive is the worse failure -- two writers, and the conditional
	 * write it protects silently loses an update -- so this is generous: the
	 * write under the lock stores the whole body, which on slow storage (object
	 * storage as primary, a remote mount) runs well past two minutes for a big
	 * object. PHP gives no reliable bound either: max_execution_time counts CPU
	 * time on Linux, not time spent waiting on storage, and php-fpm's
	 * wall-clock request_terminate_timeout is off unless the admin sets it.
	 * CleanupService sweeps what is left after an hour.
	 *
	 * A write of known size holds the lock longer than this: see
	 * WRITE_RATE_FLOOR. Locks that guard nothing but work, like EtagService's,
	 * pass a shorter limit of their own.
	 *
	 * The price: after a crash the key refuses conditional writes (503
	 * SlowDown) for up to this long. Database advisory locks (GET_LOCK,
	 * pg_advisory_lock) die with the connection and would avoid both problems,
	 * but differ per database and SQLite has none.
	 */
	private const STALE_AFTER_SECONDS = 900;

	/**
	 * Slowest storage write the lock allows for, in bytes per second.
	 *
	 * STALE_AFTER_SECONDS was sized for one body of at most 5 GiB. Completing
	 * a multipart upload or copying an object writes any size under the lock,
	 * and a 50 GiB object can take far longer than fifteen minutes to store.
	 * So a write that knows its size moves its row's `acquired_at` forward by
	 * as long as that many bytes take at this rate: the lock counts as stale
	 * that much later, and the cleanup job's hour starts that much later too.
	 */
	private const WRITE_RATE_FLOOR = 4 * 1024 * 1024;

	/**
	 * Most a write may extend its lock by: six hours, some 84 GiB at
	 * WRITE_RATE_FLOOR. A 10 GiB object adds about 43 minutes. The price of
	 * the extension is paid after a crash, when conditional writes to the
	 * key answer 503 until it runs out; past six hours a larger object's
	 * lock may be broken while it is still being stored.
	 */
	private const MAX_LEASE_SECONDS = 6 * 3600;

	/**
	 * Total time to wait for a contended lock.
	 *
	 * Every waiting request holds a PHP worker, so a pile of conditional
	 * writes to one key could otherwise tie up the pool. Past this the
	 * client gets 503 SlowDown and retries on its own schedule, which every
	 * S3 client does.
	 */
	private const WAIT_TIMEOUT_SECONDS = 3;

	/** First sleep between attempts, in microseconds; doubles from there. */
	private const RETRY_SLEEP_MIN_US = 10_000;

	/** Longest sleep between attempts, in microseconds. */
	private const RETRY_SLEEP_MAX_US = 250_000;

	public function __construct(
		private IDBConnection $db,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Run `$operation` while holding the lock for the file `$objectKey` names
	 * inside `$bucketFolder`.
	 *
	 * Keyed on the resolved storage path rather than on the bucket id, because
	 * the same file can be reached through more than one bucket: nothing stops
	 * a user registering `/Documents` as `foo` and `/Documents/sub` as `bar`,
	 * and then `foo/sub/x.txt` and `bar/x.txt` are one file. Hashing the bucket
	 * id gave those two names separate locks, which silently voids the
	 * mutual exclusion a conditional write depends on — two `If-None-Match: *`
	 * requests, one per name, would both see "absent" and both succeed.
	 *
	 * The bucket's own path is not enough either, for the same reason in the
	 * other direction: it is the full path of the *object* that has to agree.
	 *
	 * @template T
	 * @param \Closure(): T $operation
	 * @param int|float $writeBytes how much `$operation` writes, when known;
	 *        holds the lock against breaking for longer, see WRITE_RATE_FLOOR
	 * @return T
	 * @throws S3AuthException when the lock cannot be acquired in time
	 */
	public function withLock(Folder $bucketFolder, string $objectKey, \Closure $operation, int|float $writeBytes = 0) {
		$name = $this->locationFor($bucketFolder, $objectKey);
		$lease = (int)min(ceil($writeBytes / self::WRITE_RATE_FLOOR), self::MAX_LEASE_SECONDS);

		return $this->run($name, $operation, self::STALE_AFTER_SECONDS, $lease);
	}

	/**
	 * Run `$operation` while holding the lock called `$name`, for whatever
	 * is not an object key: EtagService locks a file id while it hashes.
	 *
	 * Names share one table with the object locks, so they must not look like
	 * what locationFor() produces: a numeric storage id or `view`, a colon,
	 * a path.
	 *
	 * @template T
	 * @param \Closure(): T $operation
	 * @param int $staleAfter seconds after which a waiter breaks the lock;
	 *        short for a lock whose holder only saves others work, as
	 *        breaking it early costs that work twice and nothing else
	 * @return T
	 * @throws S3AuthException when the lock cannot be acquired in time
	 */
	public function withNamedLock(string $name, \Closure $operation, int $staleAfter) {
		return $this->run($name, $operation, $staleAfter, 0);
	}

	/**
	 * @template T
	 * @param \Closure(): T $operation
	 * @return T
	 */
	private function run(string $name, \Closure $operation, int $staleAfter, int $lease) {
		$lockId = $this->acquire(hash('sha256', $name), $name, $staleAfter, $lease);
		try {
			return $operation();
		} finally {
			$this->release($lockId);
		}
	}

	/**
	 * Where on which storage a key's file lives: `<storage>:<path>`.
	 *
	 * Built by concatenation rather than by asking the file for its path: the
	 * object usually does not exist yet — `If-None-Match: *` is precisely the
	 * case where it must not — so there is nothing to look up. The bucket
	 * folder does exist, and the key has been through isUsableKey(), which
	 * rejects `.`, `..`, empty segments and other spellings Nextcloud would
	 * fold into another name. So the join names exactly one file.
	 *
	 * The bucket folder is located by its file cache row, not by its path in
	 * the user's view: a folder shared with Bob is `/alice/files/Proj` for
	 * Alice and `/bob/files/Shared/Proj` for Bob, and a lock keyed on either
	 * would not exclude a writer coming through the other. The row holds the
	 * storage the folder really is on and its path there, the same for
	 * everyone who can reach it -- and for a share of a subfolder too, since
	 * the key's remaining segments are appended to the same place. Should the
	 * lookup fail, the view path is still a lock among this user's buckets.
	 */
	private function locationFor(Folder $bucketFolder, string $objectKey): string {
		$location = null;
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->select('storage', 'path')
				->from('filecache')
				->where($qb->expr()->eq('fileid', $qb->createNamedParameter($bucketFolder->getId(), IQueryBuilder::PARAM_INT)));
			$result = $qb->executeQuery();
			$row = $result->fetchAssociative();
			$result->closeCursor();
			if ($row !== false) {
				$location = $row['storage'] . ':' . rtrim((string)$row['path'], '/');
			}
		} catch (\Throwable $e) {
			$this->logger->warning('Could not locate an S3 bucket folder in the file cache', [
				'app' => 's3_api',
				'exception' => $e,
			]);
		}

		$location ??= 'view:' . rtrim($bucketFolder->getPath(), '/');

		return $location . '/' . $objectKey;
	}

	/**
	 * @return int the id of the row that is the lock, for release()
	 * @throws S3AuthException
	 */
	private function acquire(string $lockKey, string $objectKey, int $staleAfter, int $lease): int {
		$deadline = microtime(true) + self::WAIT_TIMEOUT_SECONDS;
		$sleep = self::RETRY_SLEEP_MIN_US;

		while (true) {
			$id = $this->tryInsert($lockKey, $lease);
			if ($id !== null) {
				return $id;
			}

			// Someone holds it. Drop it if it is old enough to be a leftover
			// from a process that died mid-write.
			if ($this->breakIfStale($lockKey, $staleAfter)) {
				$id = $this->tryInsert($lockKey, $lease);
				if ($id !== null) {
					$this->logger->warning('Broke a stale S3 object lock', [
						'app' => 's3_api',
						'key' => $objectKey,
					]);
					return $id;
				}
			}

			if (microtime(true) >= $deadline) {
				throw new S3AuthException(
					'SlowDown',
					'The object is busy, please retry',
					\OCP\AppFramework\Http::STATUS_SERVICE_UNAVAILABLE,
				);
			}

			// Back off, with jitter so waiters do not retry in lockstep.
			usleep(random_int(intdiv($sleep, 2), $sleep));
			$sleep = min($sleep * 2, self::RETRY_SLEEP_MAX_US);
		}
	}

	/**
	 * @return ?int the new row's id, or null when another request holds the lock
	 */
	private function tryInsert(string $lockKey, int $lease): ?int {
		$qb = $this->db->getQueryBuilder();
		$qb->insert('s3api_locks')
			->values([
				'lock_key' => $qb->createNamedParameter($lockKey),
				'acquired_at' => $qb->createNamedParameter(time() + $lease, IQueryBuilder::PARAM_INT),
			]);

		try {
			$qb->executeStatement();
		} catch (DbException $e) {
			// The unique index rejected it: another request holds the lock.
			// Or InnoDB gave up on the insert as a deadlock, which it does
			// when several of them wait on one key of a unique index while
			// its holder deletes the row: the statement is rolled back, and
			// trying again is what MariaDB and MySQL ask for.
			if ($this->isContention($e)) {
				return null;
			}
			throw $e;
		}

		return $qb->getLastInsertId();
	}

	/**
	 * @return bool whether a stale row was removed
	 */
	private function breakIfStale(string $lockKey, int $staleAfter): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->delete('s3api_locks')
			->where($qb->expr()->eq('lock_key', $qb->createNamedParameter($lockKey)))
			->andWhere($qb->expr()->lt(
				'acquired_at',
				$qb->createNamedParameter(time() - $staleAfter, IQueryBuilder::PARAM_INT),
			));

		try {
			return $qb->executeStatement() > 0;
		} catch (DbException $e) {
			if ($this->isContention($e)) {
				return false;
			}
			throw $e;
		}
	}

	private function isContention(DbException $e): bool {
		return in_array($e->getReason(), [
			DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION,
			DbException::REASON_DEADLOCK,
			DbException::REASON_LOCK_WAIT_TIMEOUT,
		], true);
	}

	/**
	 * Delete this request's own row, by id rather than by key: if the lock was
	 * broken as stale meanwhile, the row under this key belongs to whoever
	 * took it next, and removing that would let a third request in while the
	 * second is still writing.
	 */
	private function release(int $lockId): void {
		// A deadlock rolls the delete back; losing the release would wedge
		// the key until the stale timeout, so it is tried again.
		for ($attempt = 1; ; $attempt++) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete('s3api_locks')
				->where($qb->expr()->eq('id', $qb->createNamedParameter($lockId, IQueryBuilder::PARAM_INT)));

			try {
				$qb->executeStatement();
				return;
			} catch (DbException $e) {
				if ($attempt < 5 && $this->isContention($e)) {
					usleep(random_int(5_000, 20_000));
					continue;
				}
				// Log it rather than masking the operation's own result.
				$this->logger->error('Failed to release S3 object lock', [
					'app' => 's3_api',
					'exception' => $e,
				]);
				return;
			}
		}
	}
}
