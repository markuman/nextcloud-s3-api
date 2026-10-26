<?php

declare(strict_types=1);

namespace OCA\S3Api\Controller;

use OCA\S3Api\Db\BucketMapper;
use OCA\S3Api\Http\S3ObjectResponse;
use OCA\S3Api\Service\BodyHash;
use OCA\S3Api\Service\BucketService;
use OCA\S3Api\Service\ChunkSignatureVerifier;
use OCA\S3Api\Service\EtagService;
use OCA\S3Api\Service\KeyRules;
use OCA\S3Api\Service\MultipartService;
use OCA\S3Api\Service\ObjectLockService;
use OCA\S3Api\Service\PayloadIntegrity;
use OCA\S3Api\Service\S3AuthException;
use OCA\S3Api\Service\S3AuthService;
use OCA\S3Api\Service\S3RequestBody;
use OCA\S3Api\Service\S3ResponseBuilder;
use OCA\S3Api\Service\TempFiles;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\StreamResponse;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\ForbiddenException;
use OCP\Files\InvalidPathException;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\IRequest;
use OCP\Log\Audit\CriticalActionPerformedEvent;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;

class S3Controller extends Controller {

	/** S3's limit on the keys in one DeleteObjects request. */
	private const MAX_DELETE_KEYS = 1000;

	/** S3's limit on the parts of one multipart upload. */
	private const MAX_COMPLETE_PARTS = 10000;

	/**
	 * Largest object one PUT, and largest part one UploadPart, may carry: 5
	 * GiB, as on S3. Anything bigger goes up as a multipart upload. The body
	 * passes through the temp directory before Nextcloud stores it, so
	 * without a bound one request could fill that directory for the whole
	 * instance, whatever the uploader's quota.
	 */
	private const MAX_OBJECT_BYTES = 5 * 1024 * 1024 * 1024;

	/**
	 * Largest XML body accepted, before parsing. A full DeleteObjects (1000
	 * keys of up to 1024 bytes) or CompleteMultipartUpload (10000 parts)
	 * stays well below it. The body is held in memory and parsed into a tree
	 * several times its size, and post_max_size does not apply to a raw
	 * `application/xml` body -- without this the web server's limit is the
	 * only one.
	 */
	private const MAX_XML_BODY_BYTES = 4 * 1024 * 1024;

	/**
	 * Brute force protection bucket for failed credentials. Nextcloud delays
	 * further requests from an address that keeps failing, per action, so
	 * this slows guessing at keys without touching the login's own counter.
	 */
	private const THROTTLE_ACTION = 's3_api_auth';

	/** Auth failures that are a wrong guess, as opposed to a stale clock. */
	private const THROTTLED_CODES = ['InvalidAccessKeyId', 'SignatureDoesNotMatch'];

	/**
	 * Chunk signature chain for this request, when it carries signed chunks.
	 *
	 * Set once the request is authenticated, read by the handlers that consume a
	 * body. Safe as state because a controller instance serves one request.
	 */
	private ?ChunkSignatureVerifier $chunkVerifier = null;

	/**
	 * Lower-case names of the headers the request signed, and the access
	 * key it authenticated with. Request state like $chunkVerifier.
	 *
	 * @var string[]
	 */
	private array $signedHeaders = [];

