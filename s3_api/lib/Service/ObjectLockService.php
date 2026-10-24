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
 * `ILockingProvider` is not usable here: with no distributed cache configured
 * the server installs NoopLockingProvider, which does nothing at all. Inserting
 * a row against a unique index is atomic in every supported database, so that is
 * the primitive used.
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
	 * The price: after a crash the key refuses conditional writes (503
	 * SlowDown) for up to this long. Database advisory locks (GET_LOCK,
	 * pg_advisory_lock) die with the connection and would avoid both problems,
	 * but differ per database and SQLite has none.
	 */
	private const STALE_AFTER_SECONDS = 900;

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
	 * @return T
	 * @throws S3AuthException when the lock cannot be acquired in time
	 */
	public function withLock(Folder $bucketFolder, string $objectKey, \Closure $operation) {
		$lockKey = hash('sha256', $this->locationFor($bucketFolder, $objectKey));

		$lockId = $this->acquire($lockKey, $objectKey);
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
	private function acquire(string $lockKey, string $objectKey): int {
		$deadline = microtime(true) + self::WAIT_TIMEOUT_SECONDS;
		$sleep = self::RETRY_SLEEP_MIN_US;

		while (true) {
			$id = $this->tryInsert($lockKey);
			if ($id !== null) {
				return $id;
			}

			// Someone holds it. Drop it if it is old enough to be a leftover
			// from a process that died mid-write.
			if ($this->breakIfStale($lockKey)) {
				$id = $this->tryInsert($lockKey);
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
	private function tryInsert(string $lockKey): ?int {
		$qb = $this->db->getQueryBuilder();
		$qb->insert('s3api_locks')
			->values([
				'lock_key' => $qb->createNamedParameter($lockKey),
				'acquired_at' => $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT),
			]);

		try {
			$qb->executeStatement();
		} catch (DbException $e) {
			// The unique index rejected it: another request holds the lock.
			if ($e->getReason() === DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				return null;
			}
			throw $e;
		}

		return $qb->getLastInsertId();
	}

	/**
	 * @return bool whether a stale row was removed
	 */
	private function breakIfStale(string $lockKey): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->delete('s3api_locks')
			->where($qb->expr()->eq('lock_key', $qb->createNamedParameter($lockKey)))
			->andWhere($qb->expr()->lt(
				'acquired_at',
				$qb->createNamedParameter(time() - self::STALE_AFTER_SECONDS, IQueryBuilder::PARAM_INT),
			));

		return $qb->executeStatement() > 0;
	}

	/**
	 * Delete this request's own row, by id rather than by key: if the lock was
	 * broken as stale meanwhile, the row under this key belongs to whoever
	 * took it next, and removing that would let a third request in while the
	 * second is still writing.
	 */
	private function release(int $lockId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete('s3api_locks')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($lockId, IQueryBuilder::PARAM_INT)));

		try {
			$qb->executeStatement();
		} catch (DbException $e) {
			// Losing the release would wedge the key until the stale timeout;
			// log it rather than masking the operation's own result.
			$this->logger->error('Failed to release S3 object lock', [
				'app' => 's3_api',
				'exception' => $e,
			]);
		}
	}
}
