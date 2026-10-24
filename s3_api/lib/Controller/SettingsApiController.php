<?php

declare(strict_types=1);

namespace OCA\S3Api\Controller;

use OCA\S3Api\Service\ApiKeyService;
use OCA\S3Api\Service\BucketConflictException;
use OCA\S3Api\Service\BucketService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\DB\Exception as DbException;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IRequest;
use OCP\Log\Audit\CriticalActionPerformedEvent;
use Psr\Log\LoggerInterface;

class SettingsApiController extends Controller {

	public function __construct(
		string $appName,
		IRequest $request,
		private BucketService $bucketService,
		private ApiKeyService $apiKeyService,
		private IEventDispatcher $eventDispatcher,
		private LoggerInterface $logger,
		private ?string $userId,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Record a change to buckets or keys: in nextcloud.log at info level, and
	 * in admin_audit's audit.log when that app is enabled.
	 *
	 * @param array<string, string> $parameters filled into `$message` in order
	 */
	private function audit(string $message, array $parameters): void {
		$this->logger->info(vsprintf($message, array_values($parameters)), [
			'app' => 's3_api',
			'userId' => $this->userId,
		] + $parameters);
		$this->eventDispatcher->dispatchTyped(new CriticalActionPerformedEvent($message, $parameters));
	}

	#[NoAdminRequired]
	public function listBuckets(): JSONResponse {
		$buckets = $this->bucketService->listBucketsWithCurrentPaths($this->userId);
		return new JSONResponse(array_map(fn($b) => $b->jsonSerialize(), $buckets));
	}

	#[NoAdminRequired]
	public function createBucket(): JSONResponse {
		$folderPath = $this->request->getParam('folderPath', '');
		$bucketName = $this->request->getParam('bucketName', '');

		// JSON can carry any type here; the service takes strings, and a
		// TypeError would end as a 500.
		if (!is_string($folderPath) || !is_string($bucketName) || $folderPath === '' || $bucketName === '') {
			return new JSONResponse(['error' => 'folderPath and bucketName are required'], Http::STATUS_BAD_REQUEST);
		}

		try {
			$bucket = $this->bucketService->createBucket($this->userId, $folderPath, $bucketName);
			$this->audit('S3 bucket "%s" created on folder "%s" by user "%s"', [
				'bucket' => $bucket->getBucketName(),
				'folder' => $bucket->getFolderPath(),
				'user' => (string)$this->userId,
			]);
			return new JSONResponse($bucket->jsonSerialize(), Http::STATUS_CREATED);
		} catch (\InvalidArgumentException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (BucketConflictException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_CONFLICT);
		} catch (DbException $e) {
			// The service checks for a duplicate name first, so reaching the
			// index means two requests raced. Same conflict, same answer — and
			// notably not a 500 with the driver's message in it, which is what
			// this used to do for the far more common non-racing case.
			if ($e->getReason() === DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				return new JSONResponse(
					['error' => 'A bucket named "' . $bucketName . '" already exists'],
					Http::STATUS_CONFLICT,
				);
			}

			$this->logger->error('Failed to create S3 bucket', ['app' => 's3_api', 'exception' => $e]);
			return new JSONResponse(['error' => 'Failed to create bucket'], Http::STATUS_INTERNAL_SERVER_ERROR);
		} catch (\Exception $e) {
			// Nothing here should reach the client: an unexpected failure could
			// carry a path or a driver detail in its message.
			$this->logger->error('Failed to create S3 bucket', ['app' => 's3_api', 'exception' => $e]);
			return new JSONResponse(['error' => 'Failed to create bucket'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	#[NoAdminRequired]
	public function deleteBucket(int $id): JSONResponse {
		try {
			$bucket = $this->bucketService->deleteBucket($id, $this->userId);
			$this->audit('S3 bucket "%s" and its keys deleted by user "%s"', [
				'bucket' => $bucket->getBucketName(),
				'user' => (string)$this->userId,
			]);
			return new JSONResponse(['status' => 'ok']);
		} catch (DoesNotExistException) {
			return new JSONResponse(['error' => 'Bucket not found'], Http::STATUS_NOT_FOUND);
		}
	}

	#[NoAdminRequired]
	public function listKeys(int $bucketId): JSONResponse {
		try {
			$keys = $this->apiKeyService->listKeys($bucketId, $this->userId);
			return new JSONResponse(array_map(fn($k) => $k->jsonSerialize(), $keys));
		} catch (DoesNotExistException) {
			// Same answer whether the bucket belongs to someone else or does not
			// exist, so the response cannot be used to probe for bucket ids.
			return new JSONResponse(['error' => 'Bucket not found'], Http::STATUS_NOT_FOUND);
		}
	}

	#[NoAdminRequired]
	public function createKey(int $bucketId): JSONResponse {
		$permission = $this->request->getParam('permission', 'readonly');
		$label = $this->request->getParam('label', '') ?? '';

		if (!is_string($permission) || !is_string($label)) {
			return new JSONResponse(['error' => 'permission and label must be strings'], Http::STATUS_BAD_REQUEST);
		}
		// The column holds 255 characters; longer used to be a database error.
		if (mb_strlen($label) > 255) {
			return new JSONResponse(['error' => 'Label must be at most 255 characters'], Http::STATUS_BAD_REQUEST);
		}

		try {
			$result = $this->apiKeyService->createKey(
				$this->userId,
				$bucketId,
				$permission,
				$label !== '' ? $label : null,
			);

			$data = $result['key']->jsonSerialize();
			$data['secretKey'] = $result['secretKey'];

			$this->audit('S3 access key "%s" (%s) created for bucket %s by user "%s"', [
				'accessKey' => $result['key']->getAccessKey(),
				'permission' => $result['key']->getPermission(),
				'bucketId' => (string)$bucketId,
				'user' => (string)$this->userId,
			]);

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
			$key = $this->apiKeyService->deleteKey($keyId, $bucketId, $this->userId);
			$this->audit('S3 access key "%s" of bucket %s deleted by user "%s"', [
				'accessKey' => $key->getAccessKey(),
				'bucketId' => (string)$bucketId,
				'user' => (string)$this->userId,
			]);
			return new JSONResponse(['status' => 'ok']);
		} catch (DoesNotExistException) {
			return new JSONResponse(['error' => 'Key not found'], Http::STATUS_NOT_FOUND);
		}
	}
}
