<?php

declare(strict_types=1);

namespace OCA\S3Api\Service;

use OCA\S3Api\Db\BucketMapper;
use OCA\S3Api\Db\UploadMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\Folder;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Reclaims what interrupted requests leave behind.
 *
 * A client that starts a multipart upload and never completes or aborts it
 * leaves its parts in the bucket forever, and a process killed between
 * acquiring and releasing a write lock leaves a row that blocks the key until
 * it is considered stale. Neither is self-healing, hence this sweep.
 */
class CleanupService {

	/**
	 * Abandoned uploads discarded per run. Each one is a folder delete on
	 * the owner's storage; the job runs every few hours, so a backlog drains
	 * over a few runs instead of one run holding cron for an hour.
	 */
	private const UPLOADS_PER_RUN = 200;

	/**
	 * Locks older than this are swept. Well above the point where
	 * ObjectLockService breaks a lock as stale, so this only removes rows of
	 * keys nobody has written since.
	 */
	private const LOCK_MAX_AGE_SECONDS = 3600;

	public function __construct(
		private IDBConnection $db,
		private UploadMapper $uploadMapper,
		private BucketMapper $bucketMapper,
		private BucketService $bucketService,
		private MultipartService $multipartService,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @return array{uploads: int, locks: int, etags: int} how many rows went away
	 */
	public function run(): array {
		return [
			'uploads' => $this->pruneUploads(),
			'locks' => $this->pruneLocks(),
			'etags' => $this->pruneEtags(),
		];
	}

	private function pruneUploads(): int {
		$cutoff = new \DateTime('@' . (time() - MultipartService::UPLOAD_MAX_AGE_SECONDS));

		$removed = 0;
		foreach ($this->uploadMapper->findOlderThan($cutoff, self::UPLOADS_PER_RUN) as $upload) {
			$folder = $this->bucketFolder($upload->getBucketId(), $upload->getUserId());

			try {
				if ($folder !== null) {
					$this->multipartService->discard($folder, $upload);
				} else {
					// The bucket is gone; the parts went with it.
					$this->uploadMapper->delete($upload);
				}
				$removed++;
			} catch (\Throwable $e) {
				$this->logger->warning('Could not discard an abandoned multipart upload', [
					'app' => 's3_api',
					'uploadId' => $upload->getUploadId(),
					'exception' => $e,
				]);
			}
		}

		return $removed;
	}

	private function pruneLocks(): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete('s3api_locks')
			->where($qb->expr()->lt(
				'acquired_at',
				$qb->createNamedParameter(time() - self::LOCK_MAX_AGE_SECONDS, IQueryBuilder::PARAM_INT),
			));

		return $qb->executeStatement();
	}

	/**
	 * Drop ETags whose file no longer exists.
	 *
	 * File ids are reused, so a leftover row could otherwise be served for an
	 * unrelated object.
	 */
	private function pruneEtags(): int {
		// Resolving each id through IRootFolder would be correct but costs a
		// query per row; `filecache` is where file ids live, so a left join
		// finds the orphans in one pass. Guarded because it reaches into a table
		// this app does not own.
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->select('e.file_id')
				->from('s3api_etags', 'e')
				->leftJoin('e', 'filecache', 'f', $qb->expr()->eq('e.file_id', 'f.fileid'))
				->where($qb->expr()->isNull('f.fileid'))
				->setMaxResults(5000);

			$result = $qb->executeQuery();
			$orphans = array_map('intval', $result->fetchFirstColumn());
			$result->closeCursor();
		} catch (\Throwable $e) {
			$this->logger->warning('Could not look for orphaned S3 ETags', [
				'app' => 's3_api',
				'exception' => $e,
			]);
			return 0;
		}

		if ($orphans === []) {
			return 0;
		}

		$removed = 0;
		foreach (array_chunk($orphans, 500) as $chunk) {
			$delete = $this->db->getQueryBuilder();
			$delete->delete('s3api_etags')
				->where($delete->expr()->in(
					'file_id',
					$delete->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY),
				));
			$removed += $delete->executeStatement();
		}

		return $removed;
	}

	private function bucketFolder(int $bucketId, string $userId): ?Folder {
		try {
			$bucket = $this->bucketMapper->findByIdAndUser($bucketId, $userId);
		} catch (DoesNotExistException) {
			return null;
		}

		return $this->bucketService->resolveFolder($bucket);
	}
}
