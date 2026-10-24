<?php

declare(strict_types=1);

namespace OCA\S3Api\Service;

use OCA\S3Api\Db\Upload;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\ForbiddenException;
use OCP\Files\InvalidPathException;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;

class S3ResponseBuilder {

	/** S3 never returns more than this, whatever the client asks for. */
	private const MAX_KEYS_LIMIT = 1000;

	/**
	 * Bytes one listing page may read to compute missing ETags. See
	 * withEtags().
	 */
	private const LISTING_HASH_BUDGET_BYTES = 256 * 1024 * 1024;

	public function __construct(
		private EtagService $etagService,
	) {
	}

	public function listBucketsXml(string $userId, array $buckets): string {
		$xml = '<?xml version="1.0" encoding="UTF-8"?>';
		$xml .= '<ListAllMyBucketsResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/">';
		$xml .= '<Owner>';
		$xml .= '<ID>' . $this->escape($userId) . '</ID>';
		$xml .= '<DisplayName>' . $this->escape($userId) . '</DisplayName>';
		$xml .= '</Owner>';
		$xml .= '<Buckets>';
		foreach ($buckets as $bucket) {
			$xml .= '<Bucket>';
			$xml .= '<Name>' . $this->escape($bucket->getBucketName()) . '</Name>';
			$xml .= '<CreationDate>' . $bucket->getCreatedAt()->format('Y-m-d\TH:i:s.000\Z') . '</CreationDate>';
			$xml .= '</Bucket>';
		}
		$xml .= '</Buckets>';
		$xml .= '</ListAllMyBucketsResult>';
		return $xml;
	}

	public function listObjectsXml(
		string $bucketName,
		Folder $bucketFolder,
		string $prefix,
		string $delimiter,
		string $marker,
		int $maxKeys,
	): string {
		$maxKeys = $this->clampMaxKeys($maxKeys);
		$listing = $this->collect($bucketFolder, $prefix, $delimiter, $marker, $maxKeys);

		$xml = '<?xml version="1.0" encoding="UTF-8"?>';
		$xml .= '<ListBucketResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/">';
		$xml .= '<Name>' . $this->escape($bucketName) . '</Name>';
		$xml .= '<Prefix>' . $this->escape($prefix) . '</Prefix>';
		$xml .= '<Marker>' . $this->escape($marker) . '</Marker>';
		$xml .= '<MaxKeys>' . $maxKeys . '</MaxKeys>';
		$xml .= '<Delimiter>' . $this->escape($delimiter) . '</Delimiter>';
		$xml .= '<IsTruncated>' . ($listing['truncated'] ? 'true' : 'false') . '</IsTruncated>';

		if ($listing['truncated'] && $listing['next'] !== null) {
			$xml .= '<NextMarker>' . $this->escape($listing['next']) . '</NextMarker>';
		}

		$xml .= $this->contentsXml($listing['objects']);
		$xml .= $this->commonPrefixesXml($listing['commonPrefixes']);
		$xml .= '</ListBucketResult>';

		return $xml;
	}

	public function listObjectsV2Xml(
		string $bucketName,
		Folder $bucketFolder,
		string $prefix,
		string $delimiter,
		string $startAfter,
		string $continuationToken,
		int $maxKeys,
	): string {
		$maxKeys = $this->clampMaxKeys($maxKeys);

		// A continuation token supersedes start-after. It is opaque to clients,
		// so it is base64 of the last key we returned.
		$after = $startAfter;
		if ($continuationToken !== '') {
			$decoded = base64_decode($continuationToken, true);
			$after = $decoded === false ? $continuationToken : $decoded;
		}

		$listing = $this->collect($bucketFolder, $prefix, $delimiter, $after, $maxKeys);

		$xml = '<?xml version="1.0" encoding="UTF-8"?>';
		$xml .= '<ListBucketResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/">';
		$xml .= '<Name>' . $this->escape($bucketName) . '</Name>';
		$xml .= '<Prefix>' . $this->escape($prefix) . '</Prefix>';
		$xml .= '<MaxKeys>' . $maxKeys . '</MaxKeys>';
		$xml .= '<Delimiter>' . $this->escape($delimiter) . '</Delimiter>';
		$xml .= '<KeyCount>' . (count($listing['objects']) + count($listing['commonPrefixes'])) . '</KeyCount>';
		$xml .= '<IsTruncated>' . ($listing['truncated'] ? 'true' : 'false') . '</IsTruncated>';

		if ($continuationToken !== '') {
			$xml .= '<ContinuationToken>' . $this->escape($continuationToken) . '</ContinuationToken>';
		}
		if ($listing['truncated'] && $listing['next'] !== null) {
			$xml .= '<NextContinuationToken>' . $this->escape(base64_encode($listing['next'])) . '</NextContinuationToken>';
		}
		if ($startAfter !== '') {
			$xml .= '<StartAfter>' . $this->escape($startAfter) . '</StartAfter>';
		}

		$xml .= $this->contentsXml($listing['objects']);
		$xml .= $this->commonPrefixesXml($listing['commonPrefixes']);
		$xml .= '</ListBucketResult>';

		return $xml;
	}

