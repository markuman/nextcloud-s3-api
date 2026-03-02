<?php

declare(strict_types=1);

namespace OCA\S3Api\Service;

use DateTime;
use OCA\S3Api\Db\Bucket;
use OCA\S3Api\Db\BucketMapper;
use OCA\S3Api\Db\ApiKeyMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Files\IRootFolder;

class BucketService {

	public function __construct(
		private BucketMapper $bucketMapper,
		private ApiKeyMapper $apiKeyMapper,
		private IRootFolder $rootFolder,
	) {
	}

	/**
	 * @return Bucket[]
	 */
	public function listBuckets(string $userId): array {
		return $this->bucketMapper->findByUserId($userId);
	}

	public function getBucketByName(string $userId, string $bucketName): ?Bucket {
		try {
			return $this->bucketMapper->findByUserAndName($userId, $bucketName);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	public function createBucket(string $userId, string $folderPath, string $bucketName): Bucket {
		$this->validateBucketName($bucketName);
		$this->validateFolderExists($userId, $folderPath);

		$bucket = new Bucket();
		$bucket->setUserId($userId);
		$bucket->setFolderPath($folderPath);
		$bucket->setBucketName($bucketName);
		$bucket->setCreatedAt(new DateTime());

		return $this->bucketMapper->insert($bucket);
	}

	public function deleteBucket(int $id, string $userId): void {
		$bucket = $this->bucketMapper->findByIdAndUser($id, $userId);
		$this->apiKeyMapper->deleteByBucketId($bucket->getId());
		$this->bucketMapper->delete($bucket);
	}

	private function validateBucketName(string $name): void {
		if (strlen($name) < 3 || strlen($name) > 63) {
			throw new \InvalidArgumentException('Bucket name must be between 3 and 63 characters');
		}
		if (!preg_match('/^[a-z0-9][a-z0-9.\-]*[a-z0-9]$/', $name)) {
			throw new \InvalidArgumentException('Bucket name must be DNS-compatible (lowercase alphanumeric, dots, hyphens)');
		}
		if (preg_match('/\.\./', $name)) {
			throw new \InvalidArgumentException('Bucket name must not contain consecutive dots');
		}
	}

	private function validateFolderExists(string $userId, string $folderPath): void {
		$userFolder = $this->rootFolder->getUserFolder($userId);
		if (!$userFolder->nodeExists($folderPath)) {
			throw new \InvalidArgumentException('Folder does not exist: ' . $folderPath);
		}
		$node = $userFolder->get($folderPath);
		if ($node->getType() !== \OCP\Files\FileInfo::TYPE_FOLDER) {
			throw new \InvalidArgumentException('Path is not a folder: ' . $folderPath);
		}
	}
}
