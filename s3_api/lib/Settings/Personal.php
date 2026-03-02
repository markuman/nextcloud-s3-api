<?php

declare(strict_types=1);

namespace OCA\S3Api\Settings;

use OCA\S3Api\AppInfo\Application;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\ISettings;

class Personal implements ISettings {

	public function getForm(): TemplateResponse {
		return new TemplateResponse(Application::APP_ID, 'personal');
	}

	public function getSection(): string {
		return 's3_api';
	}

	public function getPriority(): int {
		return 10;
	}
}
