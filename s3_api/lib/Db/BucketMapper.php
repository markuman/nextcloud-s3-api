<?php

declare(strict_types=1);

namespace OCA\S3Api\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * @extends QBMapper<Bucket>
 */
class BucketMapper extends QBMapper {

	public function __construct(IDBConnection $db) {
		parent::__construct($db, 's3api_buckets', Bucket::class);
	}

	/**
	 * @return Bucket[]
	 */
	public function findByUserId(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		return $this->findEntities($qb);
	}

	/**
	 * @throws DoesNotExistException
	 */
	public function findByUserAndName(string $userId, string $bucketName): Bucket {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('bucket_name', $qb->createNamedParameter($bucketName)));
		return $this->findEntity($qb);
	}

	/**
	 * Buckets of a user whose folder path is `$folderPath`, or is nested inside
	 * it, or contains it.
	 *
	 * Used to keep one file from being reachable through two buckets, which
	 * would give it two independent locks. Matching is done in PHP rather than
	 * with a `LIKE` prefix query: the paths are per user and few, and `LIKE`
	 * would need `_` and `%` escaped, which is easy to get subtly wrong.
	 *
	 * @return Bucket[]
	 */
	public function findOverlapping(string $userId, string $folderPath): array {
		$needle = $this->normalizePath($folderPath);

		return array_values(array_filter(
			$this->findByUserId($userId),
			function (Bucket $bucket) use ($needle): bool {
				return $this->pathsOverlap($this->normalizePath($bucket->getFolderPath()), $needle);
			},
		));
	}

	/**
	 * Whether two normalised paths name the same folder or one contains the
	 * other.
	 *
	 * Comparison is on whole segments — `/Documents` and `/Documents2` share a
	 * string prefix but are unrelated folders, so a plain str_starts_with on
	 * the bare paths would be wrong. Appending the separator to both sides is
	 * what makes the boundary explicit.
	 */
	private function pathsOverlap(string $a, string $b): bool {
		if ($a === $b) {
			return true;
		}

		// The home root contains every path, and normalises to "/" — for which
		// the trailing-separator trick degenerates into "//" and matches
		// nothing. Special-cased rather than papered over, because a bucket on
		// the whole home is a legitimate thing to have registered.
		if ($a === '/' || $b === '/') {
			return true;
		}

		return str_starts_with($a . '/', $b . '/')
			|| str_starts_with($b . '/', $a . '/');
	}

	/**
	 * A path in one comparable shape: leading slash, no trailing slash, no
	 * empty, `.` or `..` segments.
	 *
	 * `Documents`, `/Documents` and `/Documents/` all name the same folder, and
	 * the settings form accepts any of them. So do `/Documents//sub` and
	 * `/Documents/./sub` — Nextcloud's own path handling collapses those, which
	 * means such a spelling passes the folder-exists check. If it were compared
	 * here verbatim it would slip past the overlap check while still naming the
	 * same directory, so the same collapsing has to happen before comparing.
	 *
	 * `..` never gets here in practice: Nextcloud refuses a path containing
	 * it, so the folder-exists check fails first. It is resolved anyway, so
	 * the comparison does not depend on that; it cannot climb above the
	 * user's home because these paths are all relative to it.
	 */
	private function normalizePath(string $path): string {
		$segments = [];
		foreach (explode('/', str_replace('\\', '/', $path)) as $segment) {
			if ($segment === '' || $segment === '.') {
				continue;
			}
			if ($segment === '..') {
				array_pop($segments);
				continue;
			}
			$segments[] = $segment;
		}

		return '/' . implode('/', $segments);
	}

	/**
	 * @throws DoesNotExistException
	 */
	public function findByIdAndUser(int $id, string $userId): Bucket {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		return $this->findEntity($qb);
	}
}
