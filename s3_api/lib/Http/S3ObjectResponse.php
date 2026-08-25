<?php

declare(strict_types=1);

namespace OCA\S3Api\Http;

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\ICallbackResponse;
use OCP\AppFramework\Http\IOutput;
use OCP\AppFramework\Http\Response;
use OCP\Files\File;

/**
 * Streams an object's bytes, optionally a byte range, without loading it into
 * memory.
 *
 * Implemented as an ICallbackResponse for two reasons:
 *
 * 1. The app framework replaces `Content-Length` with `strlen($output)` for
 *    ordinary responses. A HEAD reply carries no body, so the header would be
 *    forced to 0 and clients would believe every object is empty. Callback
 *    responses are exempt from that rewrite.
 * 2. Reading a multi-gigabyte object into a string to hand it to the framework
 *    is not viable; here the bytes are copied straight to the output stream.
 */
class S3ObjectResponse extends Response implements ICallbackResponse {

	private const CHUNK = 1024 * 1024;

	/**
	 * @param File $file the object to serve
	 * @param bool $includeBody false for HEAD, where only headers are sent
	 * @param int|null $rangeStart first byte to send, or null for the whole object
	 * @param int|null $rangeEnd last byte to send (inclusive)
	 */
	public function __construct(
		private File $file,
		string $etag,
		private bool $includeBody = true,
		private ?int $rangeStart = null,
		private ?int $rangeEnd = null,
	) {
		parent::__construct();

		$size = $file->getSize();
		$isRange = $rangeStart !== null && $rangeEnd !== null;

		$this->setStatus($isRange ? Http::STATUS_PARTIAL_CONTENT : Http::STATUS_OK);

		$length = $isRange ? ($rangeEnd - $rangeStart + 1) : $size;

		$this->addHeader('Content-Type', $file->getMimeType());
		$this->addHeader('Content-Length', (string)$length);
		$this->addHeader('ETag', '"' . $etag . '"');
		$this->addHeader('Last-Modified', gmdate('D, d M Y H:i:s \G\M\T', $file->getMTime()));
		$this->addHeader('Accept-Ranges', 'bytes');

		if ($isRange) {
			$this->addHeader('Content-Range', 'bytes ' . $rangeStart . '-' . $rangeEnd . '/' . $size);
		}
	}

	public function callback(IOutput $output): void {
		if (!$this->includeBody) {
			return;
		}

		$handle = $this->file->fopen('rb');
		if ($handle === false) {
			return;
		}

		try {
			if ($this->rangeStart !== null && $this->rangeEnd !== null) {
				$this->copyRange($handle, $this->rangeStart, $this->rangeEnd);
			} else {
				while (!feof($handle)) {
					$buf = fread($handle, self::CHUNK);
					if ($buf === false) {
						break;
					}
					echo $buf;
					flush();
				}
			}
		} finally {
			fclose($handle);
		}
	}

	/**
	 * Emit `[$start, $end]` inclusive.
	 *
	 * @param resource $handle
	 */
	private function copyRange($handle, int $start, int $end): void {
		if ($start > 0 && fseek($handle, $start) !== 0) {
			// Seeking failed (some storages only stream): skip forward.
			$skipped = 0;
			while ($skipped < $start && !feof($handle)) {
				$buf = fread($handle, (int)min(self::CHUNK, $start - $skipped));
				if ($buf === false || $buf === '') {
					return;
				}
				$skipped += strlen($buf);
			}
		}

		$remaining = $end - $start + 1;
		while ($remaining > 0 && !feof($handle)) {
			$buf = fread($handle, (int)min(self::CHUNK, $remaining));
			if ($buf === false || $buf === '') {
				break;
			}
			echo $buf;
			flush();
			$remaining -= strlen($buf);
		}
	}
}
