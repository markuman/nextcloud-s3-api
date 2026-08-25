<?php

declare(strict_types=1);

namespace OCA\S3Api\Service;

use OCP\AppFramework\Http;

class S3AuthException extends \Exception {

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
}
