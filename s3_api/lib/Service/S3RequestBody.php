<?php

declare(strict_types=1);

namespace OCA\S3Api\Service;

use OCP\AppFramework\Http;
use OCP\IRequest;

/**
 * Reads a request body as a stream, transparently decoding the
 * `aws-chunked` transfer encoding used by the AWS SDKs.
 *
 * Modern AWS SDKs (and the CLI) default to sending streaming uploads with
 * flexible checksums, which means the body is not the object content but a
 * framed representation of it:
 *
 *     <hex-size>[;chunk-signature=...]\r\n
 *     <size bytes of payload>\r\n
 *     ...
 *     0[;chunk-signature=...]\r\n
 *     [trailer-name:value\r\n]*
 *     \r\n
 *
 * Writing that framing verbatim into a file silently corrupts every upload,
 * so the framing has to be stripped. Two variants exist and both are handled
 * here, distinguished by the `x-amz-content-sha256` header:
 *
 * - `STREAMING-UNSIGNED-PAYLOAD-TRAILER`: chunk sizes only, checksum in a
 *   trailer.
 * - `STREAMING-AWS4-HMAC-SHA256-PAYLOAD[-TRAILER]`: each chunk additionally
 *   carries a `chunk-signature`. The signatures are parsed and skipped but
 *   not verified; the request as a whole is still authenticated via SigV4 and
 *   the seed signature.
 *
 * Everything is streamed, so a multi-gigabyte upload never has to fit in
 * memory.
 */
class S3RequestBody {

	/** Read buffer size for body copies. */
	private const CHUNK = 1024 * 1024;

	/** Guard against absurd chunk headers from a malformed/hostile body. */
	private const MAX_CHUNK_SIZE = 1024 * 1024 * 1024;

	/** Longest plausible chunk header line, including any chunk-signature. */
	private const MAX_HEADER_LINE = 8192;

	/**
	 * Payload hash values that indicate an `aws-chunked` body.
	 */
	private const STREAMING_HASHES = [
		'STREAMING-UNSIGNED-PAYLOAD-TRAILER',
		'STREAMING-AWS4-HMAC-SHA256-PAYLOAD',
		'STREAMING-AWS4-HMAC-SHA256-PAYLOAD-TRAILER',
		'STREAMING-AWS4-ECDSA-P256-SHA256-PAYLOAD',
		'STREAMING-AWS4-ECDSA-P256-SHA256-PAYLOAD-TRAILER',
	];

	/**
	 * Whether the request body carries the `aws-chunked` framing.
	 */
	public function isChunked(IRequest $request): bool {
		$hash = strtoupper(trim((string)$request->getHeader('x-amz-content-sha256')));
		if ($hash !== '' && in_array($hash, self::STREAMING_HASHES, true)) {
			return true;
		}

		// Fall back to the transfer encoding declared by the SDK.
		$encoding = strtolower((string)$request->getHeader('content-encoding'));
		return $encoding !== '' && str_contains($encoding, 'aws-chunked');
	}

	/**
	 * The object's real byte size, if the client told us.
	 *
	 * For `aws-chunked` bodies `Content-Length` describes the framed body, so
	 * `x-amz-decoded-content-length` is the only meaningful size.
	 */
	public function getDecodedLength(IRequest $request): ?int {
		$decoded = $request->getHeader('x-amz-decoded-content-length');
		if ($decoded !== '' && ctype_digit($decoded)) {
			return (int)$decoded;
		}

		if (!$this->isChunked($request)) {
			$length = $request->getHeader('content-length');
			if ($length !== '' && ctype_digit($length)) {
				return (int)$length;
			}
		}

		return null;
	}

