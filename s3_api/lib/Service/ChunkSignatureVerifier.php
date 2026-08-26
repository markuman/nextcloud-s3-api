<?php

declare(strict_types=1);

namespace OCA\S3Api\Service;

use OCP\AppFramework\Http;

/**
 * Verifies the per-chunk signatures of an `aws-chunked` upload.
 *
 * With `STREAMING-AWS4-HMAC-SHA256-PAYLOAD` the payload hash in the canonical
 * request is a sentinel, so the SigV4 signature authenticates the headers but
 * says nothing about the bytes. Each chunk instead carries its own signature,
 * chained from the request's seed signature:
 *
 *     string-to-sign = "AWS4-HMAC-SHA256-PAYLOAD\n"
 *                      <amz-date>\n
 *                      <credential-scope>\n
 *                      <previous-signature>\n
 *                      <sha256 of empty string>\n
 *                      <sha256 of this chunk's data>
 *
 * Checking that chain is what actually binds the body to the credential. Skip
 * it and a party able to alter the request between client and server -- a
 * compromised proxy that terminates TLS, say -- could replace the content while
 * the request still authenticates.
 *
 * The final zero-length chunk is signed over the empty string, and a trailer,
 * if present, is signed separately with `AWS4-HMAC-SHA256-TRAILER`.
 */
class ChunkSignatureVerifier {

	private const EMPTY_SHA256 = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

	/** Rolling signature: the seed to begin with, then each chunk's own. */
	private string $previousSignature;

	/**
	 * @param string $seedSignature the signature from the Authorization header
	 * @param string $signingKey derived key for the request's scope
	 * @param string $amzDate the request timestamp, as signed
	 * @param string $scope `<date>/<region>/s3/aws4_request`
	 */
	public function __construct(
		string $seedSignature,
		private string $signingKey,
		private string $amzDate,
		private string $scope,
	) {
		$this->previousSignature = strtolower($seedSignature);
	}

	/**
	 * Check one chunk's signature and advance the chain.
	 *
	 * @param string $signature the `chunk-signature=` value from the header line
	 * @param string $dataSha256 hex sha256 of the chunk's bytes
	 * @throws S3AuthException when the signature does not match
	 */
	public function verifyChunk(string $signature, string $dataSha256): void {
		$expected = $this->sign('AWS4-HMAC-SHA256-PAYLOAD', $dataSha256);

		if (!hash_equals($expected, strtolower(trim($signature)))) {
			throw new S3AuthException(
				'SignatureDoesNotMatch',
				'The chunk signature does not match the data sent',
				Http::STATUS_FORBIDDEN,
			);
		}

		$this->previousSignature = $expected;
	}

	/**
	 * Check the signature over a trailer, which closes the chain.
	 *
	 * @throws S3AuthException when the signature does not match
	 */
	public function verifyTrailer(string $signature, string $trailerSha256): void {
		$expected = $this->sign('AWS4-HMAC-SHA256-TRAILER', $trailerSha256);

		if (!hash_equals($expected, strtolower(trim($signature)))) {
			throw new S3AuthException(
				'SignatureDoesNotMatch',
				'The trailer signature does not match the data sent',
				Http::STATUS_FORBIDDEN,
			);
		}

		$this->previousSignature = $expected;
	}

	private function sign(string $algorithm, string $payloadSha256): string {
		$stringToSign = implode("\n", [
			$algorithm,
			$this->amzDate,
			$this->scope,
			$this->previousSignature,
			self::EMPTY_SHA256,
			$payloadSha256,
		]);

		return hash_hmac('sha256', $stringToSign, $this->signingKey);
	}
}
