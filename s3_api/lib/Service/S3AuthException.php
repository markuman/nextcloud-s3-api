<?php

declare(strict_types=1);

namespace OCA\S3Api\Service;

use OCP\AppFramework\Http;

class S3AuthException extends \Exception {

	/** The access key the failed request named, when it got that far. */
	private ?string $accessKey = null;

	public function __construct(
		private string $s3Code,
		string $message = '',
		private int $httpStatus = Http::STATUS_FORBIDDEN,
	) {
		parent::__construct($message);
	}

	public function getS3Code(): string {
		return $this->s3Code;
	}

	/**
	 * HTTP status to answer with. Authentication failures default to 403;
	 * malformed request bodies and similar client errors pass their own.
	 */
	public function getHttpStatus(): int {
		return $this->httpStatus;
	}

	public function getAccessKey(): ?string {
		return $this->accessKey;
	}

	/**
	 * Record which access key the request claimed, for the log and the brute
	 * force metadata. Only the shape of an access key is kept: the value is
	 * whatever the client sent.
	 */
	public function setAccessKey(string $accessKey): void {
		$this->accessKey = preg_match('/^[A-Za-z0-9]{1,64}$/', $accessKey) === 1 ? $accessKey : '(malformed)';
	}
}