	/**
	 * Copy the request body into a fresh temporary stream, decoding the
	 * `aws-chunked` framing when present.
	 *
	 * The returned stream is rewound and owned by the caller, which must
	 * fclose() it. `$sha256` receives the hash of the *decoded* content so a
	 * caller can verify `x-amz-content-sha256` when it is a literal hash.
	 *
	 * @param resource|null $input Body handle; defaults to `php://input`.
	 * @return resource
	 * @throws S3AuthException on a malformed chunked body
	 */
	public function toStream(IRequest $request, &$sha256 = null, $input = null) {
		$ownsInput = false;
		if ($input === null) {
			$input = fopen('php://input', 'rb');
			$ownsInput = true;
		}
		if ($input === false) {
			throw new S3AuthException('InternalError', 'Cannot read request body', Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		$out = fopen('php://temp/maxmemory:' . (8 * 1024 * 1024), 'w+b');
		if ($out === false) {
			if ($ownsInput) {
				fclose($input);
			}
			throw new S3AuthException('InternalError', 'Cannot buffer request body', Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		$hashCtx = hash_init('sha256');

		try {
			if ($this->isChunked($request)) {
				$this->copyChunked($input, $out, $hashCtx);
			} else {
				$this->copyRaw($input, $out, $hashCtx);
			}
		} catch (\Throwable $e) {
			fclose($out);
			if ($ownsInput) {
				fclose($input);
			}
			throw $e;
		}

		if ($ownsInput) {
			fclose($input);
		}

		$sha256 = hash_final($hashCtx);
		rewind($out);
		return $out;
	}

	/**
	 * @param resource $in
	 * @param resource $out
	 * @param \HashContext $hashCtx
	 */
	private function copyRaw($in, $out, \HashContext $hashCtx): void {
		while (!feof($in)) {
			$buf = fread($in, self::CHUNK);
			if ($buf === false) {
				break;
			}
			if ($buf === '') {
				continue;
			}
			hash_update($hashCtx, $buf);
			if (fwrite($out, $buf) === false) {
				throw new S3AuthException('InternalError', 'Failed writing request body', Http::STATUS_INTERNAL_SERVER_ERROR);
			}
		}
	}

	/**
	 * Decode `aws-chunked` framing from `$in` into `$out`.
	 *
	 * @param resource $in
	 * @param resource $out
	 * @param \HashContext $hashCtx
	 */
	private function copyChunked($in, $out, \HashContext $hashCtx): void {
		while (true) {
			$line = $this->readLine($in);
			if ($line === null) {
				// Body ended without a terminating zero-length chunk. Accept it:
				// the payload we decoded so far is complete for every SDK we
				// have seen, and rejecting would turn a working upload into a
				// failure.
				return;
			}

			$line = trim($line);
			if ($line === '') {
				// Tolerate stray blank lines between chunks.
				continue;
			}

			// "<hex-size>" optionally followed by ";chunk-signature=..." or
			// other chunk extensions.
			$semicolon = strpos($line, ';');
			$sizeHex = $semicolon === false ? $line : substr($line, 0, $semicolon);
			$sizeHex = trim($sizeHex);

			if ($sizeHex === '' || !ctype_xdigit($sizeHex)) {
				throw new S3AuthException(
					'InvalidRequest',
					'Malformed aws-chunked body: bad chunk size',
					Http::STATUS_BAD_REQUEST,
				);
			}

			$size = (int)hexdec($sizeHex);
			if ($size < 0 || $size > self::MAX_CHUNK_SIZE) {
				throw new S3AuthException(
					'InvalidRequest',
					'Malformed aws-chunked body: chunk size out of range',
					Http::STATUS_BAD_REQUEST,
				);
			}

			if ($size === 0) {
				// Final chunk: trailers (if any) follow, none of which belong
				// to the object content.
				return;
			}

			$this->copyExactly($in, $out, $hashCtx, $size);

			// Each chunk's payload is followed by CRLF.
			$this->consumeCrlf($in);
		}
	}

	/**
	 * Copy exactly `$size` bytes, failing on a truncated body.
	 *
	 * @param resource $in
	 * @param resource $out
	 * @param \HashContext $hashCtx
	 */
	private function copyExactly($in, $out, \HashContext $hashCtx, int $size): void {
		$remaining = $size;
		while ($remaining > 0) {
			$buf = fread($in, (int)min($remaining, self::CHUNK));
			if ($buf === false || $buf === '') {
				if (feof($in)) {
					throw new S3AuthException(
						'IncompleteBody',
						'aws-chunked body ended mid-chunk',
						Http::STATUS_BAD_REQUEST,
					);
				}
				continue;
			}
			hash_update($hashCtx, $buf);
			if (fwrite($out, $buf) === false) {
				throw new S3AuthException('InternalError', 'Failed writing request body', Http::STATUS_INTERNAL_SERVER_ERROR);
			}
			$remaining -= strlen($buf);
		}
	}

	/**
	 * Read one CRLF-terminated line, without the terminator.
	 *
	 * `fgets()` is not used because it would happily swallow a megabyte of
	 * binary payload if a chunk header were malformed.
	 *
	 * @param resource $in
	 */
	private function readLine($in): ?string {
		$line = '';
		while (strlen($line) < self::MAX_HEADER_LINE) {
			$c = fread($in, 1);
			if ($c === false || $c === '') {
				if ($line === '') {
					return null;
				}
				return $line;
			}
			if ($c === "\n") {
				return rtrim($line, "\r");
			}
			$line .= $c;
		}

		throw new S3AuthException(
			'InvalidRequest',
			'Malformed aws-chunked body: chunk header too long',
			Http::STATUS_BAD_REQUEST,
		);
	}

	/**
	 * Consume the CRLF that terminates a chunk payload.
	 *
	 * @param resource $in
	 */
	private function consumeCrlf($in): void {
		$seen = 0;
		while ($seen < 2) {
			$c = fread($in, 1);
			if ($c === false || $c === '') {
				return;
			}
			if ($c === "\n") {
				return;
			}
			if ($c !== "\r") {
				// Not a terminator after all: the body is misframed.
				throw new S3AuthException(
					'InvalidRequest',
					'Malformed aws-chunked body: missing chunk terminator',
					Http::STATUS_BAD_REQUEST,
				);
			}
			$seen++;
		}
	}
}
