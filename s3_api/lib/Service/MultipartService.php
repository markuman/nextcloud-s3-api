<?php

declare(strict_types=1);

namespace OCA\S3Api\Service;

use OCA\Files_Trashbin\Events\MoveToTrashEvent;
use OCA\S3Api\Db\Upload;
use OCA\S3Api\Db\UploadMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;

/**
 * Multipart uploads.
 *
 * Parts are kept as files under a scratch folder in the bucket
 * (`.s3-uploads/<uploadId>/<partNumber>`) and concatenated on completion.
 * Listings hide that folder, so it never shows up as an object, and it is
 * deleted past the trash bin, so it does not linger there either.
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

	/**
	 * Uploads one bucket may have in flight. S3 sets no such limit, but here
	 * every upload is a row and a folder, and an unfinished one is only
	 * reclaimed after a week: without a bound, a key could pile them up
	 * faster than cleanup takes them away. Real clients run a handful in
	 * parallel.
	 */
	public const MAX_UPLOADS_PER_BUCKET = 1000;

	/** Multipart uploads untouched for this long are abandoned. */
	public const UPLOAD_MAX_AGE_SECONDS = 7 * 24 * 3600;

	/**
	 * Abandoned uploads one CreateMultipartUpload clears away when the
	 * bucket is at its limit. Each is a folder delete, so a request does
	 * not take them all on, but every create that hits the limit makes room.
	 */
	private const REAP_ON_CREATE = 50;

	public function __construct(
		private UploadMapper $uploadMapper,
		private IEventDispatcher $eventDispatcher,
		private TempFiles $tempFiles,
		private EtagService $etagService,
	) {
	}

	/**
	 * Begin an upload and return its id.
	 *
	 * At the limit, uploads of this bucket that are past their age are
	 * discarded first, as the cleanup job would. That job only gets to them
	 * a batch every few hours, and a client that crashed through a thousand
	 * uploads should not keep the bucket from taking new ones once those
	 * have expired.
	 *
	 * @throws S3AuthException when the bucket has too many uploads in flight
	 */
	public function create(Folder $bucketFolder, int $bucketId, string $userId, string $objectKey): string {
		// Not atomic with the insert: concurrent creates can overshoot by a
		// few, which is fine for a bound that only has to stop a pile-up.
		if ($this->uploadMapper->countByBucket($bucketId) >= self::MAX_UPLOADS_PER_BUCKET) {
			$cutoff = new \DateTime('@' . (time() - self::UPLOAD_MAX_AGE_SECONDS));
			foreach ($this->uploadMapper->findOlderThanInBucket($bucketId, $cutoff, self::REAP_ON_CREATE) as $expired) {
				$this->discard($bucketFolder, $expired);
			}

			if ($this->uploadMapper->countByBucket($bucketId) >= self::MAX_UPLOADS_PER_BUCKET) {
				throw new S3AuthException(
					'InvalidRequest',
					'Too many multipart uploads in progress for this bucket; complete or abort some first',
					Http::STATUS_BAD_REQUEST,
				);
			}
		}

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

		// A part uploaded again replaces the earlier one. Writing over it in
		// place would make Nextcloud keep the old bytes as a file version,
		// which nothing reads and which outlives the upload: deleting the
		// scratch folder does not take the versions of its files along. So
		// the old part goes first and the new one is a fresh file.
		try {
			$existing = $folder->get($name);
			if (!($existing instanceof File)) {
				throw new S3AuthException('InternalError', 'Part path is not a file', Http::STATUS_INTERNAL_SERVER_ERROR);
			}
			$existingId = $existing->getId();
			$this->deleteForGood($existing);
			$this->etagService->forget($existingId);
		} catch (NotFoundException) {
			// First upload of this part.
		}

		$file = $folder->newFile($name, $stream);

		// The part ETag is the MD5 of its bytes, because CompleteMultipartUpload
		// derives the final ETag from the part ETags. Recorded like an
		// object's, so ListParts and Complete look it up instead of reading
		// every part again on each call.
		return $this->etagService->refresh($file);
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

			$parts[$number] = [
				'number' => $number,
				'size' => $node->getSize(),
				// Recorded by putPart(); only a part changed behind our back
				// is hashed again.
				'etag' => $this->etagService->get($node),
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

		// The object is assembled in the temp directory first and then stored
		// next to its parts, so it needs its full size in free space while
		// they still take theirs. Better to say so now than after copying it
		// all into a temp file only for the quota to refuse it.
		$total = 0;
		foreach ($requested as $part) {
			$total += $available[$part['number']]['size'];
		}
		$free = $bucketFolder->getFreeSpace();
		if ((is_int($free) || is_float($free)) && $free >= 0 && $total > $free) {
			throw new S3AuthException(
				'EntityTooLarge',
				'Not enough free space to assemble the object',
				Http::STATUS_BAD_REQUEST,
			);
		}

		// Assemble into a temp stream, then hand the finished bytes to the
		// storage in one call so the object never exists half-written.
		$assembled = $this->tempFiles->open();

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
			// The parts' recorded ETags go with them: file ids are reused.
			$partIds = [];
			foreach ($folder->getDirectoryListing() as $node) {
				$partIds[] = $node->getId();
			}
			$this->deleteForGood($folder);
			$this->etagService->forgetMany($partIds);
		}

		$this->pruneScratchRoot($bucketFolder);
		$this->uploadMapper->delete($upload);
	}

	/**
	 * Delete scratch data without the detour through the trash bin.
	 *
	 * A plain delete() hands the node to the trash bin, so every completed or
	 * aborted upload left a full copy of its parts in the owner's trash —
	 * twice the space until the trash expired. The trash bin asks before it
	 * takes a node, through MoveToTrashEvent; answering no for exactly this
	 * node, and only while it is being deleted, leaves everything else the
	 * user deletes as recoverable as before.
	 *
	 * With the trash bin app disabled the event never fires, and delete() is
	 * final anyway.
	 */
	private function deleteForGood(Node $node): void {
		$id = $node->getId();
		$keepOutOfTrash = static function (Event $event) use ($id): void {
			if ($event instanceof MoveToTrashEvent && $event->getNode()->getId() === $id) {
				$event->disableTrashBin();
			}
		};

		$this->eventDispatcher->addListener(MoveToTrashEvent::class, $keepOutOfTrash);
		try {
			$node->delete();
		} finally {
			$this->eventDispatcher->removeListener(MoveToTrashEvent::class, $keepOutOfTrash);
		}
	}

	/**
	 * One page of a bucket's uploads, plus one more if there is a next page.
	 *
	 * @return Upload[]
	 */
	public function listUploads(int $bucketId, string $prefix, string $keyMarker, string $uploadIdMarker, int $maxUploads): array {
		return $this->uploadMapper->findByBucketPage($bucketId, $prefix, $keyMarker, $uploadIdMarker, $maxUploads + 1);
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
			$this->deleteForGood($root);
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