	public function initiateMultipartUploadXml(string $bucketName, string $key, string $uploadId): string {
		$xml = '<?xml version="1.0" encoding="UTF-8"?>';
		$xml .= '<InitiateMultipartUploadResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/">';
		$xml .= '<Bucket>' . $this->escape($bucketName) . '</Bucket>';
		$xml .= '<Key>' . $this->escape($key) . '</Key>';
		$xml .= '<UploadId>' . $this->escape($uploadId) . '</UploadId>';
		$xml .= '</InitiateMultipartUploadResult>';
		return $xml;
	}

	public function completeMultipartUploadXml(string $location, string $bucketName, string $key, string $etag): string {
		$xml = '<?xml version="1.0" encoding="UTF-8"?>';
		$xml .= '<CompleteMultipartUploadResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/">';
		$xml .= '<Location>' . $this->escape($location) . '</Location>';
		$xml .= '<Bucket>' . $this->escape($bucketName) . '</Bucket>';
		$xml .= '<Key>' . $this->escape($key) . '</Key>';
		$xml .= '<ETag>"' . $this->escape($etag) . '"</ETag>';
		$xml .= '</CompleteMultipartUploadResult>';
		return $xml;
	}

	/**
	 * One page of ListParts.
	 *
	 * @param array<int, array{number: int, size: int, etag: string, mtime: int}> $parts
	 *        the parts on this page, in order
	 */
	public function listPartsXml(
		string $bucketName,
		string $key,
		string $uploadId,
		array $parts,
		int $partNumberMarker,
		int $maxParts,
		bool $truncated,
	): string {
		$xml = '<?xml version="1.0" encoding="UTF-8"?>';
		$xml .= '<ListPartsResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/">';
		$xml .= '<Bucket>' . $this->escape($bucketName) . '</Bucket>';
		$xml .= '<Key>' . $this->escape($key) . '</Key>';
		$xml .= '<UploadId>' . $this->escape($uploadId) . '</UploadId>';
		$xml .= '<StorageClass>STANDARD</StorageClass>';
		$xml .= '<PartNumberMarker>' . $partNumberMarker . '</PartNumberMarker>';
		if ($truncated && $parts !== []) {
			$xml .= '<NextPartNumberMarker>' . $parts[array_key_last($parts)]['number'] . '</NextPartNumberMarker>';
		}
		$xml .= '<MaxParts>' . $maxParts . '</MaxParts>';
		$xml .= '<IsTruncated>' . ($truncated ? 'true' : 'false') . '</IsTruncated>';

		foreach ($parts as $part) {
			$xml .= '<Part>';
			$xml .= '<PartNumber>' . $part['number'] . '</PartNumber>';
			$xml .= '<LastModified>' . gmdate('Y-m-d\TH:i:s.000\Z', $part['mtime']) . '</LastModified>';
			$xml .= '<ETag>"' . $this->escape($part['etag']) . '"</ETag>';
			$xml .= '<Size>' . $part['size'] . '</Size>';
			$xml .= '</Part>';
		}

		$xml .= '</ListPartsResult>';
		return $xml;
	}

