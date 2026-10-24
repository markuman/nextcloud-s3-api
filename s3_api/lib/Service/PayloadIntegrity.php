<?php

declare(strict_types=1);

namespace OCA\S3Api\Service;

use OCP\AppFramework\Http;
use OCP\IRequest;

/**
 * Checks what a request claims about its own body, for the bodies that are
 * small enough to hold in memory: the XML of DeleteObjects and
 * CompleteMultipartUpload.
 *
 * SigV4 covers the body only through the payload hash. A literal
 * `x-amz-content-sha256` does that directly, signed `aws-chunked` framing
 * through the chunk signature chain. `UNSIGNED-PAYLOAD` -- what SDKs send
 * over HTTPS when they also send a checksum -- leaves the body to that
 * checksum: `Content-MD5` when it is among the signed headers, or an
 * `x-amz-checksum-*` header, which the auth service only accepts signed.
 * Without one of those, whoever can replay a captured request can also swap
 * its body: a different list of keys to delete, a different part list.
 */
final class PayloadIntegrity {

	/** `x-amz-checksum-*` headers and the hash() algorithm behind each. */
	private const CHECKSUMS = [
		'x-amz-checksum-crc32' => 'crc32b',
		'x-amz-checksum-crc32c' => 'crc32c',
		'x-amz-checksum-sha1' => 'sha1',
		'x-amz-checksum-sha256' => 'sha256',
		'x-amz-checksum-crc64nvme' => 'crc64nvme',
	];

	/** @var list<int>|null lookup table for CRC-64/NVME, built on first use */
	private static ?array $crc64Table = null;

	/**
	 * Verify every integrity claim the request makes about `$body`.
	 *
	 * @param string $body the decoded body
	 * @param string $sha256 hex SHA-256 of `$body`
	 * @param string[] $signedHeaders lower-case names of the signed headers
	 * @param bool $chunkSigned whether the body arrived with a verified chunk
	 *        signature chain
	 * @param bool $checksumsDescribeBody whether `x-amz-checksum-*` is a
	 *        checksum of this body. On DeleteObjects it is. On
	 *        CompleteMultipartUpload it is not: there it is the checksum of
	 *        the whole object being assembled, and checking it against the
	 *        XML would refuse every client that sends one.
	 * @return bool whether something the signature covers pins the body down
	 * @throws S3AuthException when a claim does not hold
	 */
	public static function verify(IRequest $request, string $body, string $sha256, array $signedHeaders, bool $chunkSigned, bool $checksumsDescribeBody): bool {
		$bound = $chunkSigned;

		$declared = strtolower(trim((string)$request->getHeader('x-amz-content-sha256')));
		if (self::isLiteralSha256($declared)) {
			if (!hash_equals($declared, $sha256)) {
				throw new S3AuthException(
					'XAmzContentSHA256Mismatch',
					'The provided x-amz-content-sha256 does not match what was computed',
					Http::STATUS_BAD_REQUEST,
				);
			}
			$bound = true;
		}

		$md5 = trim((string)$request->getHeader('Content-MD5'));
		if ($md5 !== '') {
			$expected = base64_decode($md5, true);
			if ($expected === false || strlen($expected) !== 16) {
				throw new S3AuthException('InvalidDigest', 'The Content-MD5 you specified is not valid', Http::STATUS_BAD_REQUEST);
			}
			if (!hash_equals($expected, md5($body, true))) {
				throw new S3AuthException('BadDigest', 'The Content-MD5 you specified did not match what we received', Http::STATUS_BAD_REQUEST);
			}
			// An unsigned Content-MD5 can be rewritten along with the body.
			$bound = $bound || in_array('content-md5', $signedHeaders, true);
		}

		foreach ($checksumsDescribeBody ? self::CHECKSUMS : [] as $header => $algorithm) {
			$value = trim((string)$request->getHeader($header));
			if ($value === '') {
				continue;
			}
			$raw = $algorithm === 'crc64nvme' ? self::crc64nvme($body) : hash($algorithm, $body, true);
			if (!hash_equals(base64_encode($raw), $value)) {
				throw new S3AuthException(
					'BadDigest',
					'The ' . substr($header, strlen('x-amz-checksum-')) . ' checksum you specified did not match what we received',
					Http::STATUS_BAD_REQUEST,
				);
			}
			$bound = $bound || in_array($header, $signedHeaders, true);
		}

		return $bound;
	}

	/**
	 * Whether `x-amz-content-sha256` is an actual hash rather than one of the
	 * `UNSIGNED-PAYLOAD` / `STREAMING-*` sentinels.
	 */
	public static function isLiteralSha256(string $value): bool {
		return strlen($value) === 64 && ctype_xdigit($value);
	}

	/**
	 * CRC-64/NVME, big-endian, as S3's `x-amz-checksum-crc64nvme` expects.
	 *
	 * PHP's hash extension does not have it. Only used on the small bodies
	 * this class sees, so a byte-at-a-time table loop is fast enough.
	 */
	public static function crc64nvme(string $data): string {
		$table = self::$crc64Table ??= self::crc64Table();

		// PHP integers are signed: `>>` drags the sign bit along, so every
		// shift is masked back to a logical one.
		$crc = -1;
		$length = strlen($data);
		for ($i = 0; $i < $length; $i++) {
			$crc = $table[($crc ^ ord($data[$i])) & 0xFF] ^ (($crc >> 8) & 0x00FFFFFFFFFFFFFF);
		}

		return pack('J', ~$crc);
	}

	/**
	 * @return list<int>
	 */
	private static function crc64Table(): array {
		// The reflected polynomial 0x9A6C9329AC4BC9B5. Its top bit is set, so
		// as a literal it would overflow to a float.
		$poly = 0x1A6C9329AC4BC9B5 | PHP_INT_MIN;

		$table = [];
		for ($i = 0; $i < 256; $i++) {
			$c = $i;
			for ($bit = 0; $bit < 8; $bit++) {
				$shifted = ($c >> 1) & PHP_INT_MAX;
				$c = ($c & 1) ? ($shifted ^ $poly) : $shifted;
			}
			$table[] = $c;
		}

		return $table;
	}
}
