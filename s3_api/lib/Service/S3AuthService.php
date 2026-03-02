<?php

declare(strict_types=1);

namespace OCA\S3Api\Service;

use OCA\S3Api\Db\ApiKey;
use OCA\S3Api\Db\BucketMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IRequest;

class S3AuthService {

	public function __construct(
		private ApiKeyService $apiKeyService,
		private BucketMapper $bucketMapper,
	) {
	}

	/**
	 * @return array{userId: string, bucketId: int, permission: string}
	 * @throws S3AuthException
	 */
	public function authenticate(IRequest $request): array {
		$authHeader = $request->getHeader('Authorization');
		if (empty($authHeader)) {
			throw new S3AuthException('AccessDenied', 'Missing Authorization header');
		}

		$parsed = $this->parseAuthHeader($authHeader);
		if ($parsed === null) {
			throw new S3AuthException('AccessDenied', 'Invalid Authorization header format');
		}

		$apiKey = $this->apiKeyService->findByAccessKey($parsed['accessKey']);
		if ($apiKey === null) {
			throw new S3AuthException('InvalidAccessKeyId', 'The access key ID does not exist');
		}

		$secretKey = $this->apiKeyService->decryptSecretKey($apiKey);

		$this->verifySignature($request, $parsed, $secretKey);

		return [
			'userId' => $apiKey->getUserId(),
			'bucketId' => $apiKey->getBucketId(),
			'permission' => $apiKey->getPermission(),
			'accessKey' => $apiKey->getAccessKey(),
		];
	}

	/**
	 * @return array{accessKey: string, date: string, region: string, signedHeaders: string[], signature: string}|null
	 */
	private function parseAuthHeader(string $header): ?array {
		// AWS4-HMAC-SHA256 Credential=AKID/20260302/us-east-1/s3/aws4_request, SignedHeaders=host;x-amz-date, Signature=abc123
		if (!preg_match('/^AWS4-HMAC-SHA256\s+/', $header)) {
			return null;
		}

		$parts = substr($header, strlen('AWS4-HMAC-SHA256 '));
		$params = [];
		foreach (explode(',', $parts) as $part) {
			$part = trim($part);
			$eqPos = strpos($part, '=');
			if ($eqPos === false) {
				continue;
			}
			$key = trim(substr($part, 0, $eqPos));
			$value = trim(substr($part, $eqPos + 1));
			$params[$key] = $value;
		}

		if (!isset($params['Credential'], $params['SignedHeaders'], $params['Signature'])) {
			return null;
		}

		// Parse Credential: accessKey/date/region/s3/aws4_request
		$credParts = explode('/', $params['Credential']);
		if (count($credParts) !== 5 || $credParts[3] !== 's3' || $credParts[4] !== 'aws4_request') {
			return null;
		}

		return [
			'accessKey' => $credParts[0],
			'date' => $credParts[1],
			'region' => $credParts[2],
			'signedHeaders' => explode(';', $params['SignedHeaders']),
			'signature' => $params['Signature'],
		];
	}

	private function verifySignature(IRequest $request, array $parsed, string $secretKey): void {
		$canonicalRequest = $this->buildCanonicalRequest($request, $parsed['signedHeaders']);
		$stringToSign = $this->buildStringToSign($request, $parsed, $canonicalRequest);
		$signingKey = $this->deriveSigningKey($secretKey, $parsed['date'], $parsed['region']);
		$calculatedSignature = hash_hmac('sha256', $stringToSign, $signingKey);

		if (!hash_equals($calculatedSignature, $parsed['signature'])) {
			throw new S3AuthException('SignatureDoesNotMatch', 'The request signature does not match');
		}
	}

	private function buildCanonicalRequest(IRequest $request, array $signedHeaders): string {
		$method = $request->getMethod();

		// Get the raw request URI path and canonical-encode it
		$uri = $request->getRequestUri();
		$qPos = strpos($uri, '?');
		$path = $qPos !== false ? substr($uri, 0, $qPos) : $uri;

		// Build canonical query string
		$queryString = $this->buildCanonicalQueryString($request);

		// Build canonical headers
		$canonicalHeaders = '';
		sort($signedHeaders);
		foreach ($signedHeaders as $headerName) {
			$headerName = strtolower(trim($headerName));
			if ($headerName === 'host') {
				$value = $request->getHeader('Host');
			} else {
				$value = $request->getHeader($headerName);
			}
			$canonicalHeaders .= $headerName . ':' . trim($value) . "\n";
		}

		$signedHeadersStr = implode(';', $signedHeaders);

		// Payload hash
		$payloadHash = $request->getHeader('x-amz-content-sha256');
		if (empty($payloadHash)) {
			$payloadHash = 'UNSIGNED-PAYLOAD';
		}

		return implode("\n", [
			$method,
			$path,
			$queryString,
			$canonicalHeaders,
			$signedHeadersStr,
			$payloadHash,
		]);
	}

	private function buildCanonicalQueryString(IRequest $request): string {
		$uri = $request->getRequestUri();
		$qPos = strpos($uri, '?');
		if ($qPos === false) {
			return '';
		}

		$queryStr = substr($uri, $qPos + 1);
		parse_str($queryStr, $params);

		// Sort by key
		ksort($params);

		$parts = [];
		foreach ($params as $key => $value) {
			$parts[] = rawurlencode((string)$key) . '=' . rawurlencode((string)$value);
		}

		return implode('&', $parts);
	}

	private function buildStringToSign(IRequest $request, array $parsed, string $canonicalRequest): string {
		$amzDate = $request->getHeader('x-amz-date');
		if (empty($amzDate)) {
			$amzDate = $request->getHeader('Date');
		}

		$scope = $parsed['date'] . '/' . $parsed['region'] . '/s3/aws4_request';

		return implode("\n", [
			'AWS4-HMAC-SHA256',
			$amzDate,
			$scope,
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
