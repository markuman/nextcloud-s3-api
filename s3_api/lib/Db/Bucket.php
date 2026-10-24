<?php

declare(strict_types=1);

namespace OCA\S3Api\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method string getFolderPath()
 * @method void setFolderPath(string $folderPath)
 * @method int|null getFolderId()
 * @method void setFolderId(?int $folderId)
 * @method string getBucketName()
 * @method void setBucketName(string $bucketName)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $createdAt)
 */
class Bucket extends Entity {
	protected $userId = '';
	/** Where the folder was last seen, relative to the user's home. For display. */
	protected $folderPath = '';
	/**
	 * The folder's file id: what the bucket actually is. A path names
	 * whatever sits there at the moment, which after a rename can be another
	 * folder altogether, a share from someone else included. Null only for a
	 * bucket from before 1.6.2 whose folder could not be found while
	 * migrating.
	 */
	protected $folderId = null;
	protected $bucketName = '';
	protected $createdAt = null;

	public function __construct() {
		$this->addType('id', 'integer');
		$this->addType('userId', 'string');
		$this->addType('folderPath', 'string');
		$this->addType('folderId', 'integer');
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
