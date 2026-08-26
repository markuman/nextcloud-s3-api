<?php

declare(strict_types=1);

namespace OCA\S3Api\Service;

use OCA\S3Api\Db\Upload;
use OCA\S3Api\Db\UploadMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\NotFoundException;

/**
 * Multipart uploads.
 *
 * Parts are kept as files under a scratch folder in the bucket
 * (`.s3-uploads/<uploadId>/<partNumber>`) and concatenated on completion.
 * Listings hide that folder, so it never shows up as an object.
 */
class MultipartService {

	/** Scratch folder name inside a bucket. */
	public const SCRATCH_DIR = '.s3-uploads';

	/** Copy buffer for assembling parts. */
	private const CHUNK = 1024 * 1024;

	/**
	 * S3's minimum size for any part but the last.
	 */
	private const MIN_PART_SIZE = 5 * 1024 * 1024;

	public function __construct(
		private UploadMapper $uploadMapper,
	) {
	}

	/**
	 * Begin an upload and return its id.
	 */
	public function create(int $bucketId, string $userId, string $objectKey): string {
		$uploadId = bin2hex(random_bytes(16));

		$upload = new Upload();
		$upload->setUploadId($uploadId);
		$upload->setBucketId($bucketId);
		$upload->setObjectKey($objectKey);
		$upload->setUserId($userId);
		$upload->setCreatedAt(new \DateTime());
		$this->uploadMapper->insert($upload);

		return $uploadId;
	}

	/**
	 * @throws S3AuthException when the id is unknown or targets another key
	 */
	public function get(string $uploadId, int $bucketId, ?string $expectKey = null): Upload {
		try {
			$upload = $this->uploadMapper->findByUploadId($uploadId, $bucketId);
		} catch (DoesNotExistException) {
			throw new S3AuthException(
				'NoSuchUpload',
				'The specified multipart upload does not exist',
				Http::STATUS_NOT_FOUND,
			);
		}

		if ($expectKey !== null && $upload->getObjectKey() !== $expectKey) {
			throw new S3AuthException(
				'NoSuchUpload',
				'The specified multipart upload does not exist for this key',
				Http::STATUS_NOT_FOUND,
			);
		}

		return $upload;
	}

	/**
	 * Store one part, returning its ETag.
	 *
	 * @param resource $stream decoded part content
	 */
	public function putPart(Folder $bucketFolder, string $uploadId, int $partNumber, $stream): string {
		if ($partNumber < 1 || $partNumber > 10000) {
			throw new S3AuthException(
				'InvalidArgument',
				'Part number must be between 1 and 10000',
				Http::STATUS_BAD_REQUEST,
			);
		}

		$folder = $this->partFolder($bucketFolder, $uploadId, true);
		$name = $this->partName($partNumber);

		try {
			$existing = $folder->get($name);
			if ($existing instanceof File) {
				$existing->putContent($stream);
				$file = $existing;
			} else {
				throw new S3AuthException('InternalError', 'Part path is not a file', Http::STATUS_INTERNAL_SERVER_ERROR);
			}
		} catch (NotFoundException) {
			$file = $folder->newFile($name, $stream);
		}

		// The part ETag is the MD5 of its bytes, because CompleteMultipartUpload
		// derives the final ETag from the part ETags.
		$handle = $file->fopen('rb');
		if ($handle === false) {
			throw new S3AuthException('InternalError', 'Cannot read stored part', Http::STATUS_INTERNAL_SERVER_ERROR);
		}
		$ctx = hash_init('md5');
		hash_update_stream($ctx, $handle);
		fclose($handle);

		return hash_final($ctx);
	}

	/**
	 * Parts currently stored for an upload, ordered by part number.
	 *
	 * @return array<int, array{number: int, size: int, etag: string, mtime: int}>
	 */
	public function listParts(Folder $bucketFolder, string $uploadId): array {
		$folder = $this->partFolder($bucketFolder, $uploadId, false);
		if ($folder === null) {
			return [];
		}

		$parts = [];
		foreach ($folder->getDirectoryListing() as $node) {
			if (!($node instanceof File)) {
				continue;
			}
			$number = (int)ltrim($node->getName(), '0');
			if ($number < 1) {
				continue;
			}

			$handle = $node->fopen('rb');
			if ($handle === false) {
				continue;
			}
			$ctx = hash_init('md5');
			hash_update_stream($ctx, $handle);
			fclose($handle);

			$parts[$number] = [
				'number' => $number,
				'size' => $node->getSize(),
				'etag' => hash_final($ctx),
				'mtime' => $node->getMTime(),
			];
		}

		ksort($parts);
		return $parts;
	}

