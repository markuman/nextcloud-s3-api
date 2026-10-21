<?php

declare(strict_types=1);

namespace OCA\S3Api\Service;

use OCP\AppFramework\Http;
use OCP\ITempManager;

/**
 * Scratch streams for request bodies, assembled uploads and copy slices.
 *
 * These used to be `php://temp`, which spills to the system temp directory
 * past its memory limit — ignoring the `tempdirectory` an admin configured
 * for Nextcloud, typically because /tmp is small, a tmpfs, or not where
 * object data should sit. A whole upload passes through here.
 */
class TempFiles {

	public function __construct(
		private ITempManager $tempManager,
	) {
	}

	/**
	 * An empty read/write stream, backed by a file in Nextcloud's temp
	 * directory. The caller closes it.
	 *
	 * The file's name is removed as soon as it is open: the stream keeps
	 * working, and nothing is left behind even when the process dies before
	 * Nextcloud's own end-of-request cleanup runs.
	 *
	 * @return resource
	 * @throws S3AuthException
	 */
	public function open() {
		$path = $this->tempManager->getTemporaryFile();
		$handle = $path === false ? false : fopen($path, 'w+b');
		if ($handle === false) {
			throw new S3AuthException('InternalError', 'Cannot create a temporary file', Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		unlink($path);

		return $handle;
	}
}
