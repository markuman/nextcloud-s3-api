<?php

declare(strict_types=1);

namespace OCA\S3Api\Service;

class S3AuthException extends \Exception {

	public function __construct(
		private string $s3Code,
		string $message = '',
	) {
		parent::__construct($message);
	}

	public function getS3Code(): string {
		return $this->s3Code;
	}
}
