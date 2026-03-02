<?php

declare(strict_types=1);

namespace OCA\S3Api\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method string getFolderPath()
 * @method void setFolderPath(string $folderPath)
 * @method string getBucketName()
 * @method void setBucketName(string $bucketName)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $createdAt)
 */
class Bucket extends Entity {
	protected $userId = '';
	protected $folderPath = '';
	protected $bucketName = '';
	protected $createdAt = null;

	public function __construct() {
		$this->addType('id', 'integer');
		$this->addType('userId', 'string');
		$this->addType('folderPath', 'string');
		$this->addType('bucketName', 'string');
		$this->addType('createdAt', 'datetime');
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'userId' => $this->userId,
			'folderPath' => $this->folderPath,
			'bucketName' => $this->bucketName,
			'createdAt' => $this->createdAt?->format('c'),
		];
	}
}