	/**
	 * One page of ListMultipartUploads.
	 *
	 * @param Upload[] $uploads the uploads on this page, in order
	 */
	public function listMultipartUploadsXml(
		string $bucketName,
		array $uploads,
		string $prefix,
		string $keyMarker,
		string $uploadIdMarker,
		int $maxUploads,
		bool $truncated,
	): string {
		$xml = '<?xml version="1.0" encoding="UTF-8"?>';
		$xml .= '<ListMultipartUploadsResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/">';
		$xml .= '<Bucket>' . $this->escape($bucketName) . '</Bucket>';
		$xml .= '<KeyMarker>' . $this->escape($keyMarker) . '</KeyMarker>';
		$xml .= '<UploadIdMarker>' . $this->escape($uploadIdMarker) . '</UploadIdMarker>';
		if ($truncated && $uploads !== []) {
			$last = $uploads[array_key_last($uploads)];
			$xml .= '<NextKeyMarker>' . $this->escape($last->getObjectKey()) . '</NextKeyMarker>';
			$xml .= '<NextUploadIdMarker>' . $this->escape($last->getUploadId()) . '</NextUploadIdMarker>';
		}
		if ($prefix !== '') {
			$xml .= '<Prefix>' . $this->escape($prefix) . '</Prefix>';
		}
		$xml .= '<MaxUploads>' . $maxUploads . '</MaxUploads>';
		$xml .= '<IsTruncated>' . ($truncated ? 'true' : 'false') . '</IsTruncated>';

		foreach ($uploads as $upload) {
			$xml .= '<Upload>';
			$xml .= '<Key>' . $this->escape($upload->getObjectKey()) . '</Key>';
			$xml .= '<UploadId>' . $this->escape($upload->getUploadId()) . '</UploadId>';
			$xml .= '<StorageClass>STANDARD</StorageClass>';
			$xml .= '<Initiated>' . $upload->getCreatedAt()->format('Y-m-d\TH:i:s.000\Z') . '</Initiated>';
			$xml .= '</Upload>';
		}

		$xml .= '</ListMultipartUploadsResult>';
		return $xml;
	}

	/**
	 * @param string[] $deleted
	 * @param array<int, array{key: string, code: string, message: string}> $errors
	 */
	public function deleteResultXml(array $deleted, array $errors): string {
		$xml = '<?xml version="1.0" encoding="UTF-8"?>';
		$xml .= '<DeleteResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/">';

		foreach ($deleted as $key) {
			$xml .= '<Deleted><Key>' . $this->escape($key) . '</Key></Deleted>';
		}
		foreach ($errors as $error) {
			$xml .= '<Error>';
			$xml .= '<Key>' . $this->escape($error['key']) . '</Key>';
			$xml .= '<Code>' . $this->escape($error['code']) . '</Code>';
			$xml .= '<Message>' . $this->escape($error['message']) . '</Message>';
			$xml .= '</Error>';
		}

		$xml .= '</DeleteResult>';
		return $xml;
	}

	public function copyObjectResultXml(string $etag, int $mtime): string {
		$xml = '<?xml version="1.0" encoding="UTF-8"?>';
		$xml .= '<CopyObjectResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/">';
		$xml .= '<LastModified>' . gmdate('Y-m-d\TH:i:s.000\Z', $mtime) . '</LastModified>';
		$xml .= '<ETag>"' . $this->escape($etag) . '"</ETag>';
		$xml .= '</CopyObjectResult>';
		return $xml;
	}

	public function copyPartResultXml(string $etag, int $mtime): string {
		$xml = '<?xml version="1.0" encoding="UTF-8"?>';
		$xml .= '<CopyPartResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/">';
		$xml .= '<LastModified>' . gmdate('Y-m-d\TH:i:s.000\Z', $mtime) . '</LastModified>';
		$xml .= '<ETag>"' . $this->escape($etag) . '"</ETag>';
		$xml .= '</CopyPartResult>';
		return $xml;
	}

	public function locationXml(): string {
		return '<?xml version="1.0" encoding="UTF-8"?>'
			. '<LocationConstraint xmlns="http://s3.amazonaws.com/doc/2006-03-01/">us-east-1</LocationConstraint>';
	}

