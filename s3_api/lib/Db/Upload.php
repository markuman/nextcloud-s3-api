<?php

declare(strict_types=1);

namespace OCA\S3Api\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method string getUploadId()
 * @method void setUploadId(string $uploadId)
 * @method int getBucketId()
 * @method void setBucketId(int $bucketId)
 * @method string getObjectKey()
 * @method void setObjectKey(string $objectKey)
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $createdAt)
 */
class Upload extends Entity {
	protected $uploadId = '';
	protected $bucketId = 0;
	protected $objectKey = '';
	protected $userId = '';
	protected $createdAt = null;

	public function __construct() {
		$this->addType('id', 'integer');
		$this->addType('uploadId', 'string');
		$this->addType('bucketId', 'integer');
		$this->addType('objectKey', 'string');
		$this->addType('userId', 'string');
		$this->addType('createdAt', 'datetime');
	}
}
