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
 */
class EtagService {

	public function __construct(
		private IDBConnection $db,
	) {
	}

	/**
	 * The ETag to report for a file, without surrounding quotes.
	 *
	 * Falls back to hashing the content when nothing is recorded yet -- the file
	 * may predate this app or have been written through the web UI, WebDAV or
	 * the sync client.
	 */
	public function get(File $file): string {
		$stored = $this->lookup($file->getId());

		if ($stored !== null
			&& $stored['file_size'] === $file->getSize()
			&& $stored['file_mtime'] === $file->getMTime()) {
			return $stored['etag'];
		}

		// Either unknown or changed behind our back: recompute from content.
		$etag = $this->hashContent($file);
		$this->store($file, $etag);
		return $etag;
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
			$qb->select('file_id', 'etag', 'file_size', 'file_mtime')
				->from('s3api_etags')
				->where($qb->expr()->in('file_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));

			$result = $qb->executeQuery();
			while (($row = $result->fetchAssociative()) !== false) {
				$file = $byId[(int)$row['file_id']] ?? null;
				if ($file !== null
					&& (int)$row['file_size'] === $file->getSize()
					&& (int)$row['file_mtime'] === $file->getMTime()) {
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
	 * @return array{etag: string, file_size: int, file_mtime: int}|null
	 */
	private function lookup(int $fileId): ?array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('etag', 'file_size', 'file_mtime')
			->from('s3api_etags')
			->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)));

		$result = $qb->executeQuery();
		$row = $result->fetchAssociative();
		$result->closeCursor();

		if ($row === false) {
			return null;
		}

		return [
			'etag' => (string)$row['etag'],
			'file_size' => (int)$row['file_size'],
			'file_mtime' => (int)$row['file_mtime'],
		];
	}

	private function store(File $file, string $etag): void {
		$fileId = $file->getId();
		$size = $file->getSize();
		$mtime = $file->getMTime();

		$qb = $this->db->getQueryBuilder();
		$qb->update('s3api_etags')
			->set('etag', $qb->createNamedParameter($etag))
			->set('file_size', $qb->createNamedParameter($size, IQueryBuilder::PARAM_INT))
			->set('file_mtime', $qb->createNamedParameter($mtime, IQueryBuilder::PARAM_INT))
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
			]);

		try {
			$insert->executeStatement();
		} catch (DbException $e) {
			// Another request inserted the same row first; its value is as good
			// as ours only if it wrote the same content, so overwrite it.
			if ($e->getReason() !== DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				throw $e;
			}

			$retry = $this->db->getQueryBuilder();
			$retry->update('s3api_etags')
				->set('etag', $retry->createNamedParameter($etag))
				->set('file_size', $retry->createNamedParameter($size, IQueryBuilder::PARAM_INT))
				->set('file_mtime', $retry->createNamedParameter($mtime, IQueryBuilder::PARAM_INT))
				->where($retry->expr()->eq('file_id', $retry->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)));
			$retry->executeStatement();
		}
	}
}
