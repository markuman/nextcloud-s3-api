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
		$this->validateNameIsFree($userId, $bucketName);
		$this->validateFolderNotAlreadyBucket($userId, $folderPath);

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

	/**
	 * The name has a unique index on (user_id, bucket_name), so the database
	 * would catch this anyway — with a driver exception that surfaced as a 500
	 * carrying raw SQL. Checking here turns the common mistake into a 409.
	 *
	 * This is not a substitute for the index: two simultaneous requests can
	 * both pass the check, and the insert is what actually decides. The
	 * controller maps that collision to the same 409.
	 *
	 * @throws BucketConflictException
	 */
	private function validateNameIsFree(string $userId, string $bucketName): void {
		if ($this->getBucketByName($userId, $bucketName) !== null) {
			throw new BucketConflictException('A bucket named "' . $bucketName . '" already exists');
		}
	}

	/**
	 * Refuse a folder that is already served by another bucket, and refuse one
	 * that nests with an existing bucket's folder.
	 *
	 * Two names for one file are not merely redundant: a conditional write
	 * takes its lock on the object's resolved path, and while that now makes
	 * the two names share a lock, the rest of the app still treats them as
	 * separate buckets — separate multipart scratch areas under the same
	 * directory, and two ETag rows racing over one file id. Overlapping
	 * buckets have no use case that a single bucket with a key prefix does not
	 * cover better.
	 *
	 * @throws BucketConflictException
	 */
	private function validateFolderNotAlreadyBucket(string $userId, string $folderPath): void {
		$overlapping = $this->bucketMapper->findOverlapping($userId, $folderPath);
		if ($overlapping === []) {
			return;
		}

		$other = $overlapping[0];
		throw new BucketConflictException(
			'Folder overlaps with bucket "' . $other->getBucketName()
			. '" (' . $other->getFolderPath() . '). Use one bucket and address the'
			. ' subfolder with a key prefix.',
		);
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
