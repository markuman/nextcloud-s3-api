<?php

declare(strict_types=1);

namespace OCA\S3Api\Controller;

use OCA\S3Api\Db\BucketMapper;
use OCA\S3Api\Service\BucketService;
use OCA\S3Api\Service\S3AuthException;
use OCA\S3Api\Service\S3AuthService;
use OCA\S3Api\Service\S3ResponseBuilder;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\StreamResponse;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IRequest;

class S3Controller extends Controller {

	public function __construct(
		string $appName,
		IRequest $request,
		private S3AuthService $authService,
		private BucketService $bucketService,
		private BucketMapper $bucketMapper,
		private S3ResponseBuilder $responseBuilder,
		private IRootFolder $rootFolder,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * @PublicPage
	 * @NoCSRFRequired
	 */
	public function listBucketsOrRoot(): Response {
		return $this->handleRequest('');
	}

	/**
	 * @PublicPage
	 * @NoCSRFRequired
	 */
	public function handleGet(string $path = ''): Response {
		return $this->handleRequest($path);
	}

	/**
	 * @PublicPage
	 * @NoCSRFRequired
	 */
	public function handlePut(string $path = ''): Response {
		return $this->handleRequest($path);
	}

	/**
	 * @PublicPage
	 * @NoCSRFRequired
	 */
	public function handleDelete(string $path = ''): Response {
		return $this->handleRequest($path);
	}

	/**
	 * @PublicPage
	 * @NoCSRFRequired
	 */
	public function handleHead(string $path = ''): Response {
		return $this->handleRequest($path);
	}

	private function handleRequest(string $path = ''): Response {
		try {
			$auth = $this->authService->authenticate($this->request);
		} catch (S3AuthException $e) {
			return $this->errorResponse($e->getS3Code(), $e->getMessage(), '/' . $path, Http::STATUS_FORBIDDEN);
		}

		$userId = $auth['userId'];
		$permission = $auth['permission'];
		$method = $this->request->getMethod();

		// Parse bucket and key from path
		if ($path === '') {
			// ListBuckets
			if ($method === 'GET') {
				return $this->listBuckets($userId);
			}
			return $this->errorResponse('MethodNotAllowed', 'Method not allowed', '/', Http::STATUS_METHOD_NOT_ALLOWED);
		}

		$slashPos = strpos($path, '/');
		if ($slashPos === false) {
			$bucketName = $path;
			$objectKey = '';
		} else {
			$bucketName = substr($path, 0, $slashPos);
			$objectKey = substr($path, $slashPos + 1);
		}

		// Verify bucket access
		$bucket = $this->bucketService->getBucketByName($userId, $bucketName);
		if ($bucket === null) {
			return $this->errorResponse('NoSuchBucket', 'The specified bucket does not exist', '/' . $bucketName, Http::STATUS_NOT_FOUND);
		}

		// Verify this key is authorized for this bucket
		if ($auth['bucketId'] !== $bucket->getId()) {
			// Check if ListBuckets was requested (path is bucket name)
			return $this->errorResponse('AccessDenied', 'Access Denied', '/' . $bucketName, Http::STATUS_FORBIDDEN);
		}

		$userFolder = $this->rootFolder->getUserFolder($userId);
		try {
			$bucketFolder = $userFolder->get($bucket->getFolderPath());
		} catch (NotFoundException) {
			return $this->errorResponse('NoSuchBucket', 'Bucket folder not found', '/' . $bucketName, Http::STATUS_NOT_FOUND);
		}

		if (!($bucketFolder instanceof Folder)) {
			return $this->errorResponse('NoSuchBucket', 'Bucket path is not a folder', '/' . $bucketName, Http::STATUS_NOT_FOUND);
		}

		if ($objectKey === '') {
			// Bucket-level operations
			if ($method === 'GET') {
				return $this->listObjects($userId, $bucket, $bucketFolder);
			}
			return $this->errorResponse('MethodNotAllowed', 'Method not allowed', '/' . $bucketName, Http::STATUS_METHOD_NOT_ALLOWED);
		}

		// Object-level operations
		return match ($method) {
			'GET' => $this->getObject($bucketFolder, $objectKey, $bucketName),
			'HEAD' => $this->headObject($bucketFolder, $objectKey, $bucketName),
			'PUT' => $this->putObject($bucketFolder, $objectKey, $bucketName, $permission),
			'DELETE' => $this->deleteObject($bucketFolder, $objectKey, $bucketName, $permission),
			default => $this->errorResponse('MethodNotAllowed', 'Method not allowed', '/' . $bucketName . '/' . $objectKey, Http::STATUS_METHOD_NOT_ALLOWED),
		};
	}

	private function listBuckets(string $userId): Response {
		$buckets = $this->bucketService->listBuckets($userId);
		$xml = $this->responseBuilder->listBucketsXml($userId, $buckets);
		return $this->xmlResponse($xml);
	}

	private function listObjects(string $userId, $bucket, Folder $bucketFolder): Response {
		$prefix = $this->request->getParam('prefix', '');
		$delimiter = $this->request->getParam('delimiter', '');
		$maxKeys = (int)$this->request->getParam('max-keys', '1000');
		$listType = $this->request->getParam('list-type', '1');

		$nodes = $bucketFolder->getDirectoryListing();
		$basePath = $bucketFolder->getPath();

		if ($listType === '2') {
			$startAfter = $this->request->getParam('start-after', '');
			$continuationToken = $this->request->getParam('continuation-token', '');
			$xml = $this->responseBuilder->listObjectsV2Xml(
				$bucket->getBucketName(), $nodes, $prefix, $delimiter,
				$startAfter, $continuationToken, $maxKeys, $basePath,
			);
		} else {
			$marker = $this->request->getParam('marker', '');
			$xml = $this->responseBuilder->listObjectsXml(
				$bucket->getBucketName(), $nodes, $prefix, $delimiter,
				$marker, $maxKeys, $basePath,
			);
		}

		return $this->xmlResponse($xml);
	}

	private function getObject(Folder $bucketFolder, string $objectKey, string $bucketName): Response {
		try {
			$node = $bucketFolder->get($objectKey);
		} catch (NotFoundException) {
			return $this->errorResponse('NoSuchKey', 'The specified key does not exist', '/' . $bucketName . '/' . $objectKey, Http::STATUS_NOT_FOUND);
		}

		if (!($node instanceof File)) {
			return $this->errorResponse('NoSuchKey', 'The specified key is not a file', '/' . $bucketName . '/' . $objectKey, Http::STATUS_NOT_FOUND);
		}

		$response = new DataDisplayResponse($node->getContent());
		$response->addHeader('Content-Type', $node->getMimeType());
		$response->addHeader('Content-Length', (string)$node->getSize());
		$response->addHeader('ETag', '"' . $node->getEtag() . '"');
		$response->addHeader('Last-Modified', gmdate('D, d M Y H:i:s \G\M\T', $node->getMTime()));
		$response->addHeader('Accept-Ranges', 'bytes');
		return $response;
	}

	private function headObject(Folder $bucketFolder, string $objectKey, string $bucketName): Response {
		try {
			$node = $bucketFolder->get($objectKey);
		} catch (NotFoundException) {
			return $this->errorResponse('NoSuchKey', 'The specified key does not exist', '/' . $bucketName . '/' . $objectKey, Http::STATUS_NOT_FOUND);
		}

		if (!($node instanceof File)) {
			return $this->errorResponse('NoSuchKey', 'The specified key is not a file', '/' . $bucketName . '/' . $objectKey, Http::STATUS_NOT_FOUND);
		}

		$response = new DataDisplayResponse('');
		$response->addHeader('Content-Type', $node->getMimeType());
		$response->addHeader('Content-Length', (string)$node->getSize());
		$response->addHeader('ETag', '"' . $node->getEtag() . '"');
		$response->addHeader('Last-Modified', gmdate('D, d M Y H:i:s \G\M\T', $node->getMTime()));
		$response->addHeader('Accept-Ranges', 'bytes');
		return $response;
	}

	private function putObject(Folder $bucketFolder, string $objectKey, string $bucketName, string $permission): Response {
		if ($permission !== 'readwrite') {
			return $this->errorResponse('AccessDenied', 'Write access denied', '/' . $bucketName . '/' . $objectKey, Http::STATUS_FORBIDDEN);
		}

		$content = file_get_contents('php://input');

		// Create subdirectories if needed
		$dir = dirname($objectKey);
		$targetFolder = $bucketFolder;
		if ($dir !== '' && $dir !== '.') {
			$parts = explode('/', $dir);
			foreach ($parts as $part) {
				if ($part === '' || $part === '.') {
					continue;
				}
				try {
					$sub = $targetFolder->get($part);
					if (!($sub instanceof Folder)) {
						return $this->errorResponse('InvalidArgument', 'Path component is not a directory', '/' . $bucketName . '/' . $objectKey, Http::STATUS_CONFLICT);
					}
					$targetFolder = $sub;
				} catch (NotFoundException) {
					$targetFolder = $targetFolder->newFolder($part);
				}
			}
		}

		$fileName = basename($objectKey);
		try {
			$file = $targetFolder->get($fileName);
			if ($file instanceof File) {
				$file->putContent($content);
			} else {
				return $this->errorResponse('InvalidArgument', 'Target exists and is not a file', '/' . $bucketName . '/' . $objectKey, Http::STATUS_CONFLICT);
			}
		} catch (NotFoundException) {
			$file = $targetFolder->newFile($fileName, $content);
		}

		$response = new DataDisplayResponse('');
		$response->setStatus(Http::STATUS_OK);
		$response->addHeader('ETag', '"' . $file->getEtag() . '"');
		return $response;
	}

	private function deleteObject(Folder $bucketFolder, string $objectKey, string $bucketName, string $permission): Response {
		if ($permission !== 'readwrite') {
			return $this->errorResponse('AccessDenied', 'Write access denied', '/' . $bucketName . '/' . $objectKey, Http::STATUS_FORBIDDEN);
		}

		try {
			$node = $bucketFolder->get($objectKey);
			$node->delete();
		} catch (NotFoundException) {
			// S3 returns 204 even if object doesn't exist
		}

		$response = new DataDisplayResponse('');
		$response->setStatus(Http::STATUS_NO_CONTENT);
		return $response;
	}

	private function xmlResponse(string $xml, int $status = Http::STATUS_OK): Response {
		$response = new DataDisplayResponse($xml, $status);
		$response->addHeader('Content-Type', 'application/xml');
		return $response;
	}

	private function errorResponse(string $code, string $message, string $resource, int $httpStatus): Response {
		$xml = $this->responseBuilder->errorXml($code, $message, $resource);
		return $this->xmlResponse($xml, $httpStatus);
	}
}