	public function versioningXml(): string {
		return '<?xml version="1.0" encoding="UTF-8"?>'
			. '<VersioningConfiguration xmlns="http://s3.amazonaws.com/doc/2006-03-01/"></VersioningConfiguration>';
	}

	public function errorXml(string $code, string $message, string $resource = '', string $requestId = ''): string {
		$xml = '<?xml version="1.0" encoding="UTF-8"?>';
		$xml .= '<Error>';
		$xml .= '<Code>' . $this->escape($code) . '</Code>';
		$xml .= '<Message>' . $this->escape($message) . '</Message>';
		if ($resource !== '') {
			$xml .= '<Resource>' . $this->escape($resource) . '</Resource>';
		}
		$xml .= '<RequestId>' . $this->escape($requestId ?: bin2hex(random_bytes(8))) . '</RequestId>';
		$xml .= '</Error>';
		return $xml;
	}

	// ---- listing ----------------------------------------------------------

	/**
	 * Walk the bucket and collect one page of keys.
	 *
	 * Descent is prefix-aware: only folders on the prefix path are entered, and
	 * with a delimiter the walk stops at the first level below the prefix and
	 * reports the rest as common prefixes. Enumeration halts once a full page
	 * plus one key has been seen, so listing a small prefix in a large bucket
	 * does not traverse the whole tree.
	 *
	 * @return array{objects: list<array{key: string, lastModified: string, etag: string, size: int}>, commonPrefixes: list<string>, truncated: bool, next: ?string}
	 */
	private function collect(
		Folder $bucketFolder,
		string $prefix,
		string $delimiter,
		string $after,
		int $maxKeys,
	): array {
		$objects = [];
		$commonPrefixes = [];

		// Jump straight to the deepest folder the prefix pins down, so a prefix
		// like "repos/acme/" does not walk every sibling. A prefix with no
		// slash pins nothing down and must start at the bucket root -- note
		// that strrpos() returns false there, which must not be read as 0.
		$lastSlash = strrpos($prefix, '/');
		$literal = $lastSlash === false ? '' : substr($prefix, 0, $lastSlash + 1);
		$start = $bucketFolder;
		if ($literal !== '') {
			// The literal part is resolved as a path, so it has to be one that
			// names exactly the keys it spells. Nextcloud refuses `..` itself,
			// but folds `./`, `//` and `\` into some other folder, whose
			// contents would then be listed under keys that do not exist -- and
			// under `./` the scratch area, which walk() only hides at the top
			// level. No stored key has such a segment, so nothing can match.
			// Jumping into the scratch area by name is refused the same way.
			// $literal always ends in the slash strrpos() found; without it,
			// a plain path has no empty segment left.
			$folderPath = substr($literal, 0, -1);
			if (!KeyRules::isPlainPath($folderPath) || KeyRules::isInScratchArea($folderPath)) {
				return ['objects' => [], 'commonPrefixes' => [], 'truncated' => false, 'next' => null];
			}
			$resolved = $this->resolveFolder($bucketFolder, $folderPath);
			if ($resolved === null) {
				return ['objects' => [], 'commonPrefixes' => [], 'truncated' => false, 'next' => null];
			}
			$start = $resolved;
		}

		$this->walk($start, $literal, $prefix, $delimiter, $after, $maxKeys, $objects, $commonPrefixes);

		usort($objects, static fn(array $a, array $b): int => strcmp($a['key'], $b['key']));
		$commonPrefixes = array_values(array_unique($commonPrefixes));
		sort($commonPrefixes);

		$truncated = count($objects) > $maxKeys;
		if ($truncated) {
			$objects = array_slice($objects, 0, $maxKeys);
		}

		$next = null;
		if ($truncated && $objects !== []) {
			$next = $objects[count($objects) - 1]['key'];
		}

		return [
			'objects' => $this->withEtags($objects),
			'commonPrefixes' => $commonPrefixes,
			'truncated' => $truncated,
			'next' => $next,
		];
	}

