<?php

declare(strict_types=1);

namespace OCA\S3Api\AppInfo;

use OCP\AppFramework\App;

class Application extends App {
	public const APP_ID = 's3_api';

	public function __construct() {
		parent::__construct(self::APP_ID);
	}
}
