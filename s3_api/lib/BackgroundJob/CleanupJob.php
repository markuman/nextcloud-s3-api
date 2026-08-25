<?php

declare(strict_types=1);

namespace OCA\S3Api\BackgroundJob;

use OCA\S3Api\Service\CleanupService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Removes what aborted requests leave behind: multipart uploads nobody
 * completed, lock rows from processes that died mid-write, and recorded ETags
 * whose file is gone.
 */
class CleanupJob extends TimedJob {

	public function __construct(
		ITimeFactory $time,
		private CleanupService $cleanup,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);

		$this->setInterval(6 * 3600);
		// Nothing here is urgent; it should never displace user-facing work.
		$this->setTimeSensitivity(IJob::TIME_INSENSITIVE);
	}

	protected function run($argument): void {
		try {
			$removed = $this->cleanup->run();
		} catch (\Throwable $e) {
			$this->logger->error('S3 API cleanup failed', [
				'app' => 's3_api',
				'exception' => $e,
			]);
			return;
		}

		if (array_sum($removed) > 0) {
			$this->logger->info('S3 API cleanup removed stale records', [
				'app' => 's3_api',
				'uploads' => $removed['uploads'],
				'locks' => $removed['locks'],
				'etags' => $removed['etags'],
			]);
		}
	}
}
