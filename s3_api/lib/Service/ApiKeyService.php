<?php

declare(strict_types=1);

namespace OCA\S3Api\Service;

use DateTime;
use OCA\S3Api\Db\ApiKey;
use OCA\S3Api\Db\ApiKeyMapper;
use OCA\S3Api\Db\BucketMapper;
use OCP\IConfig;
use OCP\Security\ICrypto;

class ApiKeyService {

	public function __construct(
		private ApiKeyMapper $apiKeyMapper,
		private BucketMapper $bucketMapper,
		private ICrypto $crypto,
	) {
	}

	/**
	 * @return ApiKey[]
	 */
	public function listKeys(int $bucketId): array {
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

	public function deleteKey(int $keyId, string $userId): void {
		$key = $this->apiKeyMapper->findByIdAndUser($keyId, $userId);
		$this->apiKeyMapper->delete($key);
	}

	public function findByAccessKey(string $accessKey): ?ApiKey {
		try {
			return $this->apiKeyMapper->findByAccessKey($accessKey);
		} catch (\OCP\AppFramework\Db\DoesNotExistException) {
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
