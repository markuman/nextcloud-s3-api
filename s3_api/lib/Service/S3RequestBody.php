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
 *   trailer. The signature covers the headers, not the bytes.
 * - `STREAMING-AWS4-HMAC-SHA256-PAYLOAD[-TRAILER]`: each chunk additionally
 *   carries a `chunk-signature` chained from the request's seed signature.
 *   Given a {@see ChunkSignatureVerifier} the chain is checked, which is what
 *   binds the body to the credential: the payload hash in the canonical request
 *   is a sentinel here, so without that check the bytes are unauthenticated.
 *
 * The SigV4a variants (`STREAMING-AWS4-ECDSA-P256-SHA256-*`) are refused by
 * the auth service before a body is read: their chunk signatures could not be
 * checked here, and decoding them unchecked would quietly treat a body its
 * sender meant to be signed as unsigned.
 *
 * Trailers after the final chunk are collected for PayloadIntegrity, which
 * checks the checksums among them against the decoded bytes.
 *
 * Everything is streamed, so a multi-gigabyte upload never has to fit in
 * memory.
 */
class S3RequestBody {

	public function __construct(
		private TempFiles $tempFiles,
	) {
	}

	/** Read buffer size for body copies. */
	private const CHUNK = 1024 * 1024;

	/** Guard against absurd chunk headers from a malformed/hostile body. */
	private const MAX_CHUNK_SIZE = 1024 * 1024 * 1024;

	/** Longest plausible chunk header line, including any chunk-signature. */
	private const MAX_HEADER_LINE = 8192;

	/** More trailers than any SDK sends: a checksum and its signature. */
	private const MAX_TRAILERS = 8;

