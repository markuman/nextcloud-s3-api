<?php

declare(strict_types=1);

namespace OCA\S3Api\Service;

use DateTime;
use OCA\S3Api\Db\ApiKey;
use OCA\S3Api\Db\ApiKeyMapper;
use OCA\S3Api\Db\BucketMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Security\ICrypto;

class ApiKeyService {

	public function __construct(
		private ApiKeyMapper $apiKeyMapper,
		private BucketMapper $bucketMapper,
		private ICrypto $crypto,
	) {
	}

	/**
	 * Keys of a bucket the user owns.
	 *
	 * The ownership check lives here rather than in the controller so a caller
	 * cannot list a bucket's keys by guessing its id: bucket ids are sequential,
	 * and the listing exposes access key ids, permissions and labels.
	 *
	 * @return ApiKey[]
	 * @throws DoesNotExistException when the bucket is not the user's
	 */
	public function listKeys(int $bucketId, string $userId): array {
		$this->bucketMapper->findByIdAndUser($bucketId, $userId);

		return $this->apiKeyMapper->findByBucketId($bucketId);
	}

	/**
	 * @return array{key: ApiKey, secretKey: string}
	 */
	public function createKey(string $userId, int $bucketId, string $permission, ?string $label = null): array {
		if (!in_array($permission, ['readonly', 'readwrite'], true)) {
			throw new \InvalidArgumentException('Permission must be "readonly" or "readwrite"');
		}

		// Verify bucket belongs to user
		$this->bucketMapper->findByIdAndUser($bucketId, $userId);

		$accessKey = $this->generateAccessKey();
		$secretKey = $this->generateSecretKey();

		$apiKey = new ApiKey();
		$apiKey->setUserId($userId);
		$apiKey->setBucketId($bucketId);
		$apiKey->setAccessKey($accessKey);
		$apiKey->setSecretKeyEnc($this->crypto->encrypt($secretKey));
		$apiKey->setPermission($permission);
		$apiKey->setLabel($label);
		$apiKey->setCreatedAt(new DateTime());

		$apiKey = $this->apiKeyMapper->insert($apiKey);

		return [
			'key' => $apiKey,
			'secretKey' => $secretKey,
		];
	}

	/**
	 * @param int $bucketId the bucket the key is expected to belong to
	 * @throws DoesNotExistException when the key is not the user's, or does not
	 *         belong to that bucket
	 */
	public function deleteKey(int $keyId, int $bucketId, string $userId): void {
		$key = $this->apiKeyMapper->findByIdAndUser($keyId, $userId);

		// The bucket is part of the route, so honour it instead of deleting a
		// key that happens to share an id but sits in another bucket.
		if ($key->getBucketId() !== $bucketId) {
			throw new DoesNotExistException('Key does not belong to this bucket');
		}

		$this->apiKeyMapper->delete($key);
	}

	public function findByAccessKey(string $accessKey): ?ApiKey {
		try {
			return $this->apiKeyMapper->findByAccessKey($accessKey);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	public function decryptSecretKey(ApiKey $apiKey): string {
		return $this->crypto->decrypt($apiKey->getSecretKeyEnc());
	}

	private function generateAccessKey(): string {
		$chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
		$key = '';
		for ($i = 0; $i < 20; $i++) {
			$key .= $chars[random_int(0, strlen($chars) - 1)];
		}
		return $key;
	}

	private function generateSecretKey(): string {
		return base64_encode(random_bytes(30));
	}
}
