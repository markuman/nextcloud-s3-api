<?php

declare(strict_types=1);

namespace OCA\S3Api\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * @extends QBMapper<ApiKey>
 */
class ApiKeyMapper extends QBMapper {

	public function __construct(IDBConnection $db) {
		parent::__construct($db, 's3api_keys', ApiKey::class);
	}

	/**
	 * @throws DoesNotExistException
	 */
	public function findByAccessKey(string $accessKey): ApiKey {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('access_key', $qb->createNamedParameter($accessKey)));
		return $this->findEntity($qb);
	}

	/**
	 * @return ApiKey[]
	 */
	public function findByBucketId(int $bucketId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('bucket_id', $qb->createNamedParameter($bucketId)));
		return $this->findEntities($qb);
	}

	/**
	 * @throws DoesNotExistException
	 */
	public function findByIdAndUser(int $id, string $userId): ApiKey {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		return $this->findEntity($qb);
	}

	public function deleteByBucketId(int $bucketId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('bucket_id', $qb->createNamedParameter($bucketId)));
		$qb->executeStatement();
	}
}
