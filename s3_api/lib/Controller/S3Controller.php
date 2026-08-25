<?php

declare(strict_types=1);

namespace OCA\S3Api\Controller;

use OCA\S3Api\Db\BucketMapper;
use OCA\S3Api\Http\S3ObjectResponse;
use OCA\S3Api\Service\BucketService;
use OCA\S3Api\Service\MultipartService;
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
use OCP\Lock\LockedException;

class S3Controller extends Controller {

	public function __construct(
		string $appName,
		IRequest $request,
		private S3AuthService $authService,
		private BucketService $bucketService,
		private BucketMapper $bucketMapper,
		private S3ResponseBuilder $responseBuilder,
		private S3RequestBody $requestBody,
		private MultipartService $multipartService,
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
	public function handlePost(string $path = ''): Response {
		return $this->handleRequest($path);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	public function handleHead(string $path = ''): Response {
		return $this->handleRequest($path);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	public function handleHeadRoot(): Response {
		return $this->handleRequest('');
	}

	private function handleRequest(string $path = ''): Response {
		$response = $this->dispatch($path);

		// A response to HEAD must not carry a body. Error paths build XML
		// bodies, so strip them centrally instead of relying on every caller.
		if ($this->request->getMethod() === 'HEAD' && $response instanceof DataDisplayResponse) {
			$stripped = new DataDisplayResponse('', $response->getStatus());
			foreach ($response->getHeaders() as $name => $value) {
				if (strtolower($name) === 'content-length') {
					continue;
				}
				$stripped->addHeader($name, $value);
			}
			return $stripped;
		}

		return $response;
	}

	private function dispatch(string $path = ''): Response {
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

		$query = $this->queryParams();

		if ($objectKey === '') {
			return $this->dispatchBucket($method, $query, $userId, $bucket, $bucketFolder, $bucketName, $permission);
		}

		return $this->dispatchObject($method, $query, $bucket, $bucketFolder, $bucketName, $objectKey, $permission);
	}

	/**
	 * Bucket-level requests.
	 *
	 * @param array<string, string> $query
	 */
	private function dispatchBucket(
		string $method,
		array $query,
		string $userId,
		$bucket,
		Folder $bucketFolder,
		string $bucketName,
		string $permission,
	): Response {
		$resource = '/' . $bucketName;

		// Subresources have to be recognised explicitly. Falling through to the
		// default handler would answer a request for, say, ?versioning with an
		// object listing, which clients then misparse.
		foreach (['uploads', 'delete', 'location', 'versioning'] as $known) {
			if (!array_key_exists($known, $query)) {
				continue;
			}

			return match (true) {
				$known === 'uploads' && $method === 'GET' => $this->listMultipartUploads($bucket, $bucketName),
				$known === 'delete' && $method === 'POST' => $this->deleteObjects($bucketFolder, $bucketName, $permission),
				$known === 'location' && $method === 'GET' => $this->xmlResponse($this->responseBuilder->locationXml()),
				$known === 'versioning' && $method === 'GET' => $this->xmlResponse($this->responseBuilder->versioningXml()),
				default => $this->errorResponse('MethodNotAllowed', 'Method not allowed', $resource, Http::STATUS_METHOD_NOT_ALLOWED),
			};
		}

		$unsupported = $this->unsupportedSubresource($query, $resource);
		if ($unsupported !== null) {
			return $unsupported;
		}

		return match ($method) {
			'GET' => $this->listObjects($userId, $bucket, $bucketFolder),
			// HeadBucket: existence and access were already established above.
			'HEAD' => new DataDisplayResponse('', Http::STATUS_OK),
			default => $this->errorResponse('MethodNotAllowed', 'Method not allowed', $resource, Http::STATUS_METHOD_NOT_ALLOWED),
		};
	}

	/**
	 * Object-level requests.
	 *
	 * @param array<string, string> $query
	 */
	private function dispatchObject(
		string $method,
		array $query,
		$bucket,
		Folder $bucketFolder,
		string $bucketName,
		string $objectKey,
		string $permission,
	): Response {
		$resource = '/' . $bucketName . '/' . $objectKey;

		// Multipart upload subresources. Without this, `PUT ?partNumber=1` fell
		// through to PutObject and stored a single part as the whole object,
		// reporting success.
		if (array_key_exists('uploads', $query) && $method === 'POST') {
			return $this->createMultipartUpload($bucket, $bucketFolder, $bucketName, $objectKey, $permission);
		}

		$uploadId = $query['uploadId'] ?? null;
		if ($uploadId !== null) {
			$partNumber = $query['partNumber'] ?? null;

			return match ($method) {
				'PUT' => $partNumber !== null
					? $this->uploadPart($bucket, $bucketFolder, $bucketName, $objectKey, $uploadId, $partNumber, $permission)
					: $this->errorResponse('InvalidRequest', 'partNumber is required', $resource, Http::STATUS_BAD_REQUEST),
				'POST' => $this->completeMultipartUpload($bucket, $bucketFolder, $bucketName, $objectKey, $uploadId, $permission),
				'DELETE' => $this->abortMultipartUpload($bucket, $bucketFolder, $bucketName, $objectKey, $uploadId, $permission),
				'GET' => $this->listParts($bucket, $bucketFolder, $bucketName, $objectKey, $uploadId),
				default => $this->errorResponse('MethodNotAllowed', 'Method not allowed', $resource, Http::STATUS_METHOD_NOT_ALLOWED),
			};
		}

		$unsupported = $this->unsupportedSubresource($query, $resource);
		if ($unsupported !== null) {
			return $unsupported;
		}

		return match ($method) {
			'GET' => $this->getObject($bucketFolder, $objectKey, $bucketName),
			'HEAD' => $this->headObject($bucketFolder, $objectKey, $bucketName),
			'PUT' => $this->putObject($bucketFolder, $objectKey, $bucketName, $permission),
			'DELETE' => $this->deleteObject($bucketFolder, $objectKey, $bucketName, $permission),
			default => $this->errorResponse('MethodNotAllowed', 'Method not allowed', $resource, Http::STATUS_METHOD_NOT_ALLOWED),
		};
	}

	/**
	 * Reject subresources we do not implement instead of silently treating the
	 * request as an operation on the object itself.
	 *
	 * @param array<string, string> $query
	 */
	private function unsupportedSubresource(array $query, string $resource): ?Response {
		$unsupported = [
			'acl', 'tagging', 'lifecycle', 'policy', 'cors', 'website',
			'replication', 'encryption', 'notification', 'logging',
			'accelerate', 'requestPayment', 'analytics', 'inventory',
			'metrics', 'object-lock', 'legal-hold', 'retention',
			'attributes', 'select', 'torrent', 'restore', 'publicAccessBlock',
			'ownershipControls', 'intelligent-tiering', 'versions',
		];

		foreach ($unsupported as $name) {
			if (array_key_exists($name, $query)) {
				return $this->errorResponse(
					'NotImplemented',
					'The requested subresource "' . $name . '" is not implemented',
					$resource,
					Http::STATUS_NOT_IMPLEMENTED,
				);
			}
		}

		return null;
	}

	/**
	 * Query parameters straight from the raw query string.
	 *
	 * parse_str() would rewrite dots in keys and cannot represent a valueless
	 * parameter such as `?uploads`, which is exactly how subresources are
	 * expressed.
	 *
	 * @return array<string, string>
	 */
	private function queryParams(): array {
		$uri = $this->request->getRequestUri();
		$pos = strpos($uri, '?');
		if ($pos === false) {
			return [];
		}

		$params = [];
		foreach (explode('&', substr($uri, $pos + 1)) as $pair) {
			if ($pair === '') {
				continue;
			}
			$eq = strpos($pair, '=');
			if ($eq === false) {
				$params[rawurldecode($pair)] = '';
			} else {
				$params[rawurldecode(substr($pair, 0, $eq))] = rawurldecode(substr($pair, $eq + 1));
			}
		}

		return $params;
	}

	private function listBuckets(string $userId): Response {
		$buckets = $this->bucketService->listBuckets($userId);
		$xml = $this->responseBuilder->listBucketsXml($userId, $buckets);
		return $this->xmlResponse($xml);
	}

	private function listObjects(string $userId, $bucket, Folder $bucketFolder): Response {
		// Read from the raw query string: getParam() would rewrite dots in keys
		// such as start-after=wal/dead.beef.pack.
		$query = $this->queryParams();

		$prefix = $query['prefix'] ?? '';
		$delimiter = $query['delimiter'] ?? '';
		$maxKeysRaw = $query['max-keys'] ?? '';
		$maxKeys = ctype_digit($maxKeysRaw) ? (int)$maxKeysRaw : 1000;

		if (($query['list-type'] ?? '1') === '2') {
			$xml = $this->responseBuilder->listObjectsV2Xml(
				$bucket->getBucketName(),
				$bucketFolder,
				$prefix,
				$delimiter,
				$query['start-after'] ?? '',
				$query['continuation-token'] ?? '',
				$maxKeys,
			);
		} else {
			$xml = $this->responseBuilder->listObjectsXml(
				$bucket->getBucketName(),
				$bucketFolder,
				$prefix,
				$delimiter,
				$query['marker'] ?? '',
				$maxKeys,
			);
		}

		return $this->xmlResponse($xml);
	}

	private function getObject(Folder $bucketFolder, string $objectKey, string $bucketName): Response {
		return $this->serveObject($bucketFolder, $objectKey, $bucketName, true);
	}

	private function headObject(Folder $bucketFolder, string $objectKey, string $bucketName): Response {
		return $this->serveObject($bucketFolder, $objectKey, $bucketName, false);
	}

	/**
	 * Serve an object for GET or HEAD, honouring conditional and range headers.
	 */
	private function serveObject(Folder $bucketFolder, string $objectKey, string $bucketName, bool $includeBody): Response {
		$resource = '/' . $bucketName . '/' . $objectKey;

		try {
			$node = $bucketFolder->get($objectKey);
		} catch (NotFoundException) {
			return $this->errorResponse('NoSuchKey', 'The specified key does not exist', $resource, Http::STATUS_NOT_FOUND, $includeBody);
		}

		if (!($node instanceof File)) {
			return $this->errorResponse('NoSuchKey', 'The specified key is not a file', $resource, Http::STATUS_NOT_FOUND, $includeBody);
		}

		$etag = $node->getEtag();

		// RFC 9110: If-Match is evaluated before If-None-Match.
		$ifMatch = trim((string)$this->request->getHeader('If-Match'));
		if ($ifMatch !== '' && !$this->etagMatches($ifMatch, $etag)) {
			return $this->errorResponse('PreconditionFailed', 'At least one of the pre-conditions you specified did not hold', $resource, Http::STATUS_PRECONDITION_FAILED, $includeBody);
		}

		// A matching If-None-Match means the caller's copy is current. This is
		// the cheap freshness check clients poll with, so it must not send a
		// body.
		$ifNoneMatch = trim((string)$this->request->getHeader('If-None-Match'));
		if ($ifNoneMatch !== '' && $this->etagMatches($ifNoneMatch, $etag)) {
			$response = new DataDisplayResponse('', Http::STATUS_NOT_MODIFIED);
			$response->addHeader('ETag', '"' . $etag . '"');
			return $response;
		}

		$size = $node->getSize();
		$range = $this->parseRange((string)$this->request->getHeader('Range'), $size);

		if ($range === false) {
			$response = $this->errorResponse('InvalidRange', 'The requested range is not satisfiable', $resource, Http::STATUS_REQUESTED_RANGE_NOT_SATISFIABLE, $includeBody);
			$response->addHeader('Content-Range', 'bytes */' . $size);
			return $response;
		}

		if ($range === null) {
			return new S3ObjectResponse($node, $includeBody);
		}

		return new S3ObjectResponse($node, $includeBody, $range[0], $range[1]);
	}

	/**
	 * Parse a `Range` header.
	 *
	 * Only a single range is honoured; multipart/byteranges responses are not
	 * worth the complexity for an S3 endpoint, and clients that ask for several
	 * ranges accept the whole object instead.
	 *
	 * @return array{0: int, 1: int}|null|false offsets (inclusive), null for no
	 *         range, false when the range cannot be satisfied
	 */
	private function parseRange(string $header, int $size): array|null|false {
		$header = trim($header);
		if ($header === '' || !str_starts_with($header, 'bytes=')) {
			return null;
		}

		$spec = substr($header, 6);
		if (str_contains($spec, ',')) {
			// Multiple ranges: serve the entire object, which is always legal.
			return null;
		}

		$dash = strpos($spec, '-');
		if ($dash === false) {
			return null;
		}

		$fromRaw = trim(substr($spec, 0, $dash));
		$toRaw = trim(substr($spec, $dash + 1));

		if ($fromRaw === '') {
			// Suffix range: the last N bytes.
			if ($toRaw === '' || !ctype_digit($toRaw)) {
				return null;
			}
			$suffix = (int)$toRaw;
			if ($suffix === 0) {
				return false;
			}
			if ($size === 0) {
				return false;
			}
			$start = $suffix >= $size ? 0 : $size - $suffix;
			return [$start, $size - 1];
		}

		if (!ctype_digit($fromRaw)) {
			return null;
		}

		$start = (int)$fromRaw;
		if ($start >= $size) {
			return false;
		}

		if ($toRaw === '') {
			return [$start, $size - 1];
		}

		if (!ctype_digit($toRaw)) {
			return null;
		}

		$end = min((int)$toRaw, $size - 1);
		if ($end < $start) {
			return false;
		}

		return [$start, $end];
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

		// Hand the stream to Nextcloud rather than writing through fopen():
		// putContent() copies it under an exclusive lock and updates the file
		// cache, so size and ETag reflect the new content. Writing to the raw
		// handle leaves stale metadata behind, which breaks ETag-based
		// conditional requests.
		try {
			if ($existing instanceof File) {
				$existing->putContent($stream);
			} else {
				$targetFolder->newFile($fileName, $stream);
			}
		} catch (LockedException) {
			return $this->errorResponse('SlowDown', 'The object is locked by another operation, please retry', $resource, Http::STATUS_SERVICE_UNAVAILABLE);
		} finally {
			// putContent()/newFile() close the stream themselves; guard against
			// the paths that do not.
			if (is_resource($stream)) {
				fclose($stream);
			}
		}

		// Re-read the node so the ETag is the one now stored.
		$file = $targetFolder->get($fileName);
		if (!($file instanceof File)) {
			return $this->errorResponse('InternalError', 'Object vanished after write', $resource, Http::STATUS_INTERNAL_SERVER_ERROR);
		}

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

	// ---- multipart upload -------------------------------------------------

	private function createMultipartUpload(
		$bucket,
		Folder $bucketFolder,
		string $bucketName,
		string $objectKey,
		string $permission,
	): Response {
		if ($permission !== 'readwrite') {
			return $this->errorResponse('AccessDenied', 'Write access denied', '/' . $bucketName . '/' . $objectKey, Http::STATUS_FORBIDDEN);
		}

		$uploadId = $this->multipartService->create(
			$bucket->getId(),
			$bucket->getUserId(),
			$objectKey,
		);

		return $this->xmlResponse(
			$this->responseBuilder->initiateMultipartUploadXml($bucketName, $objectKey, $uploadId),
		);
	}

	private function uploadPart(
		$bucket,
		Folder $bucketFolder,
		string $bucketName,
		string $objectKey,
		string $uploadId,
		string $partNumber,
		string $permission,
	): Response {
		$resource = '/' . $bucketName . '/' . $objectKey;

		if ($permission !== 'readwrite') {
			return $this->errorResponse('AccessDenied', 'Write access denied', $resource, Http::STATUS_FORBIDDEN);
		}

		if (!ctype_digit($partNumber)) {
			return $this->errorResponse('InvalidArgument', 'partNumber must be a number', $resource, Http::STATUS_BAD_REQUEST);
		}

		try {
			$this->multipartService->get($uploadId, $bucket->getId(), $objectKey);

			$sha256 = null;
			$stream = $this->requestBody->toStream($this->request, $sha256);

			$declared = strtolower(trim((string)$this->request->getHeader('x-amz-content-sha256')));
			if ($declared !== '' && ctype_xdigit($declared) && strlen($declared) === 64
				&& !hash_equals($declared, (string)$sha256)) {
				fclose($stream);
				return $this->errorResponse('XAmzContentSHA256Mismatch', 'The provided x-amz-content-sha256 does not match what was computed', $resource, Http::STATUS_BAD_REQUEST);
			}

			try {
				$etag = $this->multipartService->putPart($bucketFolder, $uploadId, (int)$partNumber, $stream);
			} finally {
				if (is_resource($stream)) {
					fclose($stream);
				}
			}
		} catch (S3AuthException $e) {
			return $this->errorResponse($e->getS3Code(), $e->getMessage(), $resource, $e->getHttpStatus());
		} catch (LockedException) {
			return $this->errorResponse('SlowDown', 'The upload is locked by another operation, please retry', $resource, Http::STATUS_SERVICE_UNAVAILABLE);
		}

		$response = new DataDisplayResponse('');
		$response->addHeader('ETag', '"' . $etag . '"');
		return $response;
	}

	private function completeMultipartUpload(
		$bucket,
		Folder $bucketFolder,
		string $bucketName,
		string $objectKey,
		string $uploadId,
		string $permission,
	): Response {
		$resource = '/' . $bucketName . '/' . $objectKey;

		if ($permission !== 'readwrite') {
			return $this->errorResponse('AccessDenied', 'Write access denied', $resource, Http::STATUS_FORBIDDEN);
		}

		try {
			$upload = $this->multipartService->get($uploadId, $bucket->getId(), $objectKey);

			$requested = $this->parseCompleteBody(file_get_contents('php://input') ?: '');
			if ($requested === null) {
				return $this->errorResponse('MalformedXML', 'The XML you provided was not well-formed', $resource, Http::STATUS_BAD_REQUEST);
			}

			$result = $this->multipartService->complete(
				$bucketFolder,
				$upload,
				$requested,
				fn($assembled) => $this->writeAssembledObject($bucketFolder, $objectKey, $assembled),
			);
		} catch (S3AuthException $e) {
			return $this->errorResponse($e->getS3Code(), $e->getMessage(), $resource, $e->getHttpStatus());
		} catch (LockedException) {
			return $this->errorResponse('SlowDown', 'The object is locked by another operation, please retry', $resource, Http::STATUS_SERVICE_UNAVAILABLE);
		}

		$location = $this->request->getServerProtocol() . '://' . $this->request->getServerHost()
			. '/apps/s3_api/s3/' . $bucketName . '/' . $objectKey;

		return $this->xmlResponse(
			$this->responseBuilder->completeMultipartUploadXml($location, $bucketName, $objectKey, $result['etag']),
		);
	}

	private function abortMultipartUpload(
		$bucket,
		Folder $bucketFolder,
		string $bucketName,
		string $objectKey,
		string $uploadId,
		string $permission,
	): Response {
		$resource = '/' . $bucketName . '/' . $objectKey;

		if ($permission !== 'readwrite') {
			return $this->errorResponse('AccessDenied', 'Write access denied', $resource, Http::STATUS_FORBIDDEN);
		}

		try {
			$upload = $this->multipartService->get($uploadId, $bucket->getId(), $objectKey);
			$this->multipartService->discard($bucketFolder, $upload);
		} catch (S3AuthException $e) {
			return $this->errorResponse($e->getS3Code(), $e->getMessage(), $resource, $e->getHttpStatus());
		}

		return new DataDisplayResponse('', Http::STATUS_NO_CONTENT);
	}

	private function listParts(
		$bucket,
		Folder $bucketFolder,
		string $bucketName,
		string $objectKey,
		string $uploadId,
	): Response {
		$resource = '/' . $bucketName . '/' . $objectKey;

		try {
			$this->multipartService->get($uploadId, $bucket->getId(), $objectKey);
			$parts = $this->multipartService->listParts($bucketFolder, $uploadId);
		} catch (S3AuthException $e) {
			return $this->errorResponse($e->getS3Code(), $e->getMessage(), $resource, $e->getHttpStatus());
		}

		return $this->xmlResponse(
			$this->responseBuilder->listPartsXml($bucketName, $objectKey, $uploadId, $parts),
		);
	}

	private function listMultipartUploads($bucket, string $bucketName): Response {
		$uploads = $this->multipartService->listUploads($bucket->getId());
		return $this->xmlResponse(
			$this->responseBuilder->listMultipartUploadsXml($bucketName, $uploads),
		);
	}

	/**
	 * Write the assembled multipart object, creating parent folders.
	 *
	 * @param resource $assembled
	 */
	private function writeAssembledObject(Folder $bucketFolder, string $objectKey, $assembled): File {
		$targetFolder = $this->ensureParentFolder($bucketFolder, $objectKey);
		$fileName = basename($objectKey);

		try {
			$existing = $targetFolder->get($fileName);
			if (!($existing instanceof File)) {
				throw new S3AuthException('InvalidArgument', 'Target exists and is not a file', Http::STATUS_CONFLICT);
			}
			$existing->putContent($assembled);
			$file = $existing;
		} catch (NotFoundException) {
			$file = $targetFolder->newFile($fileName, $assembled);
		}

		$reread = $targetFolder->get($fileName);
		return $reread instanceof File ? $reread : $file;
	}

	/**
	 * Parse the part list from a CompleteMultipartUpload body.
	 *
	 * @return array<int, array{number: int, etag: string}>|null null when the
	 *         XML cannot be parsed
	 */
	private function parseCompleteBody(string $xml): ?array {
		if (trim($xml) === '') {
			return null;
		}

		$previous = libxml_use_internal_errors(true);
		try {
			// LIBXML_NONET plus no entity substitution: the body is untrusted,
			// and an XXE here would read server-side files.
			$doc = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOENT);
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors($previous);
		}

		if ($doc === false) {
			return null;
		}

		$parts = [];
		foreach ($doc->Part as $part) {
			$number = (int)$part->PartNumber;
			if ($number < 1) {
				return null;
			}
			$parts[] = [
				'number' => $number,
				'etag' => trim((string)$part->ETag),
			];
		}

		return $parts === [] ? null : $parts;
	}

	// ---- bulk delete ------------------------------------------------------

	private function deleteObjects(Folder $bucketFolder, string $bucketName, string $permission): Response {
		$resource = '/' . $bucketName;

		if ($permission !== 'readwrite') {
			return $this->errorResponse('AccessDenied', 'Write access denied', $resource, Http::STATUS_FORBIDDEN);
		}

		$body = file_get_contents('php://input') ?: '';
		$previous = libxml_use_internal_errors(true);
		try {
			$doc = simplexml_load_string($body, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOENT);
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors($previous);
		}

		if ($doc === false) {
			return $this->errorResponse('MalformedXML', 'The XML you provided was not well-formed', $resource, Http::STATUS_BAD_REQUEST);
		}

		$quiet = strtolower(trim((string)$doc->Quiet)) === 'true';

		$deleted = [];
		$errors = [];
		foreach ($doc->Object as $object) {
			$key = (string)$object->Key;
			if ($key === '') {
				continue;
			}

			try {
				$node = $bucketFolder->get($key);
				$node->delete();
				$deleted[] = $key;
			} catch (NotFoundException) {
				// Deleting a missing key is a success in S3.
				$deleted[] = $key;
			} catch (LockedException) {
				$errors[] = ['key' => $key, 'code' => 'SlowDown', 'message' => 'The object is locked, please retry'];
			} catch (\Throwable) {
				$errors[] = ['key' => $key, 'code' => 'InternalError', 'message' => 'Could not delete the object'];
			}
		}

		return $this->xmlResponse(
			$this->responseBuilder->deleteResultXml($quiet ? [] : $deleted, $errors),
		);
	}

	/**
	 * Create and return the folder holding `$objectKey`.
	 */
	private function ensureParentFolder(Folder $bucketFolder, string $objectKey): Folder {
		$dir = dirname($objectKey);
		if ($dir === '' || $dir === '.') {
			return $bucketFolder;
		}

		$folder = $bucketFolder;
		foreach (explode('/', $dir) as $part) {
			if ($part === '' || $part === '.') {
				continue;
			}
			try {
				$sub = $folder->get($part);
				if (!($sub instanceof Folder)) {
					throw new S3AuthException('InvalidArgument', 'Path component is not a directory', Http::STATUS_CONFLICT);
				}
				$folder = $sub;
			} catch (NotFoundException) {
				$folder = $folder->newFolder($part);
			}
		}

		return $folder;
	}

	private function xmlResponse(string $xml, int $status = Http::STATUS_OK): Response {
		$response = new DataDisplayResponse($xml, $status);
		$response->addHeader('Content-Type', 'application/xml');
		return $response;
	}

	/**
	 * @param bool $withBody false to omit the XML body, which a response to
	 *        HEAD must do
	 */
	private function errorResponse(string $code, string $message, string $resource, int $httpStatus, bool $withBody = true): Response {
		if (!$withBody) {
			$response = new DataDisplayResponse('', $httpStatus);
			$response->addHeader('Content-Type', 'application/xml');
			return $response;
		}

		$xml = $this->responseBuilder->errorXml($code, $message, $resource);
		return $this->xmlResponse($xml, $httpStatus);
	}
}
