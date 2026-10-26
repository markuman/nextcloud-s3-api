<?php

declare(strict_types=1);

namespace OCA\S3Api\Service;

use OCP\AppFramework\Http;
use OCP\IRequest;

/**
 * Checks what a request claims about its own body: uploads (PutObject,
 * UploadPart) and the XML of DeleteObjects and CompleteMultipartUpload.
 *
 * SigV4 covers the body only through the payload hash. A literal
 * `x-amz-content-sha256` does that directly, signed `aws-chunked` framing
 * through the chunk signature chain. `UNSIGNED-PAYLOAD` -- what SDKs send
 * over HTTPS when they also send a checksum -- leaves the body to that
 * checksum: `Content-MD5` when it is among the signed headers, or an
 * `x-amz-checksum-*` header, which the auth service only accepts signed.
 * Without one of those, whoever can replay a captured request can also swap
 * its body.
 *
 * A checksum may also come as a trailer after an `aws-chunked` body, named
 * in `x-amz-trailer`. It is checked against the bytes like a header, but
 * binds nothing: an unsigned trailer can be rewritten with the body, and a
 * signed one only arrives with a signed chunk chain, which binds the body on
 * its own. Its signature is therefore not checked.
 *
 * `x-amz-trailer` itself is signed, though: the client committed to sending
 * that checksum. So an announced checksum trailer that never arrives is
 * refused, as on S3 -- otherwise stripping it would switch the check off
 * for whoever also swaps the body.
 *
 * The digests are computed while the body is read (BodyHash), with the
 * algorithms algorithms() picks from the request's headers.
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

	/**
	 * The algorithms the body has to be hashed with to check the request's
	 * claims: SHA-256 always, MD5 for `Content-MD5`, and the checksum of each
	 * `x-amz-checksum-*` header or trailer the request announces.
	 *
	 * @param bool $checksumsDescribeBody see verify()
	 * @return string[]
	 */
	public static function algorithms(IRequest $request, bool $checksumsDescribeBody): array {
		$algorithms = ['sha256'];

		if (trim((string)$request->getHeader('Content-MD5')) !== '') {
			$algorithms[] = 'md5';
		}

		if ($checksumsDescribeBody) {
			$announced = self::announcedTrailers($request);
			foreach (self::CHECKSUMS as $header => $algorithm) {
				if (trim((string)$request->getHeader($header)) !== '' || in_array($header, $announced, true)) {
					$algorithms[] = $algorithm;
				}
			}
		}

		return array_values(array_unique($algorithms));
	}

	/**
	 * The checksum trailers `x-amz-trailer` announces, lower-case.
	 *
	 * @return string[]
	 */
	private static function announcedTrailers(IRequest $request): array {
		$names = array_map(
			static fn(string $name): string => strtolower(trim($name)),
			explode(',', (string)$request->getHeader('x-amz-trailer')),
		);

		return array_values(array_intersect($names, array_keys(self::CHECKSUMS)));
	}

	/**
	 * Verify every integrity claim the request makes about its body.
	 *
	 * @param array<string, string> $digests raw digests of the decoded body,
	 *        from a BodyHash set up with algorithms()
	 * @param array<string, string> $trailers trailers that followed an
	 *        `aws-chunked` body, by lower-case name
	 * @param string[] $signedHeaders lower-case names of the signed headers
	 * @param bool $chunkSigned whether the body arrived with a verified chunk
	 *        signature chain
	 * @param bool $checksumsDescribeBody whether `x-amz-checksum-*` is a
	 *        checksum of this body. On uploads and DeleteObjects it is. On
	 *        CompleteMultipartUpload it is not: there it is the checksum of
	 *        the whole object being assembled, and checking it against the
	 *        XML would refuse every client that sends one.
	 * @return bool whether something the signature covers pins the body down
	 * @throws S3AuthException when a claim does not hold
	 */
	public static function verify(IRequest $request, array $digests, array $trailers, array $signedHeaders, bool $chunkSigned, bool $checksumsDescribeBody): bool {
		$bound = $chunkSigned;

		$declared = strtolower(trim((string)$request->getHeader('x-amz-content-sha256')));
		if (self::isLiteralSha256($declared)) {
			if (!hash_equals($declared, bin2hex($digests['sha256']))) {
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
			if (!hash_equals($expected, $digests['md5'])) {
				throw new S3AuthException('BadDigest', 'The Content-MD5 you specified did not match what we received', Http::STATUS_BAD_REQUEST);
			}
			// An unsigned Content-MD5 can be rewritten along with the body.
			$bound = $bound || in_array('content-md5', $signedHeaders, true);
		}

		if (!$checksumsDescribeBody) {
			return $bound;
		}

		foreach (self::CHECKSUMS as $header => $algorithm) {
			$value = trim((string)$request->getHeader($header));
			if ($value === '') {
				continue;
			}
			self::compareChecksum($header, $digests[$algorithm], $value);
			$bound = $bound || in_array($header, $signedHeaders, true);
		}

		$announced = self::announcedTrailers($request);

		foreach ($trailers as $name => $value) {
			$algorithm = self::CHECKSUMS[$name] ?? null;
			if ($algorithm === null) {
				// x-amz-trailer-signature, or something that is not ours to check.
				continue;
			}
			if (!in_array($name, $announced, true)) {
				throw new S3AuthException(
					'InvalidRequest',
					'The trailer ' . $name . ' was not announced in x-amz-trailer',
					Http::STATUS_BAD_REQUEST,
				);
			}
			self::compareChecksum($name, $digests[$algorithm], $value);
		}

		// Both ways a trailer goes missing end up here: an unsigned body cut
		// off before its final chunk, and a final chunk with nothing after it.
		foreach ($announced as $name) {
			if (!isset($trailers[$name])) {
				throw new S3AuthException(
					'IncompleteBody',
					'The trailer ' . $name . ' announced in x-amz-trailer was not sent',
					Http::STATUS_BAD_REQUEST,
				);
			}
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
	 * @throws S3AuthException
	 */
	private static function compareChecksum(string $name, string $digest, string $value): void {
		if (!hash_equals(base64_encode($digest), trim($value))) {
			throw new S3AuthException(
				'BadDigest',
				'The ' . substr($name, strlen('x-amz-checksum-')) . ' checksum you specified did not match what we received',
				Http::STATUS_BAD_REQUEST,
			);
		}
	}
}
