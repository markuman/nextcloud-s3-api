<?php

declare(strict_types=1);

namespace OCA\S3Api\Service;

use OCA\S3Api\Db\Upload;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\NotFoundException;

class S3ResponseBuilder {

	/** S3 never returns more than this, whatever the client asks for. */
	private const MAX_KEYS_LIMIT = 1000;

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
	 * @param array<int, array{number: int, size: int, etag: string, mtime: int}> $parts
	 */
	public function listPartsXml(string $bucketName, string $key, string $uploadId, array $parts): string {
		$xml = '<?xml version="1.0" encoding="UTF-8"?>';
		$xml .= '<ListPartsResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/">';
		$xml .= '<Bucket>' . $this->escape($bucketName) . '</Bucket>';
		$xml .= '<Key>' . $this->escape($key) . '</Key>';
		$xml .= '<UploadId>' . $this->escape($uploadId) . '</UploadId>';
		$xml .= '<StorageClass>STANDARD</StorageClass>';
		$xml .= '<PartNumberMarker>0</PartNumberMarker>';
		$xml .= '<MaxParts>1000</MaxParts>';
		$xml .= '<IsTruncated>false</IsTruncated>';

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
	 * @param Upload[] $uploads
	 */
	public function listMultipartUploadsXml(string $bucketName, array $uploads): string {
		$xml = '<?xml version="1.0" encoding="UTF-8"?>';
		$xml .= '<ListMultipartUploadsResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/">';
		$xml .= '<Bucket>' . $this->escape($bucketName) . '</Bucket>';
		$xml .= '<KeyMarker></KeyMarker>';
		$xml .= '<UploadIdMarker></UploadIdMarker>';
		$xml .= '<MaxUploads>1000</MaxUploads>';
		$xml .= '<IsTruncated>false</IsTruncated>';

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
		// like "repos/acme/" does not walk every sibling.
		$literal = $prefix === '' ? '' : substr($prefix, 0, (int)strrpos($prefix, '/') + 1);
		$start = $bucketFolder;
		if ($literal !== '') {
			$resolved = $this->resolveFolder($bucketFolder, rtrim($literal, '/'));
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
			'objects' => $objects,
			'commonPrefixes' => $commonPrefixes,
			'truncated' => $truncated,
			'next' => $next,
		];
	}

	/**
	 * @param list<array{key: string, lastModified: string, etag: string, size: int}> $objects
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

			$objects[] = [
				'key' => $key,
				'lastModified' => gmdate('Y-m-d\TH:i:s.000\Z', $node->getMTime()),
				'etag' => $node->getEtag(),
				'size' => $node->getSize(),
			];

			if (count($objects) > $maxKeys) {
				return;
			}
		}
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

		try {
			$node = $bucketFolder->get($relativePath);
		} catch (NotFoundException) {
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

	private function escape(string $value): string {
		return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
	}
}
