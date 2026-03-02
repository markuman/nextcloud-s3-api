<?php

declare(strict_types=1);

namespace OCA\S3Api\Settings;

use OCA\S3Api\AppInfo\Application;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

class PersonalSection implements IIconSection {

	public function __construct(
		private IURLGenerator $urlGenerator,
		private IL10N $l,
	) {
	}

	public function getID(): string {
		return 's3_api';
	}

	public function getName(): string {
		return $this->l->t('S3 API');
	}

	public function getPriority(): int {
		return 90;
	}

	public function getIcon(): string {
		return $this->urlGenerator->imagePath(Application::APP_ID, 'app.svg');
	}
}
