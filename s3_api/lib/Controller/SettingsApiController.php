<?php

declare(strict_types=1);

namespace OCA\S3Api\Controller;

use OCA\S3Api\Service\ApiKeyService;
use OCA\S3Api\Service\BucketService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

class SettingsApiController extends Controller {

	public function __construct(
		string $appName,
		IRequest $request,
		private BucketService $bucketService,
		private ApiKeyService $apiKeyService,
		private ?string $userId,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function listBuckets(): JSONResponse {
		$buckets = $this->bucketService->listBuckets($this->userId);
		return new JSONResponse(array_map(fn($b) => $b->jsonSerialize(), $buckets));
	}

	#[NoAdminRequired]
	public function createBucket(): JSONResponse {
		$folderPath = $this->request->getParam('folderPath', '');
		$bucketName = $this->request->getParam('bucketName', '');

		if (empty($folderPath) || empty($bucketName)) {
			return new JSONResponse(['error' => 'folderPath and bucketName are required'], Http::STATUS_BAD_REQUEST);
		}

		try {
			$bucket = $this->bucketService->createBucket($this->userId, $folderPath, $bucketName);
			return new JSONResponse($bucket->jsonSerialize(), Http::STATUS_CREATED);
		} catch (\InvalidArgumentException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (\Exception $e) {
			return new JSONResponse(['error' => 'Failed to create bucket: ' . $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	#[NoAdminRequired]
	public function deleteBucket(int $id): JSONResponse {
		try {
			$this->bucketService->deleteBucket($id, $this->userId);
			return new JSONResponse(['status' => 'ok']);
		} catch (DoesNotExistException) {
			return new JSONResponse(['error' => 'Bucket not found'], Http::STATUS_NOT_FOUND);
		}
	}

	#[NoAdminRequired]
	public function listKeys(int $bucketId): JSONResponse {
		try {
			$keys = $this->apiKeyService->listKeys($bucketId);
			return new JSONResponse(array_map(fn($k) => $k->jsonSerialize(), $keys));
		} catch (\Exception $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	#[NoAdminRequired]
	public function createKey(int $bucketId): JSONResponse {
		$permission = $this->request->getParam('permission', 'readonly');
		$label = $this->request->getParam('label', '');

		try {
			$result = $this->apiKeyService->createKey(
				$this->userId,
				$bucketId,
				$permission,
				$label ?: null,
			);

			$data = $result['key']->jsonSerialize();
			$data['secretKey'] = $result['secretKey'];

			return new JSONResponse($data, Http::STATUS_CREATED);
		} catch (\InvalidArgumentException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (DoesNotExistException) {
			return new JSONResponse(['error' => 'Bucket not found'], Http::STATUS_NOT_FOUND);
		}
	}

	#[NoAdminRequired]
	public function deleteKey(int $bucketId, int $keyId): JSONResponse {
		try {
			$this->apiKeyService->deleteKey($keyId, $this->userId);
			return new JSONResponse(['status' => 'ok']);
		} catch (DoesNotExistException) {
			return new JSONResponse(['error' => 'Key not found'], Http::STATUS_NOT_FOUND);
		}
	}
}
