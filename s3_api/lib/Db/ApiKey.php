<?php

declare(strict_types=1);

namespace OCA\S3Api\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method int getBucketId()
 * @method void setBucketId(int $bucketId)
 * @method string getAccessKey()
 * @method void setAccessKey(string $accessKey)
 * @method string getSecretKeyEnc()
 * @method void setSecretKeyEnc(string $secretKeyEnc)
 * @method string getPermission()
 * @method void setPermission(string $permission)
 * @method string|null getLabel()
 * @method void setLabel(?string $label)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $createdAt)
 */
class ApiKey extends Entity {
	protected $userId = '';
	protected $bucketId = 0;
	protected $accessKey = '';
	protected $secretKeyEnc = '';
	protected $permission = '';
	protected $label = null;
	protected $createdAt = null;

	public function __construct() {
		$this->addType('id', 'integer');
		$this->addType('userId', 'string');
		$this->addType('bucketId', 'integer');
		$this->addType('accessKey', 'string');
		$this->addType('secretKeyEnc', 'string');
		$this->addType('permission', 'string');
		$this->addType('label', 'string');
		$this->addType('createdAt', 'datetime');
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'bucketId' => $this->bucketId,
			'accessKey' => $this->accessKey,
			'permission' => $this->permission,
			'label' => $this->label,
			'createdAt' => $this->createdAt?->format('c'),
		];
	}
}
