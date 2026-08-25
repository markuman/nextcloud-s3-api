<?php

declare(strict_types=1);

namespace OCA\S3Api\Service;

use OCA\S3Api\Db\BucketMapper;
use OCP\AppFramework\Http;
use OCP\IRequest;

/**
 * AWS Signature Version 4 verification.
 *
 * Both flavours are accepted:
 *
 * - **Header auth**: credentials in `Authorization`, used for most requests.
 * - **Query auth (presigned URL)**: credentials in `X-Amz-*` query parameters.
 *   Some SDKs issue *every* GET as a presigned URL, so rejecting these makes
 *   the endpoint unusable for reads.
 */
class S3AuthService {

	private const ALGORITHM = 'AWS4-HMAC-SHA256';

	/** Accepted clock skew, matching AWS. */
	private const MAX_SKEW_SECONDS = 900;

	public function __construct(
		private ApiKeyService $apiKeyService,
		private BucketMapper $bucketMapper,
	) {
	}

	/**
	 * @return array{userId: string, bucketId: int, permission: string, accessKey: string}
	 * @throws S3AuthException
	 */
	public function authenticate(IRequest $request): array {
		$query = $this->queryParams($request);

		$parsed = isset($query['X-Amz-Signature'])
			? $this->parseQueryAuth($query)
			: $this->parseHeaderAuth((string)$request->getHeader('Authorization'));

		$apiKey = $this->apiKeyService->findByAccessKey($parsed['accessKey']);
		if ($apiKey === null) {
			throw new S3AuthException('InvalidAccessKeyId', 'The access key ID you provided does not exist in our records');
		}

		$this->assertFreshness($request, $parsed);

		$secretKey = $this->apiKeyService->decryptSecretKey($apiKey);
		$this->verifySignature($request, $parsed, $secretKey, $query);

		return [
			'userId' => $apiKey->getUserId(),
			'bucketId' => $apiKey->getBucketId(),
			'permission' => $apiKey->getPermission(),
			'accessKey' => $apiKey->getAccessKey(),
		];
	}

