<?php

declare(strict_types=1);

namespace OCA\S3Api\Service;

use OCP\DB\Exception as DbException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\File;
use OCP\IDBConnection;

/**
 * ETags derived from an object's content.
 *
 * Nextcloud's etag is `md5(mtime + inode + dev + size)` and mtime only has
 * second resolution, so two writes of equally sized but different content
 * inside one second yield the same etag. S3 clients treat the ETag as the
 * object's version and use it for compare-and-swap, so such a collision makes a
 * lost update look like a success.
 *
 * The etag is therefore an MD5 of the bytes, stored per file id. That also
 * matches what clients expect from S3 for single-part objects. Multipart
 * objects keep S3's compound form (`<md5-of-part-md5s>-<n>`), which is why the
 * value is stored rather than always recomputed.
 *
 * A stored value is current while the file has the size, mtime and Nextcloud
 * etag it had when the value was recorded. Size and mtime alone miss a change
 * that keeps both -- a client that sets the mtime, a write within the same
 * second -- and then If-Match would match a version that is gone. Nextcloud
 * gives the file a new etag on every write it sees, so that one catches it.
 */
class EtagService {

	/**
	 * When another request may take over a hash that seems to have stalled.
	 *
	 * Short: a hasher that died -- php-fpm's timeout, the OOM killer, a
	 * restart -- would otherwise keep every read of the file at 503 until an
	 * object lock's fifteen minutes are up. Taking over from one that is
	 * merely slow costs a second hash of the file, nothing more.
	 */
	private const HASH_LOCK_STALE_SECONDS = 60;

	public function __construct(
		private IDBConnection $db,
		private ObjectLockService $objectLock,
	) {
	}

	/**
	 * The ETag to report for a file, without surrounding quotes.
	 *
	 * Falls back to hashing the content when nothing is recorded yet -- the file
	 * may predate this app or have been written through the web UI, WebDAV or
	 * the sync client.
	 *
	 * Hashing reads the whole file before the request can answer, which for
	 * a large one takes longer than clients wait. Each retry used to start a
	 * hash of its own next to the ones still running. Now one request per
	 * file hashes; the others wait for it briefly and, if it takes longer,
	 * get 503 SlowDown and retry until the value is recorded.
	 *
	 * @throws S3AuthException SlowDown while another request hashes the file
	 */
	public function get(File $file): string {
		$etag = $this->current($file);
		if ($etag !== null) {
			return $etag;
		}

		return $this->objectLock->withNamedLock('etag:' . $file->getId(), function () use ($file): string {
			// Whoever held the lock before may have recorded it.
			$etag = $this->current($file);
			if ($etag !== null) {
				return $etag;
			}

			// Either unknown or changed behind our back: recompute from content.
			$etag = $this->hashContent($file);
			$this->store($file, $etag);
			return $etag;
		}, self::HASH_LOCK_STALE_SECONDS);
	}

	/**
	 * The recorded ETag, if it is still current.
	 */
	private function current(File $file): ?string {
		$stored = $this->lookup($file->getId());
		if ($stored === null || !self::isCurrent($stored, $file)) {
			return null;
		}

		// Recorded before 1.7.3, without Nextcloud's etag: trust it this once,
		// as before, and record the etag from now on.
		if ($stored['nc_etag'] === null) {
			$this->store($file, $stored['etag']);
		}

		return $stored['etag'];
	}

	/**
	 * @param array{etag: string, file_size: int, file_mtime: int, nc_etag: ?string} $stored
	 */
	private static function isCurrent(array $stored, File $file): bool {
		return $stored['file_size'] === (int)$file->getSize()
			&& $stored['file_mtime'] === $file->getMTime()
			&& ($stored['nc_etag'] === null || $stored['nc_etag'] === $file->getEtag());
	}

	/**
	 * Record an ETag we already know, e.g. the compound value produced by
	 * completing a multipart upload.
	 */
	public function set(File $file, string $etag): void {
		$this->store($file, $etag);
	}

	/**
	 * Compute and record the ETag for freshly written content.
	 */
	public function refresh(File $file): string {
		$etag = $this->hashContent($file);
		$this->store($file, $etag);
		return $etag;
	}