	/**
	 * @param list<array{key: string, lastModified: string, size: int, file: File}> $objects
	 * @param list<string> $commonPrefixes
	 */
	private function walk(
		Folder $folder,
		string $folderKey,
		string $prefix,
		string $delimiter,
		string $after,
		int $maxKeys,
		array &$objects,
		array &$commonPrefixes,
	): void {
		// One extra key is enough to know the page is truncated.
		if (count($objects) > $maxKeys) {
			return;
		}

		try {
			$children = $folder->getDirectoryListing();
		} catch (NotFoundException) {
			return;
		}

		// Visit entries in key order, not in whatever order the storage
		// returns. Enumeration stops as soon as a page is full, so if the walk
		// were unordered it would stop having missed keys that sort earlier and
		// they would never appear on any page. A folder sorts as if it carried
		// its trailing slash, because that is what its keys look like.
		usort($children, static function ($a, $b): int {
			$ka = $a->getName() . ($a instanceof Folder ? '/' : '');
			$kb = $b->getName() . ($b instanceof Folder ? '/' : '');
			return strcmp($ka, $kb);
		});

		foreach ($children as $node) {
			$name = $node->getName();

			// The multipart scratch area is internal and must never be listed.
			if ($folderKey === '' && $name === MultipartService::SCRATCH_DIR) {
				continue;
			}

			// A name no key can spell -- not NFC, a control character, a
			// backslash, put there through WebDAV or straight on the disk --
			// is an object nobody could GET. Listing it anyway would at best
			// hand out a key that 400s, and a control character would make
			// the whole page unparseable for every client.
			if (!KeyRules::isPlainSegment($name)) {
				continue;
			}

			$key = $folderKey . $name;

			if ($node instanceof Folder) {
				$folderPrefix = $key . '/';

				// Skip subtrees that cannot contain the prefix.
				if (!$this->pathMayContainPrefix($folderPrefix, $prefix)) {
					continue;
				}

				if ($delimiter !== '' && str_starts_with($folderPrefix, $prefix)
					&& $folderPrefix !== $prefix) {
					$rest = substr($folderPrefix, strlen($prefix));
					$cut = strpos($rest, $delimiter);
					if ($cut !== false) {
						// Roll the whole subtree up into a single common prefix.
						$commonPrefixes[] = $prefix . substr($rest, 0, $cut + strlen($delimiter));
						continue;
					}
				}

				$this->walk($node, $folderPrefix, $prefix, $delimiter, $after, $maxKeys, $objects, $commonPrefixes);

				if (count($objects) > $maxKeys) {
					return;
				}
				continue;
			}

			if (!($node instanceof File)) {
				continue;
			}

			if ($prefix !== '' && !str_starts_with($key, $prefix)) {
				continue;
			}

			if ($after !== '' && strcmp($key, $after) <= 0) {
				continue;
			}

			if ($delimiter !== '') {
				$rest = substr($key, strlen($prefix));
				$cut = strpos($rest, $delimiter);
				if ($cut !== false) {
					$commonPrefixes[] = $prefix . substr($rest, 0, $cut + strlen($delimiter));
					continue;
				}
			}

			// The ETag is filled in once the page is cut to size, see
			// withEtags(): the walk overshoots by one key, and that one need
			// not be hashed.
			$objects[] = [
				'key' => $key,
				'lastModified' => gmdate('Y-m-d\TH:i:s.000\Z', $node->getMTime()),
				'size' => $node->getSize(),
				'file' => $node,
			];

			if (count($objects) > $maxKeys) {
				return;
			}
		}
	}

