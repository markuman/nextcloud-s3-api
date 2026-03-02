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
