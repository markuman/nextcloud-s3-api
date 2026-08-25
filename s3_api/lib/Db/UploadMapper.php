<?php

declare(strict_types=1);

namespace OCA\S3Api\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
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
			->andWhere($qb->expr()->eq('bucket_id', $qb->createNamedParameter($bucketId, \PDO::PARAM_INT)));
		return $this->findEntity($qb);
	}

	/**
	 * @return Upload[]
	 */
	public function findByBucket(int $bucketId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('bucket_id', $qb->createNamedParameter($bucketId, \PDO::PARAM_INT)))
			->orderBy('object_key', 'ASC');
		return $this->findEntities($qb);
	}

	/**
	 * Uploads started before `$before`, for reaping abandoned ones.
	 *
	 * @return Upload[]
	 */
	public function findOlderThan(\DateTime $before): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->lt('created_at', $qb->createNamedParameter($before, 'datetime')));
		return $this->findEntities($qb);
	}
}