	/**
	 * Parse the raw query string ourselves.
	 *
	 * `parse_str()` is unusable here: it rewrites `.` and space in keys, drops
	 * repeated keys and cannot represent a valueless parameter such as
	 * `?acl`, all of which change the canonical request and therefore the
	 * signature.
	 *
	 * @return array<string, string> decoded keys mapped to decoded values
	 */
	private function queryParams(IRequest $request): array {
		$uri = $request->getRequestUri();
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
	 * @return array{accessKey: string, date: string, region: string, signedHeaders: string[], signature: string, presigned: bool, amzDate: string, expires: ?int}
	 * @throws S3AuthException
	 */
	private function parseHeaderAuth(string $header): array {
		if ($header === '') {
			throw new S3AuthException('AccessDenied', 'Missing Authorization header');
		}

		if (!str_starts_with($header, self::ALGORITHM . ' ')) {
			throw new S3AuthException('AccessDenied', 'Unsupported authorization algorithm');
		}

		$params = [];
		foreach (explode(',', substr($header, strlen(self::ALGORITHM) + 1)) as $part) {
			$part = trim($part);
			$eq = strpos($part, '=');
			if ($eq === false) {
				continue;
			}
			$params[trim(substr($part, 0, $eq))] = trim(substr($part, $eq + 1));
		}

		if (!isset($params['Credential'], $params['SignedHeaders'], $params['Signature'])) {
			throw new S3AuthException('AccessDenied', 'Incomplete Authorization header');
		}

		$scope = $this->parseCredential($params['Credential']);

		return [
			'accessKey' => $scope['accessKey'],
			'date' => $scope['date'],
			'region' => $scope['region'],
			'signedHeaders' => $this->splitSignedHeaders($params['SignedHeaders']),
			'signature' => $params['Signature'],
			'presigned' => false,
			'amzDate' => '',
			'expires' => null,
		];
	}

	/**
	 * @param array<string, string> $query
	 * @return array{accessKey: string, date: string, region: string, signedHeaders: string[], signature: string, presigned: bool, amzDate: string, expires: ?int}
	 * @throws S3AuthException
	 */
	private function parseQueryAuth(array $query): array {
		$algorithm = $query['X-Amz-Algorithm'] ?? '';
		if ($algorithm !== self::ALGORITHM) {
			throw new S3AuthException('AccessDenied', 'Unsupported authorization algorithm');
		}

		foreach (['X-Amz-Credential', 'X-Amz-SignedHeaders', 'X-Amz-Signature', 'X-Amz-Date'] as $required) {
			if (!isset($query[$required]) || $query[$required] === '') {
				throw new S3AuthException('AccessDenied', 'Incomplete presigned request');
			}
		}

		$scope = $this->parseCredential($query['X-Amz-Credential']);

		$expires = null;
		if (isset($query['X-Amz-Expires']) && ctype_digit($query['X-Amz-Expires'])) {
			$expires = (int)$query['X-Amz-Expires'];
		}

		return [
			'accessKey' => $scope['accessKey'],
			'date' => $scope['date'],
			'region' => $scope['region'],
			'signedHeaders' => $this->splitSignedHeaders($query['X-Amz-SignedHeaders']),
			'signature' => $query['X-Amz-Signature'],
			'presigned' => true,
			'amzDate' => $query['X-Amz-Date'],
			'expires' => $expires,
		];
	}

	/**
	 * @return array{accessKey: string, date: string, region: string}
	 * @throws S3AuthException
	 */
	private function parseCredential(string $credential): array {
		$parts = explode('/', $credential);
		if (count($parts) !== 5 || $parts[3] !== 's3' || $parts[4] !== 'aws4_request') {
			throw new S3AuthException('AccessDenied', 'Malformed credential scope');
		}

		return [
			'accessKey' => $parts[0],
			'date' => $parts[1],
			'region' => $parts[2],
		];
	}

	/**
	 * @return string[]
	 */
	private function splitSignedHeaders(string $value): array {
		$headers = [];
		foreach (explode(';', $value) as $name) {
			$name = strtolower(trim($name));
			if ($name !== '') {
				$headers[] = $name;
			}
		}
		sort($headers);
		return $headers;
	}

	/**
	 * Reject stale requests, so a captured signature cannot be replayed
	 * indefinitely.
	 *
	 * @param array{date: string, presigned: bool, amzDate: string, expires: ?int} $parsed
	 * @throws S3AuthException
	 */
	private function assertFreshness(IRequest $request, array $parsed): void {
		$amzDate = $parsed['presigned']
			? $parsed['amzDate']
			: (string)$request->getHeader('x-amz-date');

		if ($amzDate === '') {
			// Signed with a plain `Date` header; nothing precise to compare.
			return;
		}

		$timestamp = \DateTimeImmutable::createFromFormat('Ymd\THis\Z', $amzDate, new \DateTimeZone('UTC'));
		if ($timestamp === false) {
			throw new S3AuthException('AccessDenied', 'Malformed x-amz-date');
		}

		// The date in the credential scope must agree with the timestamp,
		// otherwise the scope could be reused across days.
		if ($timestamp->format('Ymd') !== $parsed['date']) {
			throw new S3AuthException('AccessDenied', 'Credential scope date does not match request date');
		}

		$now = time();
		$signedAt = $timestamp->getTimestamp();

		if ($parsed['presigned'] && $parsed['expires'] !== null) {
			if ($now > $signedAt + $parsed['expires']) {
				throw new S3AuthException('AccessDenied', 'Request has expired');
			}
			// Tolerate a clock that is behind the signer's.
			if ($signedAt - $now > self::MAX_SKEW_SECONDS) {
				throw new S3AuthException('AccessDenied', 'Request is not yet valid');
			}
			return;
		}

		if (abs($now - $signedAt) > self::MAX_SKEW_SECONDS) {
			throw new S3AuthException(
				'RequestTimeTooSkewed',
				'The difference between the request time and the current time is too large',
			);
		}
	}

	/**
	 * @param array<string, string> $query
	 * @throws S3AuthException
	 */
	private function verifySignature(IRequest $request, array $parsed, string $secretKey, array $query): void {
		$canonicalRequest = $this->buildCanonicalRequest($request, $parsed, $query);
		$stringToSign = $this->buildStringToSign($request, $parsed, $canonicalRequest);
		$signingKey = $this->deriveSigningKey($secretKey, $parsed['date'], $parsed['region']);
		$expected = hash_hmac('sha256', $stringToSign, $signingKey);

		if (!hash_equals($expected, strtolower($parsed['signature']))) {
			throw new S3AuthException(
				'SignatureDoesNotMatch',
				'The request signature we calculated does not match the signature you provided',
			);
		}
	}

	/**
	 * @param array<string, string> $query
	 */
	private function buildCanonicalRequest(IRequest $request, array $parsed, array $query): string {
		$uri = $request->getRequestUri();
		$qPos = strpos($uri, '?');
		$path = $qPos !== false ? substr($uri, 0, $qPos) : $uri;

		return implode("\n", [
			$request->getMethod(),
			$this->canonicalUri($path),
			$this->canonicalQueryString($query, $parsed['presigned']),
			$this->canonicalHeaders($request, $parsed['signedHeaders']),
			implode(';', $parsed['signedHeaders']),
			$this->payloadHash($request),
		]);
	}

	/**
	 * Normalise the URI path.
	 *
	 * The path arrives percent-encoded; decoding and re-encoding per segment
	 * yields the form AWS signs, and keeps `/` as the key separator.
	 */
	private function canonicalUri(string $path): string {
		if ($path === '') {
			return '/';
		}

		$segments = explode('/', $path);
		$encoded = array_map(
			static fn(string $segment): string => rawurlencode(rawurldecode($segment)),
			$segments,
		);

		return implode('/', $encoded);
	}

	/**
	 * @param array<string, string> $query
	 */
	private function canonicalQueryString(array $query, bool $presigned): string {
		if ($presigned) {
			// The signature itself is never part of what was signed.
			unset($query['X-Amz-Signature']);
		}

		$parts = [];
		foreach ($query as $key => $value) {
			$parts[] = rawurlencode($key) . '=' . rawurlencode($value);
		}
		sort($parts);

		return implode('&', $parts);
	}

	/**
	 * @param string[] $signedHeaders
	 */
	private function canonicalHeaders(IRequest $request, array $signedHeaders): string {
		$canonical = '';
		foreach ($signedHeaders as $name) {
			$canonical .= $name . ':' . $this->headerValue($request, $name) . "\n";
		}
		return $canonical;
	}

	private function headerValue(IRequest $request, string $name): string {
		$value = (string)$request->getHeader($name);

		if ($name === 'host' && $value === '') {
			$value = (string)$request->getHeader('Host');
		}

		// Sequential whitespace collapses to a single space when signing.
		return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
	}

	/**
	 * The payload hash as it appears in the canonical request.
	 *
	 * Whatever the client put in `x-amz-content-sha256` is what it signed, so
	 * it is used verbatim -- including the `STREAMING-*` sentinels. Verifying
	 * that a literal hash matches the body is the request handler's job, once
	 * the body has been decoded.
	 */
	private function payloadHash(IRequest $request): string {
		$hash = (string)$request->getHeader('x-amz-content-sha256');

		// Presigned URLs normally omit the header entirely.
		return $hash !== '' ? $hash : 'UNSIGNED-PAYLOAD';
	}

	private function buildStringToSign(IRequest $request, array $parsed, string $canonicalRequest): string {
		$amzDate = $parsed['presigned']
			? $parsed['amzDate']
			: (string)$request->getHeader('x-amz-date');

		if ($amzDate === '') {
			$amzDate = (string)$request->getHeader('Date');
		}

		return implode("\n", [
			self::ALGORITHM,
			$amzDate,
			$parsed['date'] . '/' . $parsed['region'] . '/s3/aws4_request',
			hash('sha256', $canonicalRequest),
		]);
	}

	private function deriveSigningKey(string $secretKey, string $date, string $region): string {
		$dateKey = hash_hmac('sha256', $date, 'AWS4' . $secretKey, true);
		$regionKey = hash_hmac('sha256', $region, $dateKey, true);
		$serviceKey = hash_hmac('sha256', 's3', $regionKey, true);
		return hash_hmac('sha256', 'aws4_request', $serviceKey, true);
	}
}