	/**
	 * Attach an ETag to every object on a listing page.
	 *
	 * Recorded ETags are looked up for the whole page in one query. A file
	 * without one -- written through WebDAV, the web UI or the sync client,
	 * or changed there since -- needs its content hashed, and that is a read
	 * of the whole file: a page of a thousand large files written outside
	 * this API would be terabytes of I/O for one GET, a readonly key enough
	 * to ask for it in a loop. So hashing is capped per page at
	 * LISTING_HASH_BUDGET_BYTES; files past the cap get their content ETag on
	 * a later listing or on the first GET or HEAD. Reads and conditional
	 * writes always hash: they need the exact value.
	 *
	 * Until then they are listed with Nextcloud's own etag plus `-0`. The
	 * suffix matters: on some storages -- object storage as primary, for one
	 * -- Nextcloud's etag is 32 hex digits, which a client would take for the
	 * content MD5 and find wrong, and re-upload. A dash marks an S3 ETag as
	 * not an MD5 (it is how multipart ETags look), so tools skip comparing
	 * it, and zero parts is a count no real multipart object has.
	 *
	 * @param list<array{key: string, lastModified: string, size: int, file: File}> $objects
	 * @return list<array{key: string, lastModified: string, etag: string, size: int}>
	 */
	private function withEtags(array $objects): array {
		$cached = $this->etagService->cachedMany(array_column($objects, 'file'));
		$budget = self::LISTING_HASH_BUDGET_BYTES;

		$out = [];
		foreach ($objects as $obj) {
			$file = $obj['file'];
			$etag = $cached[$file->getId()] ?? null;
			if ($etag === null) {
				if ($obj['size'] <= $budget) {
					$budget -= $obj['size'];
					$etag = $this->etagService->refresh($file);
				} else {
					$etag = trim($file->getEtag(), '"') . '-0';
				}
			}

			$out[] = [
				'key' => $obj['key'],
				'lastModified' => $obj['lastModified'],
				'etag' => $etag,
				'size' => $obj['size'],
			];
		}

		return $out;
	}

	/**
	 * Whether a folder could hold keys under `$prefix`.
	 *
	 * True when the folder sits inside the prefix, or when the prefix reaches
	 * into the folder.
	 */
	private function pathMayContainPrefix(string $folderPrefix, string $prefix): bool {
		if ($prefix === '') {
			return true;
		}

		return str_starts_with($folderPrefix, $prefix) || str_starts_with($prefix, $folderPrefix);
	}

	private function resolveFolder(Folder $bucketFolder, string $relativePath): ?Folder {
		if ($relativePath === '') {
			return $bucketFolder;
		}

		// A name Nextcloud will not look up -- forbidden by the admin, reserved
		// like `.htaccess`, a character the storage cannot hold -- holds no
		// keys either. For a prefix that is an empty listing, not a failure.
		try {
			$node = $bucketFolder->get($relativePath);
		} catch (NotFoundException | InvalidPathException | NotPermittedException | ForbiddenException) {
			return null;
		}

		return $node instanceof Folder ? $node : null;
	}

	/**
	 * @param list<array{key: string, lastModified: string, etag: string, size: int}> $objects
	 */
	private function contentsXml(array $objects): string {
		$xml = '';
		foreach ($objects as $obj) {
			$xml .= '<Contents>';
			$xml .= '<Key>' . $this->escape($obj['key']) . '</Key>';
			$xml .= '<LastModified>' . $obj['lastModified'] . '</LastModified>';
			$xml .= '<ETag>"' . $this->escape($obj['etag']) . '"</ETag>';
			$xml .= '<Size>' . $obj['size'] . '</Size>';
			$xml .= '<StorageClass>STANDARD</StorageClass>';
			$xml .= '</Contents>';
		}
		return $xml;
	}

	/**
	 * @param list<string> $commonPrefixes
	 */
	private function commonPrefixesXml(array $commonPrefixes): string {
		$xml = '';
		foreach ($commonPrefixes as $cp) {
			$xml .= '<CommonPrefixes><Prefix>' . $this->escape($cp) . '</Prefix></CommonPrefixes>';
		}
		return $xml;
	}

	private function clampMaxKeys(int $maxKeys): int {
		if ($maxKeys < 1) {
			return self::MAX_KEYS_LIMIT;
		}
		return min($maxKeys, self::MAX_KEYS_LIMIT);
	}

	/**
	 * Escape a value for an XML text node or attribute.
	 *
	 * Whatever the value holds, the document has to stay parseable: one bad
	 * byte breaks the response for every client, not just the value it is
	 * in. Invalid UTF-8 is replaced rather than turned into an empty string,
	 * which htmlspecialchars() does without ENT_SUBSTITUTE, and characters
	 * XML 1.0 cannot carry at all -- control characters echoed from a
	 * request, say, in an error's `<Resource>` -- become U+FFFD as well.
	 */
	private function escape(string $value): string {
		$escaped = htmlspecialchars($value, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

		return preg_replace(
			'/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u',
			"\u{FFFD}",
			$escaped,
		) ?? '';
	}
}
