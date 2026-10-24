<?php

declare(strict_types=1);

namespace OCA\S3Api\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<Upload>
 */
class UploadMapper extends QBMapper {

	public function __construct(IDBConnection $db) {
		parent::__construct($db, 's3api_uploads', Upload::class);
	}

	/**
	 * @throws DoesNotExistException
	 */
	public function findByUploadId(string $uploadId, int $bucketId): Upload {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('upload_id', $qb->createNamedParameter($uploadId)))
			->andWhere($qb->expr()->eq('bucket_id', $qb->createNamedParameter($bucketId, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	/**
	 * One page of a bucket's uploads, ordered by key and then upload id.
	 *
	 * With `$keyMarker` alone the page starts after every upload of that key;
	 * with `$uploadIdMarker` as well, after that one upload of it -- the
	 * markers ListMultipartUploads pages with.
	 *
	 * @return Upload[]
	 */
	public function findByBucketPage(int $bucketId, string $prefix, string $keyMarker, string $uploadIdMarker, int $limit): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('bucket_id', $qb->createNamedParameter($bucketId, IQueryBuilder::PARAM_INT)));

		if ($prefix !== '') {
			$qb->andWhere($qb->expr()->like('object_key', $qb->createNamedParameter($this->db->escapeLikeParameter($prefix) . '%')));
		}

		if ($keyMarker !== '') {
			$after = $qb->expr()->gt('object_key', $qb->createNamedParameter($keyMarker));
			if ($uploadIdMarker !== '') {
				$after = $qb->expr()->orX(
					$after,
					$qb->expr()->andX(
						$qb->expr()->eq('object_key', $qb->createNamedParameter($keyMarker)),
						$qb->expr()->gt('upload_id', $qb->createNamedParameter($uploadIdMarker)),
					),
				);
			}
			$qb->andWhere($after);
		}

		$qb->orderBy('object_key', 'ASC')
			->addOrderBy('upload_id', 'ASC')
			->setMaxResults($limit);

		return $this->findEntities($qb);
	}

	public function countByBucket(int $bucketId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'n'))
			->from($this->getTableName())
			->where($qb->expr()->eq('bucket_id', $qb->createNamedParameter($bucketId, IQueryBuilder::PARAM_INT)));

		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();

		return $count;
	}

	/**
	 * One bucket's uploads started before `$before`, oldest first.
	 *
	 * @return Upload[]
	 */
	public function findOlderThanInBucket(int $bucketId, \DateTime $before, int $limit): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('bucket_id', $qb->createNamedParameter($bucketId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->lt('created_at', $qb->createNamedParameter($before, 'datetime')))
			->orderBy('created_at', 'ASC')
			->setMaxResults($limit);
		return $this->findEntities($qb);
	}

	/**
	 * Uploads started before `$before`, oldest first, for reaping abandoned
	 * ones a batch at a time.
	 *
	 * @return Upload[]
	 */
	public function findOlderThan(\DateTime $before, int $limit): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->lt('created_at', $qb->createNamedParameter($before, 'datetime')))
			->orderBy('created_at', 'ASC')
			->setMaxResults($limit);
		return $this->findEntities($qb);
	}
}
