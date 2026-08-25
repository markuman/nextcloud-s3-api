<?php

declare(strict_types=1);

namespace OCA\S3Api\Service;

use OCP\DB\Exception as DbException;
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
	 * would otherwise block the key forever.
	 */
	private const STALE_AFTER_SECONDS = 120;

	/** Total time to wait for a contended lock. */
	private const WAIT_TIMEOUT_SECONDS = 10;

	/** Sleep between attempts, in microseconds. */
	private const RETRY_SLEEP_US = 20_000;

	public function __construct(
		private IDBConnection $db,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Run `$operation` while holding the lock for `$bucketId`/`$objectKey`.
	 *
	 * @template T
	 * @param \Closure(): T $operation
	 * @return T
	 * @throws S3AuthException when the lock cannot be acquired in time
	 */
	public function withLock(int $bucketId, string $objectKey, \Closure $operation) {
		$lockKey = hash('sha256', $bucketId . "\0" . $objectKey);

		$this->acquire($lockKey, $objectKey);
		try {
			return $operation();
		} finally {
			$this->release($lockKey);
		}
	}

	/**
	 * @throws S3AuthException
	 */
	private function acquire(string $lockKey, string $objectKey): void {
		$deadline = microtime(true) + self::WAIT_TIMEOUT_SECONDS;

		while (true) {
			if ($this->tryInsert($lockKey)) {
				return;
			}

			// Someone holds it. Drop it if it is old enough to be a leftover
			// from a process that died mid-write.
			if ($this->breakIfStale($lockKey) && $this->tryInsert($lockKey)) {
				$this->logger->warning('Broke a stale S3 object lock', [
					'app' => 's3_api',
					'key' => $objectKey,
				]);
				return;
			}

			if (microtime(true) >= $deadline) {
				throw new S3AuthException(
					'SlowDown',
					'The object is busy, please retry',
					\OCP\AppFramework\Http::STATUS_SERVICE_UNAVAILABLE,
				);
			}

			usleep(self::RETRY_SLEEP_US);
		}
	}

	private function tryInsert(string $lockKey): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->insert('s3api_locks')
			->values([
				'lock_key' => $qb->createNamedParameter($lockKey),
				'acquired_at' => $qb->createNamedParameter(time(), \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
			]);

		try {
			$qb->executeStatement();
			return true;
		} catch (DbException $e) {
			// The unique index rejected it: another request holds the lock.
			if ($e->getReason() === DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				return false;
			}
			throw $e;
		}
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
				$qb->createNamedParameter(time() - self::STALE_AFTER_SECONDS, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
			));

		return $qb->executeStatement() > 0;
	}

	private function release(string $lockKey): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete('s3api_locks')
			->where($qb->expr()->eq('lock_key', $qb->createNamedParameter($lockKey)));

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