	/**
	 * Payload hash values that indicate an `aws-chunked` body.
	 */
	private const STREAMING_HASHES = [
		'STREAMING-UNSIGNED-PAYLOAD-TRAILER',
		'STREAMING-AWS4-HMAC-SHA256-PAYLOAD',
		'STREAMING-AWS4-HMAC-SHA256-PAYLOAD-TRAILER',
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
	 * fclose() it. `$hash` is fed the *decoded* content, so the caller can
	 * check what the request claims about it (PayloadIntegrity).
	 *
	 * @param ChunkSignatureVerifier|null $verifier checks the chunk signature
	 *        chain, binding the bytes to the credential; null when the request
	 *        carries no chunk signatures
	 * @param int|null $maxBytes refuse a body that decodes to more than this.
	 *        Checked against the declared size before anything is read, and
	 *        again while copying, since neither a chunked body nor one sent
	 *        without Content-Length has to declare one. Nothing else bounds
	 *        what lands in the temp directory: post_max_size does not apply
	 *        to a raw body, and the quota only once it is stored.
	 * @param array<string, string> $trailers receives the trailers of an
	 *        `aws-chunked` body, by lower-case name
	 * @return resource
	 * @throws S3AuthException on a malformed chunked body, a bad signature or
	 *         a body over `$maxBytes` (`EntityTooLarge`)
	 */
	public function toStream(IRequest $request, BodyHash $hash, ?ChunkSignatureVerifier $verifier = null, ?int $maxBytes = null, array &$trailers = []) {
		if ($maxBytes !== null) {
			$declared = $this->getDecodedLength($request);
			if ($declared !== null && $declared > $maxBytes) {
				throw $this->tooLarge();
			}
		}

		$input = fopen('php://input', 'rb');
		if ($input === false) {
			throw new S3AuthException('InternalError', 'Cannot read request body', Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		try {
			$out = $this->tempFiles->open();
		} catch (S3AuthException $e) {
			fclose($input);
			throw $e;
		}

		$trailers = [];
		try {
			if ($this->isChunked($request)) {
				$trailers = $this->copyChunked($input, $out, $hash, $verifier, $maxBytes);
				$this->assertDecodedLength($request, ftell($out));
			} else {
				$this->copyRaw($input, $out, $hash, $maxBytes);
			}
		} catch (\Throwable $e) {
			fclose($out);
			throw $e;
		} finally {
			fclose($input);
		}

		rewind($out);
		return $out;
	}

	/**
	 * Read a small body, an XML document, into a string.
	 *
	 * Goes through toStream(), so an `aws-chunked` body is decoded and its
	 * chunk signatures are checked like any upload's.
	 *
	 * @param array<string, string> $trailers as toStream()
	 * @throws S3AuthException as toStream()
	 */
	public function toString(IRequest $request, int $maxBytes, ?ChunkSignatureVerifier $verifier, BodyHash $hash, array &$trailers = []): string {
		$stream = $this->toStream($request, $hash, $verifier, $maxBytes, $trailers);
		try {
			$body = stream_get_contents($stream);
		} finally {
			fclose($stream);
		}

		if ($body === false) {
			throw new S3AuthException('InternalError', 'Cannot read request body', Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return $body;
	}

	private function tooLarge(): S3AuthException {
		return new S3AuthException(
			'EntityTooLarge',
			'Your proposed upload exceeds the maximum allowed size',
			Http::STATUS_BAD_REQUEST,
		);
	}

	/**
	 * @throws S3AuthException when `$total` passes `$maxBytes`
	 */
	private function assertWithin(int $total, ?int $maxBytes): void {
		if ($maxBytes !== null && $total > $maxBytes) {
			throw $this->tooLarge();
		}
	}

	/**
	 * Hold an `aws-chunked` body to the size its sender declared.
	 *
	 * For a plain body the web server enforces `Content-Length`; with the
	 * chunked framing that header describes the framed bytes, and
	 * `x-amz-decoded-content-length` is the only statement of the object's
	 * size. An unsigned chunked body may also end without its zero-length
	 * chunk and still be accepted, so this check is what catches one that was
	 * cut short.
	 *
	 * @throws S3AuthException
	 */
	private function assertDecodedLength(IRequest $request, int|false $written): void {
		$declared = (string)$request->getHeader('x-amz-decoded-content-length');
		if ($declared === '' || !ctype_digit($declared)) {
			return;
		}

		if ((int)$declared !== $written) {
			throw new S3AuthException(
				'IncompleteBody',
				'The decoded body does not have the length given in x-amz-decoded-content-length',
				Http::STATUS_BAD_REQUEST,
			);
		}
	}

	/**
	 * @param resource $in
	 * @param resource $out
	 */
	private function copyRaw($in, $out, BodyHash $hash, ?int $maxBytes = null): void {
		$total = 0;
		while (!feof($in)) {
			$buf = fread($in, self::CHUNK);
			if ($buf === false) {
				break;
			}
			if ($buf === '') {
				continue;
			}
			$total += strlen($buf);
			$this->assertWithin($total, $maxBytes);
			$hash->update($buf);
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
	 * @return array<string, string> the trailers, by lower-case name
	 */
	private function copyChunked($in, $out, BodyHash $hash, ?ChunkSignatureVerifier $verifier = null, ?int $maxBytes = null): array {
		$total = 0;
		while (true) {
			$line = $this->readLine($in);
			if ($line === null) {
				// Body ended without a terminating zero-length chunk.
				if ($verifier !== null) {
					// With signed chunks the terminator is what proves the body
					// ended where the signer said it did, so a truncated body
					// must not pass.
					throw new S3AuthException(
						'IncompleteBody',
						'aws-chunked body ended without its final chunk',
						Http::STATUS_BAD_REQUEST,
					);
				}

				// Unsigned: the payload decoded so far is complete for every SDK
				// we have seen, and rejecting would turn a working upload into a
				// failure. Unless it announced a checksum trailer: that one is
				// now missing, which PayloadIntegrity refuses.
				return [];
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
			$extensions = $semicolon === false ? '' : substr($line, $semicolon + 1);

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
				// Final chunk: signed over the empty string. Trailers may follow
				// and belong to none of the object's content.
				if ($verifier !== null) {
					$verifier->verifyChunk(
						$this->chunkSignature($extensions),
						hash('sha256', ''),
					);
				}
				return $this->readTrailers($in);
			}

			// The chunk header states its size, so a body that would grow past
			// the limit is refused before its bytes are read.
			$total += $size;
			$this->assertWithin($total, $maxBytes);

			// A signed chunk has to be hashed as a whole to check it, so keep it
			// aside; chunk sizes are bounded by MAX_CHUNK_SIZE.
			$chunkHash = $verifier === null ? null : hash_init('sha256');
			$this->copyExactly($in, $out, $hash, $size, $chunkHash);

			if ($verifier !== null) {
				$verifier->verifyChunk(
					$this->chunkSignature($extensions),
					hash_final($chunkHash),
				);
			}

			// Each chunk's payload is followed by CRLF.
			$this->consumeCrlf($in);
		}
	}

	/**
	 * Read the `name:value` lines after the final chunk, up to the blank line
	 * that ends the body.
	 *
	 * @param resource $in
	 * @return array<string, string> by lower-case name
	 * @throws S3AuthException on a line that is not a trailer
	 */
	private function readTrailers($in): array {
		$trailers = [];
		while (($line = $this->readLine($in)) !== null) {
			$line = trim($line);
			if ($line === '') {
				break;
			}

			$colon = strpos($line, ':');
			if ($colon === false || count($trailers) >= self::MAX_TRAILERS) {
				throw new S3AuthException(
					'InvalidRequest',
					'Malformed aws-chunked body: bad trailer',
					Http::STATUS_BAD_REQUEST,
				);
			}
			$trailers[strtolower(trim(substr($line, 0, $colon)))] = trim(substr($line, $colon + 1));
		}

		return $trailers;
	}

	/**
	 * Pull `chunk-signature=` out of a chunk header's extensions.
	 *
	 * @throws S3AuthException when it is absent, since the caller only asks
	 *         while verifying a signed body
	 */
	private function chunkSignature(string $extensions): string {
		foreach (explode(';', $extensions) as $extension) {
			$extension = trim($extension);
			if (str_starts_with($extension, 'chunk-signature=')) {
				return substr($extension, strlen('chunk-signature='));
			}
		}

		throw new S3AuthException(
			'IncompleteBody',
			'A chunk is missing its signature',
			Http::STATUS_BAD_REQUEST,
		);
	}

	/**
	 * Copy exactly `$size` bytes, failing on a truncated body.
	 *
	 * @param resource $in
	 * @param resource $out
	 */
	private function copyExactly($in, $out, BodyHash $hash, int $size, ?\HashContext $chunkHash = null): void {
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
			$hash->update($buf);
			if ($chunkHash !== null) {
				hash_update($chunkHash, $buf);
			}
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