	/**
	 * Recorded ETags that are still current, for many files in one go.
	 *
	 * Unlike get() this never reads content: a file without a current record
	 * is simply missing from the result, and the caller decides whether it is
	 * worth hashing.
	 *
	 * @param File[] $files
	 * @return array<int, string> file id => ETag
	 */
	public function cachedMany(array $files): array {
		$byId = [];
		foreach ($files as $file) {
			$byId[$file->getId()] = $file;
		}

		$current = [];
		foreach (array_chunk(array_keys($byId), 500) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('file_id', 'etag', 'file_size', 'file_mtime', 'nc_etag')
				->from('s3api_etags')
				->where($qb->expr()->in('file_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));

			$result = $qb->executeQuery();
			while (($row = $result->fetchAssociative()) !== false) {
				$file = $byId[(int)$row['file_id']] ?? null;
				if ($file !== null && self::isCurrent(self::fromRow($row), $file)) {
					$current[$file->getId()] = (string)$row['etag'];
				}
			}
			$result->closeCursor();
		}

		return $current;
	}

	public function forget(int $fileId): void {
		$this->forgetMany([$fileId]);
	}

	/**
	 * @param int[] $fileIds
	 */
	public function forgetMany(array $fileIds): void {
		foreach (array_chunk(array_values(array_unique($fileIds)), 500) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete('s3api_etags')
				->where($qb->expr()->in('file_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));
			$qb->executeStatement();
		}
	}

	private function hashContent(File $file): string {
		$handle = $file->fopen('rb');
		if ($handle === false) {
			// Without the bytes there is nothing better than Nextcloud's etag.
			return $file->getEtag();
		}

		try {
			$ctx = hash_init('md5');
			hash_update_stream($ctx, $handle);
			return hash_final($ctx);
		} finally {
			fclose($handle);
		}
	}

	/**
	 * @return array{etag: string, file_size: int, file_mtime: int, nc_etag: ?string}|null
	 */
	private function lookup(int $fileId): ?array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('etag', 'file_size', 'file_mtime', 'nc_etag')
			->from('s3api_etags')
			->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)));

		$result = $qb->executeQuery();
		$row = $result->fetchAssociative();
		$result->closeCursor();

		return $row === false ? null : self::fromRow($row);
	}

	/**
	 * @return array{etag: string, file_size: int, file_mtime: int, nc_etag: ?string}
	 */
	private static function fromRow(array $row): array {
		return [
			'etag' => (string)$row['etag'],
			'file_size' => (int)$row['file_size'],
			'file_mtime' => (int)$row['file_mtime'],
			'nc_etag' => $row['nc_etag'] === null ? null : (string)$row['nc_etag'],
		];
	}

	private function store(File $file, string $etag): void {
		$fileId = $file->getId();
		$size = $file->getSize();
		$mtime = $file->getMTime();
		$ncEtag = $file->getEtag();

		$qb = $this->db->getQueryBuilder();
		$qb->update('s3api_etags')
			->set('etag', $qb->createNamedParameter($etag))
			->set('file_size', $qb->createNamedParameter($size, IQueryBuilder::PARAM_INT))
			->set('file_mtime', $qb->createNamedParameter($mtime, IQueryBuilder::PARAM_INT))
			->set('nc_etag', $qb->createNamedParameter($ncEtag))
			->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)));

		if ($qb->executeStatement() > 0) {
			return;
		}

		$insert = $this->db->getQueryBuilder();
		$insert->insert('s3api_etags')
			->values([
				'file_id' => $insert->createNamedParameter($fileId, IQueryBuilder::PARAM_INT),
				'etag' => $insert->createNamedParameter($etag),
				'file_size' => $insert->createNamedParameter($size, IQueryBuilder::PARAM_INT),
				'file_mtime' => $insert->createNamedParameter($mtime, IQueryBuilder::PARAM_INT),
				'nc_etag' => $insert->createNamedParameter($ncEtag),
			]);

		try {
			$insert->executeStatement();
		} catch (DbException $e) {
			// Another request inserted the same row first; its value is as good
			// as ours only if it wrote the same content, so overwrite it. On
			// MariaDB that race can also end as a deadlock, which rolled the
			// insert back just the same.
			if ($e->getReason() !== DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION
				&& $e->getReason() !== DbException::REASON_DEADLOCK) {
				throw $e;
			}

			$retry = $this->db->getQueryBuilder();
			$retry->update('s3api_etags')
				->set('etag', $retry->createNamedParameter($etag))
				->set('file_size', $retry->createNamedParameter($size, IQueryBuilder::PARAM_INT))
				->set('file_mtime', $retry->createNamedParameter($mtime, IQueryBuilder::PARAM_INT))
				->set('nc_etag', $retry->createNamedParameter($ncEtag))
				->where($retry->expr()->eq('file_id', $retry->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)));
			$retry->executeStatement();
		}
	}
}
