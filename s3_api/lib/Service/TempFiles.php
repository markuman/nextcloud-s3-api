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

	/**
	 * Bytes free in the temp directory, or null when that cannot be told.
	 *
	 * Checked before a copy of known size goes there: the bucket owner's
	 * quota says nothing about this directory, which every user of the
	 * instance shares.
	 */
	public function freeSpace(): ?int {
		$free = @disk_free_space($this->tempManager->getTempBaseDir());
		return $free === false ? null : (int)$free;
	}

	/**
	 * @throws S3AuthException when `$bytes` will not fit into the temp
	 *         directory
	 */
	public function assertRoomFor(int|float $bytes): void {
		$free = $this->freeSpace();
		if ($free !== null && $bytes > $free) {
			throw new S3AuthException(
				'EntityTooLarge',
				'Not enough temporary space on the server for this request',
				Http::STATUS_BAD_REQUEST,
			);
		}
	}

	/**
	 * fwrite() all of `$data`, or fail.
	 *
	 * fwrite() reports a short write -- a full disk -- as a smaller count,
	 * not as false, and a check for false alone lets the copy carry on with
	 * bytes missing.
	 *
	 * @param resource $out
	 * @throws S3AuthException
	 */
	public static function writeAll($out, string $data): void {
		$length = strlen($data);
		$written = 0;
		while ($written < $length) {
			$n = fwrite($out, $written === 0 ? $data : substr($data, $written));
			if ($n === false || $n === 0) {
				throw new S3AuthException('InternalError', 'Could not write to a temporary file', Http::STATUS_INTERNAL_SERVER_ERROR);
			}
			$written += $n;
		}
	}
}
