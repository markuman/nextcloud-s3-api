<?php

declare(strict_types=1);

namespace OCA\S3Api\Controller;

use OCA\S3Api\Db\BucketMapper;
use OCA\S3Api\Service\BucketService;
use OCA\S3Api\Service\S3AuthException;
use OCA\S3Api\Service\S3AuthService;
use OCA\S3Api\Service\S3RequestBody;
use OCA\S3Api\Service\S3ResponseBuilder;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
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
		private S3RequestBody $requestBody,
		private IRootFolder $rootFolder,
	) {
		parent::__construct($appName, $request);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	public function listBucketsOrRoot(): Response {
		return $this->handleRequest('');
	}

	#[PublicPage]
	#[NoCSRFRequired]
	public function handleGet(string $path = ''): Response {
		return $this->handleRequest($path);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	public function handlePut(string $path = ''): Response {
		return $this->handleRequest($path);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	public function handleDelete(string $path = ''): Response {
		return $this->handleRequest($path);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	public function handleHead(string $path = ''): Response {
		return $this->handleRequest($path);
	}

	private function handleRequest(string $path = ''): Response {
		try {
			$auth = $this->authService->authenticate($this->request);
		} catch (S3AuthException $e) {
			return $this->errorResponse($e->getS3Code(), $e->getMessage(), '/' . $path, $e->getHttpStatus());
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

		$resource = '/' . $bucketName . '/' . $objectKey;

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
						return $this->errorResponse('InvalidArgument', 'Path component is not a directory', $resource, Http::STATUS_CONFLICT);
					}
					$targetFolder = $sub;
				} catch (NotFoundException) {
					$targetFolder = $targetFolder->newFolder($part);
				}
			}
		}

		$fileName = basename($objectKey);

		$existing = null;
		try {
			$existing = $targetFolder->get($fileName);
			if (!($existing instanceof File)) {
				return $this->errorResponse('InvalidArgument', 'Target exists and is not a file', $resource, Http::STATUS_CONFLICT);
			}
		} catch (NotFoundException) {
			// New object.
		}

		// Conditional write (the compare-and-swap S3 clients rely on) must be
		// evaluated against the state we are about to overwrite.
		$precondition = $this->checkWritePreconditions($existing, $resource);
		if ($precondition !== null) {
			return $precondition;
		}

		// Decode the body before writing: AWS SDKs frame streaming uploads with
		// `aws-chunked`, and storing that framing verbatim corrupts the object.
		$sha256 = null;
		try {
			$stream = $this->requestBody->toStream($this->request, $sha256);
		} catch (S3AuthException $e) {
			return $this->errorResponse($e->getS3Code(), $e->getMessage(), $resource, $e->getHttpStatus());
		}

		// Verify the payload hash when the client committed to a literal one.
		$declaredHash = strtolower(trim((string)$this->request->getHeader('x-amz-content-sha256')));
		if ($declaredHash !== '' && ctype_xdigit($declaredHash) && strlen($declaredHash) === 64
			&& !hash_equals($declaredHash, (string)$sha256)) {
			fclose($stream);
			return $this->errorResponse(
				'XAmzContentSHA256Mismatch',
				'The provided x-amz-content-sha256 does not match what was computed',
				$resource,
				Http::STATUS_BAD_REQUEST,
			);
		}

		try {
			if ($existing instanceof File) {
				$file = $existing;
				$target = $file->fopen('wb');
				if ($target === false) {
					return $this->errorResponse('InternalError', 'Cannot open object for writing', $resource, Http::STATUS_INTERNAL_SERVER_ERROR);
				}
				stream_copy_to_stream($stream, $target);
				fclose($target);
			} else {
				$file = $targetFolder->newFile($fileName);
				$target = $file->fopen('wb');
				if ($target === false) {
					return $this->errorResponse('InternalError', 'Cannot open object for writing', $resource, Http::STATUS_INTERNAL_SERVER_ERROR);
				}
				stream_copy_to_stream($stream, $target);
				fclose($target);
			}
		} finally {
			fclose($stream);
		}

		// Re-read metadata so the ETag reflects what we just stored.
		$file = $targetFolder->get($fileName);

		$response = new DataDisplayResponse('');
		$response->setStatus(Http::STATUS_OK);
		$response->addHeader('ETag', '"' . $file->getEtag() . '"');
		return $response;
	}

	/**
	 * Evaluate `If-None-Match` / `If-Match` on a write.
	 *
	 * This is what makes the store usable for compare-and-swap: `If-None-Match: *`
	 * means "create only", `If-Match: <etag>` means "replace exactly this
	 * version". Returning 200 regardless would make clients believe a lost
	 * update succeeded.
	 *
	 * @return Response|null a 412 response, or null when the write may proceed
	 */
	private function checkWritePreconditions(?File $existing, string $resource): ?Response {
		$ifNoneMatch = trim((string)$this->request->getHeader('If-None-Match'));
		$ifMatch = trim((string)$this->request->getHeader('If-Match'));

		if ($ifMatch !== '') {
			if ($existing === null) {
				return $this->errorResponse('PreconditionFailed', 'At least one of the pre-conditions you specified did not hold', $resource, Http::STATUS_PRECONDITION_FAILED);
			}
			if (!$this->etagMatches($ifMatch, $existing->getEtag())) {
				return $this->errorResponse('PreconditionFailed', 'At least one of the pre-conditions you specified did not hold', $resource, Http::STATUS_PRECONDITION_FAILED);
			}
		}

		if ($ifNoneMatch !== '') {
			if ($ifNoneMatch === '*') {
				if ($existing !== null) {
					return $this->errorResponse('PreconditionFailed', 'At least one of the pre-conditions you specified did not hold', $resource, Http::STATUS_PRECONDITION_FAILED);
				}
			} elseif ($existing !== null && $this->etagMatches($ifNoneMatch, $existing->getEtag())) {
				return $this->errorResponse('PreconditionFailed', 'At least one of the pre-conditions you specified did not hold', $resource, Http::STATUS_PRECONDITION_FAILED);
			}
		}

		return null;
	}

	/**
	 * Whether an `If-Match` / `If-None-Match` header value matches an ETag.
	 *
	 * Accepts `*`, comma-separated lists, quoted and unquoted forms, and the
	 * `W/` weak prefix.
	 */
	private function etagMatches(string $header, string $etag): bool {
		if (trim($header) === '*') {
			return true;
		}

		$etag = trim($etag, '"');
		foreach (explode(',', $header) as $candidate) {
			$candidate = trim($candidate);
			if (str_starts_with($candidate, 'W/')) {
				$candidate = substr($candidate, 2);
			}
			$candidate = trim($candidate, '"');
			if ($candidate !== '' && hash_equals($etag, $candidate)) {
				return true;
			}
		}

		return false;
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