	/**
	 * Concatenate the requested parts into the target object.
	 *
	 * @param array<int, array{number: int, etag: string}> $requested parts in
	 *        the order the client specified
	 * @return array{file: File, etag: string}
	 * @throws S3AuthException on unknown, misordered or undersized parts
	 */
	public function complete(
		Folder $bucketFolder,
		Upload $upload,
		array $requested,
		\Closure $resolveTarget,
	): array {
		if ($requested === []) {
			throw new S3AuthException(
				'InvalidRequest',
				'You must specify at least one part',
				Http::STATUS_BAD_REQUEST,
			);
		}

		$available = $this->listParts($bucketFolder, $upload->getUploadId());

		// Check the ordering over the whole list before looking at any single
		// part: a misordered list would otherwise be reported as whatever
		// happens to be wrong with the part that comes first, e.g. [2, 1] as
		// "part 2 is too small" because part 2 is no longer last.
		$lastNumber = 0;
		foreach ($requested as $part) {
			if ($part['number'] <= $lastNumber) {
				throw new S3AuthException(
					'InvalidPartOrder',
					'The list of parts was not in ascending order',
					Http::STATUS_BAD_REQUEST,
				);
			}
			$lastNumber = $part['number'];
		}

		foreach ($requested as $index => $part) {
			$number = $part['number'];

			if (!isset($available[$number])) {
				throw new S3AuthException(
					'InvalidPart',
					'One or more of the specified parts could not be found',
					Http::STATUS_BAD_REQUEST,
				);
			}

			$expectedEtag = trim($part['etag'], '"');
			if ($expectedEtag !== '' && !hash_equals($available[$number]['etag'], $expectedEtag)) {
				throw new S3AuthException(
					'InvalidPart',
					'The ETag of part ' . $number . ' does not match',
					Http::STATUS_BAD_REQUEST,
				);
			}

			// Every part except the last must reach the minimum size.
			$isLast = $index === count($requested) - 1;
			if (!$isLast && $available[$number]['size'] < self::MIN_PART_SIZE) {
				throw new S3AuthException(
					'EntityTooSmall',
					'Part ' . $number . ' is smaller than the minimum allowed size',
					Http::STATUS_BAD_REQUEST,
				);
			}
		}

		$partFolder = $this->partFolder($bucketFolder, $upload->getUploadId(), false);
		if ($partFolder === null) {
			throw new S3AuthException('NoSuchUpload', 'Upload data is gone', Http::STATUS_NOT_FOUND);
		}

		// Assemble into a temp stream, then hand the finished bytes to the
		// storage in one call so the object never exists half-written.
		$assembled = fopen('php://temp/maxmemory:' . (8 * 1024 * 1024), 'w+b');
		if ($assembled === false) {
			throw new S3AuthException('InternalError', 'Cannot assemble parts', Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		$binaryEtags = '';
		try {
			foreach ($requested as $part) {
				$node = $partFolder->get($this->partName($part['number']));
				if (!($node instanceof File)) {
					throw new S3AuthException('InvalidPart', 'Part vanished during completion', Http::STATUS_BAD_REQUEST);
				}

				$handle = $node->fopen('rb');
				if ($handle === false) {
					throw new S3AuthException('InternalError', 'Cannot read part', Http::STATUS_INTERNAL_SERVER_ERROR);
				}
				try {
					while (!feof($handle)) {
						$buf = fread($handle, self::CHUNK);
						if ($buf === false) {
							break;
						}
						if (fwrite($assembled, $buf) === false) {
							throw new S3AuthException('InternalError', 'Failed assembling parts', Http::STATUS_INTERNAL_SERVER_ERROR);
						}
					}
				} finally {
					fclose($handle);
				}

				$binaryEtags .= hex2bin($available[$part['number']]['etag']);
			}

			rewind($assembled);

			/** @var File $file */
			$file = $resolveTarget($assembled);
		} finally {
			if (is_resource($assembled)) {
				fclose($assembled);
			}
		}

		// S3's multipart ETag: md5 of the concatenated part digests, suffixed
		// with the part count.
		$etag = md5($binaryEtags) . '-' . count($requested);

		$this->discard($bucketFolder, $upload);

		return ['file' => $file, 'etag' => $etag];
	}

	/**
	 * Drop an upload's parts and its record.
	 */
	public function discard(Folder $bucketFolder, Upload $upload): void {
		$folder = $this->partFolder($bucketFolder, $upload->getUploadId(), false);
		if ($folder !== null) {
			$folder->delete();
		}

		$this->pruneScratchRoot($bucketFolder);
		$this->uploadMapper->delete($upload);
	}

	/**
	 * @return Upload[]
	 */
	public function listUploads(int $bucketId): array {
		return $this->uploadMapper->findByBucket($bucketId);
	}

	/**
	 * Remove the scratch root once the last upload is gone, so buckets do not
	 * keep an empty hidden folder around.
	 */
	private function pruneScratchRoot(Folder $bucketFolder): void {
		try {
			$root = $bucketFolder->get(self::SCRATCH_DIR);
		} catch (NotFoundException) {
			return;
		}

		if ($root instanceof Folder && $root->getDirectoryListing() === []) {
			$root->delete();
		}
	}

	/**
	 * @param bool $create create the folder when missing
	 * @return ($create is true ? Folder : Folder|null)
	 */
	private function partFolder(Folder $bucketFolder, string $uploadId, bool $create): ?Folder {
		// The id is generated by us and is pure hex, but treat it as untrusted
		// anyway: it arrives from the request.
		if (preg_match('/^[0-9a-f]{32}$/', $uploadId) !== 1) {
			throw new S3AuthException('NoSuchUpload', 'Malformed upload id', Http::STATUS_NOT_FOUND);
		}

		try {
			$root = $bucketFolder->get(self::SCRATCH_DIR);
			if (!($root instanceof Folder)) {
				throw new S3AuthException('InternalError', 'Upload scratch path is not a folder', Http::STATUS_INTERNAL_SERVER_ERROR);
			}
		} catch (NotFoundException) {
			if (!$create) {
				return null;
			}
			$root = $bucketFolder->newFolder(self::SCRATCH_DIR);
		}

		try {
			$folder = $root->get($uploadId);
			if (!($folder instanceof Folder)) {
				throw new S3AuthException('InternalError', 'Upload path is not a folder', Http::STATUS_INTERNAL_SERVER_ERROR);
			}
			return $folder;
		} catch (NotFoundException) {
			return $create ? $root->newFolder($uploadId) : null;
		}
	}

	/**
	 * Zero-pad so a plain directory listing sorts numerically.
	 */
	private function partName(int $partNumber): string {
		return str_pad((string)$partNumber, 5, '0', STR_PAD_LEFT);
	}
}