	private string $accessKey = '';

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
		private TempFiles $tempFiles,
		private IEventDispatcher $eventDispatcher,
		private LoggerInterface $logger,
	) {
		parent::__construct($appName, $request);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[BruteForceProtection(action: self::THROTTLE_ACTION)]
	public function listBucketsOrRoot(): Response {
		return $this->handleRequest('');
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[BruteForceProtection(action: self::THROTTLE_ACTION)]
	public function handleGet(string $path = ''): Response {
		return $this->handleRequest($path);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[BruteForceProtection(action: self::THROTTLE_ACTION)]
	public function handlePut(string $path = ''): Response {
		return $this->handleRequest($path);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[BruteForceProtection(action: self::THROTTLE_ACTION)]
	public function handleDelete(string $path = ''): Response {
		return $this->handleRequest($path);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[BruteForceProtection(action: self::THROTTLE_ACTION)]
	public function handlePost(string $path = ''): Response {
		return $this->handleRequest($path);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[BruteForceProtection(action: self::THROTTLE_ACTION)]
	public function handleHead(string $path = ''): Response {
		return $this->handleRequest($path);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[BruteForceProtection(action: self::THROTTLE_ACTION)]
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
		} catch (InvalidPathException) {
			// A name Nextcloud will not store: too long for one path segment, a
			// reserved name, a character the admin has forbidden.
			$response = $this->errorResponse('InvalidArgument', 'The specified key is not valid', '/' . $path, Http::STATUS_BAD_REQUEST);
		} catch (NotPermittedException | ForbiddenException) {
			// The bucket folder is a read-only share, the quota is used up, an
			// access control rule applies, or Nextcloud refused the name (such
			// as `.htaccess`). The client's doing, not a server fault.
			$response = $this->errorResponse('AccessDenied', 'Access Denied', '/' . $path, Http::STATUS_FORBIDDEN);
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
			if ($response->isThrottled()) {
				$stripped->throttle($response->getThrottleMetadata());
			}
			return $stripped;
		}

		return $response;
	}

	private function dispatch(string $path = ''): Response {
		try {
			$auth = $this->authService->authenticate($this->request);
		} catch (S3AuthException $e) {
			// For the log and the throttle in errorResponse(), which handles
			// wrong guesses.
			$this->accessKey = $e->getAccessKey() ?? '';
			if (!in_array($e->getS3Code(), self::THROTTLED_CODES, true)) {
				$this->logger->info('S3 request refused', [
					'app' => 's3_api',
					'code' => $e->getS3Code(),
					'accessKey' => $this->accessKey,
					'remoteAddress' => $this->request->getRemoteAddress(),
				]);
			}
			return $this->errorResponse($e->getS3Code(), $e->getMessage(), '/' . $path, $e->getHttpStatus());
		}

		// Set for this request only; the controller is instantiated per request.
		$this->chunkVerifier = $auth['chunkVerifier'];
		$this->signedHeaders = $auth['signedHeaders'];
		$this->accessKey = $auth['accessKey'];

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

		$path = $this->requestedPath($path);
		if ($path === null) {
			return $this->errorResponse('InvalidURI', "Couldn't parse the specified URI", '/', Http::STATUS_BAD_REQUEST);
		}
		$slashPos = strpos($path, '/');
		if ($slashPos === false) {
			$bucketName = $path;
			$objectKey = '';
		} else {
			$bucketName = substr($path, 0, $slashPos);
			$objectKey = substr($path, $slashPos + 1);
		}

		if (strlen($objectKey) > KeyRules::MAX_KEY_BYTES) {
			return $this->errorResponse('KeyTooLongError', 'Your key is too long', '/' . $bucketName, Http::STATUS_BAD_REQUEST);
		}

		if ($objectKey !== '' && !$this->isUsableKey($objectKey)) {
			return $this->errorResponse('InvalidArgument', 'The specified key is not valid', '/' . $bucketName . '/' . $objectKey, Http::STATUS_BAD_REQUEST);
		}

		// A key reaches exactly one bucket. Any other name gets the same answer
		// whether or not the owner has a bucket by that name: telling the two
		// apart would let a key enumerate its owner's other buckets, which
		// ListBuckets deliberately does not reveal.
		$bucket = $this->bucketService->getBucketByName($userId, $bucketName);
		if ($bucket === null || $auth['bucketId'] !== $bucket->getId()) {
			return $this->errorResponse('NoSuchBucket', 'The specified bucket does not exist', '/' . $bucketName, Http::STATUS_NOT_FOUND);
		}

		// By the folder's file id, not its path: see BucketService.
		$bucketFolder = $this->bucketService->resolveFolder($bucket);
		if ($bucketFolder === null) {
			return $this->errorResponse('NoSuchBucket', 'Bucket folder not found', '/' . $bucketName, Http::STATUS_NOT_FOUND);
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
				$known === 'uploads' && $method === 'GET' => $this->listMultipartUploads($bucket, $bucketName, $query, $permission),
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
				$method === 'GET' => $this->listParts($bucket, $bucketFolder, $bucketName, $objectKey, $uploadId, $query, $permission),
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
			$method === 'PUT' => $this->putObject($bucketFolder, $objectKey, $bucketName, $permission),
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
	 * The `bucket/key` the client addressed, with every slash it sent.
	 *
	 * The routed `{path}` has lost some of them: Nextcloud collapses each run
	 * of slashes in the request URI before routing — a guard against reverse
	 * proxies that double them — so `a//b` arrives as `a/b`. In S3 those are
	 * two different keys, and the client signed its request for the first;
	 * serving the second silently aliases another object, a DELETE included.
	 * Read from the raw request URI instead, the key keeps its empty segment
	 * and isUsableKey() refuses it.
	 *
	 * Slashes between the endpoint and the bucket are skipped: a client whose
	 * endpoint is configured with a trailing slash sends `…/s3//bucket/…`, and
	 * that names no key. The raw path is only used when collapsing it gives
	 * back exactly what was routed, so the two can differ in collapsed slashes
	 * and nothing else.
	 *
	 * A request URI of any other shape is refused rather than served from the
	 * routed path: that path is exactly the one that may have lost a slash,
	 * so falling back to it would quietly bring the aliasing back the day a
	 * Nextcloud release or a proxy rewrites URIs in some new way.
	 *
	 * @return string|null null when the request URI does not account for the
	 *         routed path
	 */
	private function requestedPath(string $routed): ?string {
		$uri = $this->request->getRequestUri();
		$query = strpos($uri, '?');
		if ($query !== false) {
			$uri = substr($uri, 0, $query);
		}

		if (preg_match('#/apps/s3_api/s3/+(.+)$#', $uri, $match) !== 1) {
			$this->logger->warning('S3 request URI does not match the routed path', [
				'app' => 's3_api',
				'uri' => $uri,
				'routed' => $routed,
			]);
			return null;
		}

		// Nextcloud collapses first and decodes second; compare the same way,
		// or an encoded slash next to a literal one would not round-trip.
		if (rawurldecode((string)preg_replace('#/{2,}#', '/', $match[1])) !== $routed) {
			$this->logger->warning('S3 request URI does not match the routed path', [
				'app' => 's3_api',
				'uri' => $uri,
				'routed' => $routed,
			]);
			return null;
		}

		return rawurldecode($match[1]);
	}

	/**
	 * Whether a key can be stored as a path under the bucket folder.
	 *
	 * S3 keys are opaque byte strings, but here they become file paths, so the
	 * ones that cannot be are rejected up front. Nextcloud refuses these anyway
	 * -- nothing escapes the bucket -- but it does so by raising, which would
	 * surface as an unhelpful 500 instead of a client error naming the problem.
	 *
	 * The segment rules live in KeyRules, shared with the listing prefix. A
	 * trailing slash -- a "directory marker", which has no file to store it in
	 * -- fails them as an empty last segment.
	 */
	private function isUsableKey(string $objectKey): bool {
		return KeyRules::isPlainPath($objectKey) && !KeyRules::isInScratchArea($objectKey);
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

	private function putObject(Folder $bucketFolder, string $objectKey, string $bucketName, string $permission): Response {
		if ($permission !== 'readwrite') {
			return $this->errorResponse('AccessDenied', 'Write access denied', '/' . $bucketName . '/' . $objectKey, Http::STATUS_FORBIDDEN);
		}

		$resource = '/' . $bucketName . '/' . $objectKey;

		$ifMatch = trim((string)$this->request->getHeader('If-Match'));
		$ifNoneMatch = trim((string)$this->request->getHeader('If-None-Match'));
		$conditional = $ifMatch !== '' || $ifNoneMatch !== '';

		// Read the body before taking the lock: the upload can take a while and
		// nothing about it depends on the object's current state.
		try {
			$stream = $this->readUploadBody($bucketFolder);
		} catch (S3AuthException $e) {
			return $this->errorResponse($e->getS3Code(), $e->getMessage(), $resource, $e->getHttpStatus());
		}

		try {
			// Comparing against a stored ETag hashes the object first if it
			// has none recorded -- a read of the whole file. Do that before
			// taking the lock, so the lock is held for the lookup and the
			// write, not for however long the hash takes; under the lock
			// get() then finds the record, unless the file changed meanwhile.
			if ($ifMatch !== '' || ($ifNoneMatch !== '' && $ifNoneMatch !== '*')) {
				try {
					$current = $bucketFolder->get($objectKey);
					if ($current instanceof File) {
						$this->etagService->get($current);
					}
				} catch (NotFoundException) {
					// Nothing to compare against; the precondition decides.
				}
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
			return $this->objectLock->withLock($bucketFolder, $objectKey, $write);
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
	 * Read an upload body into a temp stream and check every integrity claim
	 * the request makes about it: a literal payload hash, `Content-MD5`,
	 * `x-amz-checksum-*` as header or trailer (see PayloadIntegrity). S3
	 * answers a mismatch with BadDigest, and clients rely on that: a checksum
	 * they signed is all that ties an `UNSIGNED-PAYLOAD` body to the request.
	 *
	 * Bounded twice before the body reaches the temp directory: by what one
	 * request may carry at all, and by the free space where it is going to
	 * be stored. Nextcloud's quota only applies once the bytes are stored,
	 * which is after they have all been written to the temp directory --
	 * shared by every user of the instance.
	 *
	 * @return resource rewound, owned by the caller
	 * @throws S3AuthException
	 */
	private function readUploadBody(Folder $target) {
		$limit = self::MAX_OBJECT_BYTES;
		$quotaBound = false;
		try {
			$free = $target->getFreeSpace();
		} catch (\Throwable) {
			$free = false;
		}
		// Negative values mean unknown or unlimited.
		if ((is_int($free) || is_float($free)) && $free >= 0 && $free < $limit) {
			$limit = (int)$free;
			$quotaBound = true;
		}

		$hash = new BodyHash(PayloadIntegrity::algorithms($this->request, true));
		$trailers = [];
		try {
			$stream = $this->requestBody->toStream($this->request, $hash, $this->chunkVerifier, $limit, $trailers);
		} catch (S3AuthException $e) {
			if ($e->getS3Code() === 'EntityTooLarge' && $quotaBound) {
				// The same answer a full quota gets from Nextcloud itself.
				throw new S3AuthException('AccessDenied', 'Not enough free space for this upload', Http::STATUS_FORBIDDEN);
			}
			throw $e;
		}

		try {
			// Unlike DeleteObjects, S3 does not require any of these on an
			// upload, so whether one binds the body does not matter here.
			PayloadIntegrity::verify(
				$this->request,
				$hash->digests(),
				$trailers,
				$this->signedHeaders,
				$this->chunkVerifier !== null,
				true,
			);
		} catch (S3AuthException $e) {
			fclose($stream);
			throw $e;
		}

		return $stream;
	}

	/**
	 * Read and parse the XML body of DeleteObjects or CompleteMultipartUpload.
	 *
	 * Through S3RequestBody like an upload, so an `aws-chunked` body is
	 * decoded and its chunk signatures checked, and bounded before anything
	 * is parsed. Then every integrity claim the request makes about the body
	 * is checked, see PayloadIntegrity. With `$mustBeBound`, as S3 requires
	 * Content-MD5 or a checksum for DeleteObjects, one of them also has to
	 * be covered by the signature: otherwise a captured request could be
	 * replayed with another body, naming other keys to delete.
	 *
	 * @param bool $checksumsDescribeBody see PayloadIntegrity::verify()
	 * @return \SimpleXMLElement|null null when the body is not acceptable XML
	 * @throws S3AuthException
	 */
	private function readXmlBody(bool $mustBeBound, bool $checksumsDescribeBody): ?\SimpleXMLElement {
		$hash = new BodyHash(PayloadIntegrity::algorithms($this->request, $checksumsDescribeBody));
		$trailers = [];
		try {
			$body = $this->requestBody->toString($this->request, self::MAX_XML_BODY_BYTES, $this->chunkVerifier, $hash, $trailers);
		} catch (S3AuthException $e) {
			if ($e->getS3Code() === 'EntityTooLarge') {
				throw new S3AuthException('MaxMessageLengthExceeded', 'Your request was too big', Http::STATUS_BAD_REQUEST);
			}
			throw $e;
		}

		$bound = PayloadIntegrity::verify(
			$this->request,
			$hash->digests(),
			$trailers,
			$this->signedHeaders,
			$this->chunkVerifier !== null,
			$checksumsDescribeBody,
		);

		if ($mustBeBound && !$bound) {
			throw new S3AuthException(
				'InvalidRequest',
				'Missing required header for this request: Content-MD5 or x-amz-checksum-*, signed, or a literal x-amz-content-sha256',
				Http::STATUS_BAD_REQUEST,
			);
		}

		return $this->parseXmlBody($body);
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
			// A folder is not an object: in S3, "a" does not exist just because
			// "a/b" does, so deleting it is a no-op. Calling delete() here would
			// move the whole tree to the trash.
			if ($node instanceof File) {
				$fileId = $node->getId();
				$node->delete();
				// Drop the recorded ETag: file ids are reused, so a stale row
				// would otherwise be served for a different object.
				$this->etagService->forget($fileId);
			}
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

		try {
			$uploadId = $this->multipartService->create(
				$bucketFolder,
				$bucket->getId(),
				$bucket->getUserId(),
				$objectKey,
			);
		} catch (S3AuthException $e) {
			return $this->errorResponse($e->getS3Code(), $e->getMessage(), '/' . $bucketName . '/' . $objectKey, $e->getHttpStatus());
		}

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

			// Parts are stored in the bucket's scratch area, so the bucket's
			// free space is what bounds them.
			$stream = $this->readUploadBody($bucketFolder);

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

			// S3 does not require a checksum here, and SDKs do not all send
			// one; whatever the request does claim about its body is checked.
			$requested = $this->parseCompleteBody($this->readXmlBody(false, false));
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

	/**
	 * ListParts, a page at a time (`max-parts`, `part-number-marker`).
	 *
	 * Needs a readwrite key, like every other multipart operation: uploads
	 * in flight are the writers' business, and on S3 listing them is a
	 * permission of its own that a read-only policy does not grant.
	 *
	 * @param array<string, string> $query
	 */
	private function listParts(
		$bucket,
		Folder $bucketFolder,
		string $bucketName,
		string $objectKey,
		string $uploadId,
		array $query,
		string $permission,
	): Response {
		$resource = '/' . $bucketName . '/' . $objectKey;

		if ($permission !== 'readwrite') {
			return $this->errorResponse('AccessDenied', 'Access Denied', $resource, Http::STATUS_FORBIDDEN);
		}

		$marker = $this->intParam($query, 'part-number-marker', 0);
		$maxParts = $this->pageSize($query, 'max-parts');

		try {
			$this->multipartService->get($uploadId, $bucket->getId(), $objectKey);
			$parts = $this->multipartService->listParts($bucketFolder, $uploadId);
		} catch (S3AuthException $e) {
			return $this->errorResponse($e->getS3Code(), $e->getMessage(), $resource, $e->getHttpStatus());
		}

		$parts = array_values(array_filter($parts, static fn(array $part): bool => $part['number'] > $marker));
		$truncated = count($parts) > $maxParts;
		$parts = array_slice($parts, 0, $maxParts);

		return $this->xmlResponse(
			$this->responseBuilder->listPartsXml($bucketName, $objectKey, $uploadId, $parts, $marker, $maxParts, $truncated),
		);
	}

	/**
	 * ListMultipartUploads, a page at a time (`max-uploads`, `key-marker`,
	 * `upload-id-marker`, `prefix`). Readwrite only, as ListParts.
	 *
	 * @param array<string, string> $query
	 */
	private function listMultipartUploads($bucket, string $bucketName, array $query, string $permission): Response {
		if ($permission !== 'readwrite') {
			return $this->errorResponse('AccessDenied', 'Access Denied', '/' . $bucketName, Http::STATUS_FORBIDDEN);
		}

		$prefix = $query['prefix'] ?? '';
		$keyMarker = $query['key-marker'] ?? '';
		// Without a key marker S3 ignores the upload id marker.
		$uploadIdMarker = $keyMarker === '' ? '' : ($query['upload-id-marker'] ?? '');
		$maxUploads = $this->pageSize($query, 'max-uploads');

		$uploads = $this->multipartService->listUploads($bucket->getId(), $prefix, $keyMarker, $uploadIdMarker, $maxUploads);
		$truncated = count($uploads) > $maxUploads;
		$uploads = array_slice($uploads, 0, $maxUploads);

		return $this->xmlResponse(
			$this->responseBuilder->listMultipartUploadsXml($bucketName, $uploads, $prefix, $keyMarker, $uploadIdMarker, $maxUploads, $truncated),
		);
	}

	/**
	 * A non-negative integer query parameter, or `$default`.
	 *
	 * @param array<string, string> $query
	 */
	private function intParam(array $query, string $name, int $default): int {
		$raw = $query[$name] ?? '';
		return ctype_digit($raw) && strlen($raw) < 10 ? (int)$raw : $default;
	}

	/**
	 * A page size as S3 takes it: 1000 by default and at most.
	 *
	 * @param array<string, string> $query
	 */
	private function pageSize(array $query, string $name): int {
		$size = $this->intParam($query, $name, 1000);
		return $size < 1 ? 1000 : min($size, 1000);
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
	 * @param \SimpleXMLElement|null $doc the parsed body, null if it did not
	 *        parse
	 * @return array<int, array{number: int, etag: string}>|null null when the
	 *         XML cannot be parsed or names more parts than an upload can have
	 */
	private function parseCompleteBody(?\SimpleXMLElement $doc): ?array {
		if ($doc === null || count($doc->Part) > self::MAX_COMPLETE_PARTS) {
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

	/**
	 * Parse an XML request body, or return null when it is malformed or
	 * declares a DTD.
	 *
	 * The body is untrusted. Entity substitution stays off — `LIBXML_NOENT`,
	 * despite its name, switches it *on*, and with it libxml loads external
	 * entities (`file://`, `php://filter`). Inside Nextcloud that is only
	 * stopped by the null entity loader core installs at startup; outside of
	 * it, those flags read local files into whatever the document says.
	 *
	 * Turning substitution off is not enough on its own: SimpleXML still
	 * expands *internal* entities when an element is cast to a string. So a
	 * document that declares a DTD at all is refused. No S3 request carries
	 * one, and without a DTD there is no entity to expand, internal or
	 * external, whatever libxml's or Nextcloud's defaults are.
	 *
	 * The DTD is looked up on the parsed tree rather than by searching the raw
	 * bytes for `<!DOCTYPE`, which a UTF-16 body would slip past. Parsing it
	 * first is harmless: without `LIBXML_NOENT` or `LIBXML_DTDLOAD` libxml
	 * fetches nothing, and nothing is expanded until a value is read.
	 */
	private function parseXmlBody(string $xml): ?\SimpleXMLElement {
		if (trim($xml) === '') {
			return null;
		}

		$previous = libxml_use_internal_errors(true);
		try {
			$doc = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NONET);
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors($previous);
		}

		if ($doc === false) {
			return null;
		}

		if (dom_import_simplexml($doc)->ownerDocument?->doctype !== null) {
			return null;
		}

		return $doc;
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

		if ($this->isSameFile($bucketFolder, $objectKey, $source)) {
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
	 * Whether `$objectKey` names the file `$source` is.
	 *
	 * Decided by file id, not by comparing the keys: two spellings can reach
	 * one file -- on a case-insensitive storage such as an SMB mount, `A` and
	 * `a` do -- and a string comparison would let such a copy open the
	 * source for reading and then truncate it for writing.
	 */
	private function isSameFile(Folder $bucketFolder, string $objectKey, File $source): bool {
		try {
			$target = $bucketFolder->get($objectKey);
		} catch (NotFoundException) {
			return false;
		}

		return $target->getId() === $source->getId();
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
	 *
	 * The source key gets the same validation as a key in the request line:
	 * it is resolved to a path just the same, where `a//b` and `a/./b` would
	 * read `a/b`, and `.s3-uploads/…` the parts of an upload in progress.
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

		return $this->isUsableKey($key) ? $key : null;
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

		try {
			$out = $this->tempFiles->open();
		} catch (S3AuthException $e) {
			fclose($handle);
			throw $e;
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

		try {
			$doc = $this->readXmlBody(true, true);
		} catch (S3AuthException $e) {
			return $this->errorResponse($e->getS3Code(), $e->getMessage(), $resource, $e->getHttpStatus());
		}
		if ($doc === null) {
			return $this->errorResponse('MalformedXML', 'The XML you provided was not well-formed', $resource, Http::STATUS_BAD_REQUEST);
		}

		// As on S3. Each key is a filesystem operation within one request, so
		// without a bound a single body could keep PHP busy indefinitely.
		if (count($doc->Object) > self::MAX_DELETE_KEYS) {
			return $this->errorResponse('MalformedXML', 'A delete request may name at most ' . self::MAX_DELETE_KEYS . ' keys', $resource, Http::STATUS_BAD_REQUEST);
		}

		$quiet = strtolower(trim((string)$doc->Quiet)) === 'true';

		$deleted = [];
		$errors = [];
		foreach ($doc->Object as $object) {
			$key = (string)$object->Key;
			if ($key === '') {
				continue;
			}

			// Keys arriving in the request body get the same validation as keys
			// in the request line. Nextcloud's file API is what actually stops a
			// traversal, but a rejected key belongs in the per-key error list
			// rather than as a caught exception.
			if (!$this->isUsableKey($key)) {
				$errors[] = ['key' => $key, 'code' => 'InvalidArgument', 'message' => 'The specified key is not valid'];
				continue;
			}

			try {
				$node = $bucketFolder->get($key);
				// Same as DeleteObject: a folder is not an object, so it is
				// reported as deleted without touching it.
				if ($node instanceof File) {
					$fileId = $node->getId();
					$node->delete();
					$this->etagService->forget($fileId);
				}
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

		// One request can take a thousand objects with it, so it leaves a
		// trace of its own beside the per-file entries Nextcloud's audit log
		// writes anyway.
		$this->logger->info('S3 DeleteObjects', [
			'app' => 's3_api',
			'bucket' => $bucketName,
			'accessKey' => $this->accessKey,
			'deleted' => count($deleted),
			'errors' => count($errors),
		]);
		$this->eventDispatcher->dispatchTyped(new CriticalActionPerformedEvent(
			'S3 DeleteObjects in bucket "%s" with access key "%s": %s deleted, %s failed',
			[
				'bucket' => $bucketName,
				'accessKey' => $this->accessKey,
				'deleted' => (string)count($deleted),
				'errors' => (string)count($errors),
			],
		));

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
	 * An S3 error response; throttled when it answers a wrong guess.
	 *
	 * A wrong key or signature is what an attack looks like, whether in the
	 * request's signature or in one of its chunks', which only fails once
	 * the handler reads the body. Throttling here catches both. They are
	 * logged where an admin sees them, and the brute force metadata keeps
	 * the key too: the throttle itself is per address.
	 *
	 * @param bool $withBody false to omit the XML body, which a response to
	 *        HEAD must do
	 */
	private function errorResponse(string $code, string $message, string $resource, int $httpStatus, bool $withBody = true): Response {
		if ($withBody) {
			$response = $this->xmlResponse($this->responseBuilder->errorXml($code, $message, $resource), $httpStatus);
		} else {
			$response = new DataDisplayResponse('', $httpStatus);
			$response->addHeader('Content-Type', 'application/xml');
		}

		if (in_array($code, self::THROTTLED_CODES, true)) {
			$this->logger->warning('S3 authentication failed', [
				'app' => 's3_api',
				'code' => $code,
				'accessKey' => $this->accessKey,
				'remoteAddress' => $this->request->getRemoteAddress(),
			]);
			$response->throttle(['accessKey' => $this->accessKey]);
		}

		return $response;
	}
}
