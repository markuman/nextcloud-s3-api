<?php

declare(strict_types=1);

namespace OCA\S3Api\Controller;

use OCA\S3Api\Db\BucketMapper;
use OCA\S3Api\Http\S3ObjectResponse;
use OCA\S3Api\Service\BucketService;
use OCA\S3Api\Service\EtagService;
use OCA\S3Api\Service\MultipartService;
use OCA\S3Api\Service\ObjectLockService;
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
use Psr\Log\LoggerInterface;

class S3Controller extends Controller {

	public function __construct(
		string $appName,
		IRequest $request,
		private S3AuthService $authService,
		private BucketService $bucketService,
		private BucketMapper $bucketMapper,
		private S3ResponseBuilder $responseBuilder,
		private S3RequestBody $requestBody,
		private EtagService $etagService,
		private MultipartService $multipartService,
		private ObjectLockService $objectLock,
		private IRootFolder $rootFolder,
		private LoggerInterface $logger,
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
		try {
			$response = $this->dispatch($path);
		} catch (S3AuthException $e) {
			$response = $this->errorResponse($e->getS3Code(), $e->getMessage(), '/' . $path, $e->getHttpStatus());
		} catch (LockedException) {
			// Nextcloud's locking surfaces as 423, which S3 clients treat as a
			// permanent failure. 503 is the retryable equivalent.
			$response = $this->errorResponse('SlowDown', 'The resource is locked, please retry', '/' . $path, Http::STATUS_SERVICE_UNAVAILABLE);
		} catch (\Throwable $e) {
			// Without this the framework renders an HTML error page, which no S3
			// client can parse, and the failure looks like a protocol error
			// rather than a server fault.
			$this->logger->error('Unhandled error serving S3 request', [
				'app' => 's3_api',
				'exception' => $e,
			]);
			$response = $this->errorResponse('InternalError', 'We encountered an internal error, please try again', '/' . $path, Http::STATUS_INTERNAL_SERVER_ERROR);
		}

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
				return $this->listBuckets($userId, $auth['bucketId']);
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

		if ($objectKey !== '' && !$this->isUsableKey($objectKey)) {
			return $this->errorResponse('InvalidArgument', 'The specified key is not valid', '/' . $bucketName . '/' . $objectKey, Http::STATUS_BAD_REQUEST);
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

			$copySource = trim((string)$this->request->getHeader('x-amz-copy-source'));

			return match (true) {
				$method === 'PUT' && $partNumber === null => $this->errorResponse('InvalidRequest', 'partNumber is required', $resource, Http::STATUS_BAD_REQUEST),
				// UploadPartCopy: a part sourced from another object rather than
				// from the request body.
				$method === 'PUT' && $copySource !== '' => $this->uploadPartCopy($bucket, $bucketFolder, $bucketName, $objectKey, $uploadId, $partNumber, $copySource, $permission),
				$method === 'PUT' => $this->uploadPart($bucket, $bucketFolder, $bucketName, $objectKey, $uploadId, $partNumber, $permission),
				$method === 'POST' => $this->completeMultipartUpload($bucket, $bucketFolder, $bucketName, $objectKey, $uploadId, $permission),
				$method === 'DELETE' => $this->abortMultipartUpload($bucket, $bucketFolder, $bucketName, $objectKey, $uploadId, $permission),
				$method === 'GET' => $this->listParts($bucket, $bucketFolder, $bucketName, $objectKey, $uploadId),
				default => $this->errorResponse('MethodNotAllowed', 'Method not allowed', $resource, Http::STATUS_METHOD_NOT_ALLOWED),
			};
		}

		$unsupported = $this->unsupportedSubresource($query, $resource);
		if ($unsupported !== null) {
			return $unsupported;
		}

		$copySource = trim((string)$this->request->getHeader('x-amz-copy-source'));

		return match (true) {
			$method === 'GET' => $this->getObject($bucketFolder, $objectKey, $bucketName),
			$method === 'HEAD' => $this->headObject($bucketFolder, $objectKey, $bucketName),
			// CopyObject is a PUT that names a source instead of sending a body.
			// Treating it as a plain PUT would truncate the target to nothing.
			$method === 'PUT' && $copySource !== '' => $this->copyObject($bucketFolder, $bucketName, $objectKey, $copySource, $permission),
			$method === 'PUT' => $this->putObject($bucket, $bucketFolder, $objectKey, $bucketName, $permission),
			$method === 'DELETE' => $this->deleteObject($bucketFolder, $objectKey, $bucketName, $permission),
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
	 * Whether a key can be stored as a path under the bucket folder.
	 *
	 * S3 keys are opaque byte strings, but here they become file paths, so the
	 * ones that cannot be are rejected up front. Nextcloud refuses these anyway
	 * -- nothing escapes the bucket -- but it does so by raising, which would
	 * surface as an unhelpful 500 instead of a client error naming the problem.
	 */
	private function isUsableKey(string $objectKey): bool {
		if (str_contains($objectKey, "\0")) {
			return false;
		}

		// A trailing slash denotes a "directory marker", which has no file to
		// store it in.
		if (str_ends_with($objectKey, '/')) {
			return false;
		}

		foreach (explode('/', $objectKey) as $segment) {
			// Empty segments come from "a//b"; "." and ".." would resolve
			// outside the intended path.
			if ($segment === '' || $segment === '.' || $segment === '..') {
				return false;
			}
		}

		// The multipart scratch area is internal.
		if (str_starts_with($objectKey, MultipartService::SCRATCH_DIR . '/')
			|| $objectKey === MultipartService::SCRATCH_DIR) {
			return false;
		}

		return true;
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

	/**
	 * ListBuckets, restricted to the bucket the credential is scoped to.
	 *
	 * Credentials are issued per bucket, so listing every bucket the owning
	 * account happens to have would tell the holder of one key about buckets it
	 * cannot address.
	 */
	private function listBuckets(string $userId, int $bucketId): Response {
		$buckets = array_values(array_filter(
			$this->bucketService->listBuckets($userId),
			static fn($bucket): bool => $bucket->getId() === $bucketId,
		));

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

		$etag = $this->etagService->get($node);

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
			$response = $this->errorResponse('InvalidRange', 'The requested range is not satisfiable', $resource, Http::STATUS_REQUEST_RANGE_NOT_SATISFIABLE, $includeBody);
			$response->addHeader('Content-Range', 'bytes */' . $size);
			return $response;
		}

		if ($range === null) {
			return new S3ObjectResponse($node, $etag, $includeBody);
		}

		return new S3ObjectResponse($node, $etag, $includeBody, $range[0], $range[1]);
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

	private function putObject($bucket, Folder $bucketFolder, string $objectKey, string $bucketName, string $permission): Response {
		if ($permission !== 'readwrite') {
			return $this->errorResponse('AccessDenied', 'Write access denied', '/' . $bucketName . '/' . $objectKey, Http::STATUS_FORBIDDEN);
		}

		$resource = '/' . $bucketName . '/' . $objectKey;

		$ifMatch = trim((string)$this->request->getHeader('If-Match'));
		$ifNoneMatch = trim((string)$this->request->getHeader('If-None-Match'));
		$conditional = $ifMatch !== '' || $ifNoneMatch !== '';

		// Read the body before taking the lock: the upload can take a while and
		// nothing about it depends on the object's current state.
		$sha256 = null;
		try {
			$stream = $this->requestBody->toStream($this->request, $sha256);
		} catch (S3AuthException $e) {
			return $this->errorResponse($e->getS3Code(), $e->getMessage(), $resource, $e->getHttpStatus());
		}

		try {
			// Verify the payload hash when the client committed to a literal one.
			$declaredHash = strtolower(trim((string)$this->request->getHeader('x-amz-content-sha256')));
			if ($declaredHash !== '' && ctype_xdigit($declaredHash) && strlen($declaredHash) === 64
				&& !hash_equals($declaredHash, (string)$sha256)) {
				return $this->errorResponse(
					'XAmzContentSHA256Mismatch',
					'The provided x-amz-content-sha256 does not match what was computed',
					$resource,
					Http::STATUS_BAD_REQUEST,
				);
			}

			$write = fn(): Response => $this->writeObject(
				$bucketFolder,
				$objectKey,
				$resource,
				$stream,
				$ifMatch,
				$ifNoneMatch,
			);

			if (!$conditional) {
				return $write();
			}

			// A conditional write must evaluate the precondition and store the
			// body without anything else touching the key in between, or two
			// concurrent `If-None-Match: *` requests both see "absent" and both
			// succeed -- a silently lost update for a client using this for
			// compare-and-swap.
			return $this->objectLock->withLock($bucket->getId(), $objectKey, $write);
		} catch (S3AuthException $e) {
			return $this->errorResponse($e->getS3Code(), $e->getMessage(), $resource, $e->getHttpStatus());
		} catch (LockedException) {
			return $this->errorResponse('SlowDown', 'The object is locked by another operation, please retry', $resource, Http::STATUS_SERVICE_UNAVAILABLE);
		} finally {
			if (is_resource($stream)) {
				fclose($stream);
			}
		}
	}

	/**
	 * Evaluate the preconditions and store the body.
	 *
	 * Called with the object lock held for conditional writes, so the lookup and
	 * the write are one step.
	 *
	 * @param resource $stream
	 */
	private function writeObject(
		Folder $bucketFolder,
		string $objectKey,
		string $resource,
		$stream,
		string $ifMatch,
		string $ifNoneMatch,
	): Response {
		$targetFolder = $this->ensureParentFolder($bucketFolder, $objectKey);
		$fileName = basename($objectKey);

		$existing = null;
		try {
			$existing = $targetFolder->get($fileName);
		} catch (NotFoundException) {
			// New object.
		}

		if ($existing !== null && !($existing instanceof File)) {
			return $this->errorResponse('InvalidArgument', 'Target exists and is not a file', $resource, Http::STATUS_CONFLICT);
		}

		$precondition = $this->checkWritePreconditions($existing, $resource, $ifMatch, $ifNoneMatch);
		if ($precondition !== null) {
			return $precondition;
		}

		// Hand the stream to Nextcloud rather than writing through fopen():
		// putContent() copies it under an exclusive lock and updates the file
		// cache, so size and ETag reflect the new content. Writing to the raw
		// handle leaves stale metadata behind, which breaks ETag-based
		// conditional requests.
		if ($existing instanceof File) {
			$existing->putContent($stream);
		} else {
			$targetFolder->newFile($fileName, $stream);
		}

		// Re-read the node so the ETag is derived from what is now stored.
		$file = $targetFolder->get($fileName);
		if (!($file instanceof File)) {
			return $this->errorResponse('InternalError', 'Object vanished after write', $resource, Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		$response = new DataDisplayResponse('');
		$response->setStatus(Http::STATUS_OK);
		$response->addHeader('ETag', '"' . $this->etagService->refresh($file) . '"');
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
	private function checkWritePreconditions(?File $existing, string $resource, string $ifMatch, string $ifNoneMatch): ?Response {
		if ($ifMatch !== '') {
			if ($existing === null) {
				return $this->errorResponse('PreconditionFailed', 'At least one of the pre-conditions you specified did not hold', $resource, Http::STATUS_PRECONDITION_FAILED);
			}
			if (!$this->etagMatches($ifMatch, $this->etagService->get($existing))) {
				return $this->errorResponse('PreconditionFailed', 'At least one of the pre-conditions you specified did not hold', $resource, Http::STATUS_PRECONDITION_FAILED);
			}
		}

		if ($ifNoneMatch !== '') {
			if ($ifNoneMatch === '*') {
				if ($existing !== null) {
					return $this->errorResponse('PreconditionFailed', 'At least one of the pre-conditions you specified did not hold', $resource, Http::STATUS_PRECONDITION_FAILED);
				}
			} elseif ($existing !== null && $this->etagMatches($ifNoneMatch, $this->etagService->get($existing))) {
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
			$fileId = $node->getId();
			$node->delete();
			// Drop the recorded ETag: file ids are reused, so a stale row would
			// otherwise be served for a different object.
			$this->etagService->forget($fileId);
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

			// Record the compound ETag, otherwise the next read would replace it
			// with a plain content hash and the value would change without the
			// object changing.
			$this->etagService->set($result['file'], $result['etag']);
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

		// Resolve first, write second: wrapping the write in the same try as the
		// lookup would catch a NotFoundException raised by putContent() and
		// retry it as a create against a file that already exists.
		$existing = null;
		try {
			$existing = $targetFolder->get($fileName);
		} catch (NotFoundException) {
			// Creating a new object.
		}

		if ($existing !== null && !($existing instanceof File)) {
			throw new S3AuthException('InvalidArgument', 'Target exists and is not a file', Http::STATUS_CONFLICT);
		}

		if ($existing instanceof File) {
			$existing->putContent($assembled);
		} else {
			$targetFolder->newFile($fileName, $assembled);
		}

		$reread = $targetFolder->get($fileName);
		if (!($reread instanceof File)) {
			throw new S3AuthException('InternalError', 'Object vanished after write', Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return $reread;
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

	// ---- copy -------------------------------------------------------------

	/**
	 * CopyObject: server-side copy from `x-amz-copy-source`.
	 */
	private function copyObject(
		Folder $bucketFolder,
		string $bucketName,
		string $objectKey,
		string $copySource,
		string $permission,
	): Response {
		$resource = '/' . $bucketName . '/' . $objectKey;

		if ($permission !== 'readwrite') {
			return $this->errorResponse('AccessDenied', 'Write access denied', $resource, Http::STATUS_FORBIDDEN);
		}

		$sourceKey = $this->parseCopySource($copySource, $bucketName);
		if ($sourceKey === null) {
			return $this->errorResponse('InvalidArgument', 'Malformed or cross-bucket x-amz-copy-source', $resource, Http::STATUS_BAD_REQUEST);
		}

		try {
			$source = $bucketFolder->get($sourceKey);
		} catch (NotFoundException) {
			return $this->errorResponse('NoSuchKey', 'The specified copy source does not exist', $resource, Http::STATUS_NOT_FOUND);
		}

		if (!($source instanceof File)) {
			return $this->errorResponse('NoSuchKey', 'The copy source is not a file', $resource, Http::STATUS_NOT_FOUND);
		}

		if ($sourceKey === $objectKey) {
			// A self-copy would truncate the source before reading it.
			return $this->xmlResponse($this->responseBuilder->copyObjectResultXml($this->etagService->get($source), $source->getMTime()));
		}

		$handle = $source->fopen('rb');
		if ($handle === false) {
			return $this->errorResponse('InternalError', 'Cannot read copy source', $resource, Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		try {
			$file = $this->writeAssembledObject($bucketFolder, $objectKey, $handle);
		} finally {
			if (is_resource($handle)) {
				fclose($handle);
			}
		}

		return $this->xmlResponse($this->responseBuilder->copyObjectResultXml($this->etagService->refresh($file), $file->getMTime()));
	}

	/**
	 * UploadPartCopy: a part whose bytes come from another object, optionally a
	 * byte range of it. This is how clients concatenate objects server-side.
	 */
	private function uploadPartCopy(
		$bucket,
		Folder $bucketFolder,
		string $bucketName,
		string $objectKey,
		string $uploadId,
		string $partNumber,
		string $copySource,
		string $permission,
	): Response {
		$resource = '/' . $bucketName . '/' . $objectKey;

		if ($permission !== 'readwrite') {
			return $this->errorResponse('AccessDenied', 'Write access denied', $resource, Http::STATUS_FORBIDDEN);
		}

		if (!ctype_digit($partNumber)) {
			return $this->errorResponse('InvalidArgument', 'partNumber must be a number', $resource, Http::STATUS_BAD_REQUEST);
		}

		$sourceKey = $this->parseCopySource($copySource, $bucketName);
		if ($sourceKey === null) {
			return $this->errorResponse('InvalidArgument', 'Malformed or cross-bucket x-amz-copy-source', $resource, Http::STATUS_BAD_REQUEST);
		}

		try {
			$this->multipartService->get($uploadId, $bucket->getId(), $objectKey);

			$source = $bucketFolder->get($sourceKey);
			if (!($source instanceof File)) {
				return $this->errorResponse('NoSuchKey', 'The copy source is not a file', $resource, Http::STATUS_NOT_FOUND);
			}

			$size = $source->getSize();
			$range = $this->parseCopySourceRange(
				trim((string)$this->request->getHeader('x-amz-copy-source-range')),
				$size,
			);
			if ($range === false) {
				return $this->errorResponse('InvalidArgument', 'The x-amz-copy-source-range is not satisfiable', $resource, Http::STATUS_BAD_REQUEST);
			}

			$slice = $this->sliceOf($source, $range);
			try {
				$etag = $this->multipartService->putPart($bucketFolder, $uploadId, (int)$partNumber, $slice);
			} finally {
				if (is_resource($slice)) {
					fclose($slice);
				}
			}
		} catch (NotFoundException) {
			return $this->errorResponse('NoSuchKey', 'The specified copy source does not exist', $resource, Http::STATUS_NOT_FOUND);
		} catch (S3AuthException $e) {
			return $this->errorResponse($e->getS3Code(), $e->getMessage(), $resource, $e->getHttpStatus());
		}

		return $this->xmlResponse($this->responseBuilder->copyPartResultXml($etag, time()));
	}

	/**
	 * Extract the key from `x-amz-copy-source`.
	 *
	 * The value is `/bucket/key` or `bucket/key`, percent-encoded, and may
	 * carry a `?versionId=` suffix. Only copies inside the authorised bucket
	 * are allowed, since credentials are scoped to one bucket.
	 */
	private function parseCopySource(string $copySource, string $bucketName): ?string {
		$value = ltrim($copySource, '/');

		$question = strpos($value, '?');
		if ($question !== false) {
			$value = substr($value, 0, $question);
		}

		$slash = strpos($value, '/');
		if ($slash === false) {
			return null;
		}

		$sourceBucket = rawurldecode(substr($value, 0, $slash));
		if ($sourceBucket !== $bucketName) {
			return null;
		}

		$key = rawurldecode(substr($value, $slash + 1));
		if ($key === '' || str_contains($key, '..')) {
			return null;
		}

		return $key;
	}

	/**
	 * @return array{0: int, 1: int}|null|false null for the whole object, false
	 *         when the range cannot be satisfied
	 */
	private function parseCopySourceRange(string $header, int $size): array|null|false {
		if ($header === '') {
			return null;
		}

		if (!str_starts_with($header, 'bytes=')) {
			return false;
		}

		$spec = substr($header, 6);
		$dash = strpos($spec, '-');
		if ($dash === false) {
			return false;
		}

		$fromRaw = trim(substr($spec, 0, $dash));
		$toRaw = trim(substr($spec, $dash + 1));

		// Unlike Range, a copy-source range must be fully specified.
		if (!ctype_digit($fromRaw) || !ctype_digit($toRaw)) {
			return false;
		}

		$start = (int)$fromRaw;
		$end = (int)$toRaw;

		if ($start > $end || $start >= $size || $end >= $size) {
			return false;
		}

		return [$start, $end];
	}

	/**
	 * A stream over `$range` of `$file`, or the whole file when null.
	 *
	 * @param array{0: int, 1: int}|null $range
	 * @return resource
	 */
	private function sliceOf(File $file, ?array $range) {
		$handle = $file->fopen('rb');
		if ($handle === false) {
			throw new S3AuthException('InternalError', 'Cannot read copy source', Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		if ($range === null) {
			return $handle;
		}

		$out = fopen('php://temp/maxmemory:' . (8 * 1024 * 1024), 'w+b');
		if ($out === false) {
			fclose($handle);
			throw new S3AuthException('InternalError', 'Cannot buffer copy source', Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		try {
			if ($range[0] > 0) {
				fseek($handle, $range[0]);
			}

			$remaining = $range[1] - $range[0] + 1;
			while ($remaining > 0 && !feof($handle)) {
				$buf = fread($handle, (int)min(1024 * 1024, $remaining));
				if ($buf === false || $buf === '') {
					break;
				}
				fwrite($out, $buf);
				$remaining -= strlen($buf);
			}
		} finally {
			fclose($handle);
		}

		rewind($out);
		return $out;
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
				$fileId = $node->getId();
				$node->delete();
				$this->etagService->forget($fileId);
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
