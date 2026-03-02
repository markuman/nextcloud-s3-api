<?php

declare(strict_types=1);

namespace OCA\S3Api\Service;

use OCA\S3Api\Db\Bucket;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\Node;

class S3ResponseBuilder {

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
		array $nodes,
		string $prefix,
		string $delimiter,
		string $marker,
		int $maxKeys,
		string $basePath,
	): string {
		$xml = '<?xml version="1.0" encoding="UTF-8"?>';
		$xml .= '<ListBucketResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/">';
		$xml .= '<Name>' . $this->escape($bucketName) . '</Name>';
		$xml .= '<Prefix>' . $this->escape($prefix) . '</Prefix>';
		$xml .= '<Marker>' . $this->escape($marker) . '</Marker>';
		$xml .= '<MaxKeys>' . $maxKeys . '</MaxKeys>';
		$xml .= '<Delimiter>' . $this->escape($delimiter) . '</Delimiter>';

		$objects = [];
		$commonPrefixes = [];
		$this->collectObjects($nodes, $prefix, $delimiter, $basePath, $objects, $commonPrefixes);

		// Filter by marker
		if ($marker !== '') {
			$objects = array_filter($objects, fn($o) => $o['key'] > $marker);
			$objects = array_values($objects);
		}

		$isTruncated = count($objects) > $maxKeys;
		$objects = array_slice($objects, 0, $maxKeys);

		$xml .= '<IsTruncated>' . ($isTruncated ? 'true' : 'false') . '</IsTruncated>';

		if ($isTruncated && !empty($objects)) {
			$xml .= '<NextMarker>' . $this->escape(end($objects)['key']) . '</NextMarker>';
		}

		foreach ($objects as $obj) {
			$xml .= '<Contents>';
			$xml .= '<Key>' . $this->escape($obj['key']) . '</Key>';
			$xml .= '<LastModified>' . $obj['lastModified'] . '</LastModified>';
			$xml .= '<ETag>"' . $obj['etag'] . '"</ETag>';
			$xml .= '<Size>' . $obj['size'] . '</Size>';
			$xml .= '<StorageClass>STANDARD</StorageClass>';
			$xml .= '</Contents>';
		}

		$commonPrefixes = array_unique($commonPrefixes);
		sort($commonPrefixes);
		foreach ($commonPrefixes as $cp) {
			$xml .= '<CommonPrefixes><Prefix>' . $this->escape($cp) . '</Prefix></CommonPrefixes>';
		}

		$xml .= '</ListBucketResult>';
		return $xml;
	}

	public function listObjectsV2Xml(
		string $bucketName,
		array $nodes,
		string $prefix,
		string $delimiter,
		string $startAfter,
		string $continuationToken,
		int $maxKeys,
		string $basePath,
	): string {
		$xml = '<?xml version="1.0" encoding="UTF-8"?>';
		$xml .= '<ListBucketResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/">';
		$xml .= '<Name>' . $this->escape($bucketName) . '</Name>';
		$xml .= '<Prefix>' . $this->escape($prefix) . '</Prefix>';
		$xml .= '<MaxKeys>' . $maxKeys . '</MaxKeys>';
		$xml .= '<Delimiter>' . $this->escape($delimiter) . '</Delimiter>';
		$xml .= '<KeyCount>';

		$objects = [];
		$commonPrefixes = [];
		$this->collectObjects($nodes, $prefix, $delimiter, $basePath, $objects, $commonPrefixes);

		// Filter by start-after or continuation-token
		$filterAfter = $continuationToken !== '' ? $continuationToken : $startAfter;
		if ($filterAfter !== '') {
			$objects = array_filter($objects, fn($o) => $o['key'] > $filterAfter);
			$objects = array_values($objects);
		}

		$isTruncated = count($objects) > $maxKeys;
		$objects = array_slice($objects, 0, $maxKeys);

		// Replace KeyCount placeholder
		$keyCount = count($objects);
		$xml .= $keyCount . '</KeyCount>';

		$xml .= '<IsTruncated>' . ($isTruncated ? 'true' : 'false') . '</IsTruncated>';

		if ($continuationToken !== '') {
			$xml .= '<ContinuationToken>' . $this->escape($continuationToken) . '</ContinuationToken>';
		}
		if ($isTruncated && !empty($objects)) {
			$xml .= '<NextContinuationToken>' . $this->escape(end($objects)['key']) . '</NextContinuationToken>';
		}
		if ($startAfter !== '') {
			$xml .= '<StartAfter>' . $this->escape($startAfter) . '</StartAfter>';
		}

		foreach ($objects as $obj) {
			$xml .= '<Contents>';
			$xml .= '<Key>' . $this->escape($obj['key']) . '</Key>';
			$xml .= '<LastModified>' . $obj['lastModified'] . '</LastModified>';
			$xml .= '<ETag>"' . $obj['etag'] . '"</ETag>';
			$xml .= '<Size>' . $obj['size'] . '</Size>';
			$xml .= '<StorageClass>STANDARD</StorageClass>';
			$xml .= '</Contents>';
		}

		$commonPrefixes = array_unique($commonPrefixes);
		sort($commonPrefixes);
		foreach ($commonPrefixes as $cp) {
			$xml .= '<CommonPrefixes><Prefix>' . $this->escape($cp) . '</Prefix></CommonPrefixes>';
		}

		$xml .= '</ListBucketResult>';
		return $xml;
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

	private function collectObjects(
		array $nodes,
		string $prefix,
		string $delimiter,
		string $basePath,
		array &$objects,
		array &$commonPrefixes,
	): void {
		foreach ($nodes as $node) {
			$relativePath = $this->getRelativePath($node, $basePath);

			if ($prefix !== '' && !str_starts_with($relativePath, $prefix)) {
				continue;
			}

			if ($node instanceof Folder) {
				if ($delimiter !== '') {
					// Add as common prefix
					$commonPrefixes[] = $relativePath . '/';
				}
				// Recurse into subfolder
				$this->collectObjectsRecursive($node, $prefix, $delimiter, $basePath, $objects, $commonPrefixes);
			} elseif ($node instanceof File) {
				if ($delimiter !== '') {
					$afterPrefix = substr($relativePath, strlen($prefix));
					if (str_contains($afterPrefix, $delimiter)) {
						// This object is in a "subdirectory", add common prefix
						$delimPos = strpos($afterPrefix, $delimiter);
						$commonPrefixes[] = $prefix . substr($afterPrefix, 0, $delimPos + strlen($delimiter));
						continue;
					}
				}

				$objects[] = [
					'key' => $relativePath,
					'lastModified' => gmdate('Y-m-d\TH:i:s.000\Z', $node->getMTime()),
					'etag' => $node->getEtag(),
					'size' => $node->getSize(),
				];
			}
		}

		usort($objects, fn($a, $b) => strcmp($a['key'], $b['key']));
	}

	private function collectObjectsRecursive(
		Folder $folder,
		string $prefix,
		string $delimiter,
		string $basePath,
		array &$objects,
		array &$commonPrefixes,
	): void {
		if ($delimiter !== '') {
			// With delimiter, we don't recurse deeper (common prefixes handle it)
			return;
		}

		foreach ($folder->getDirectoryListing() as $node) {
			$relativePath = $this->getRelativePath($node, $basePath);

			if ($prefix !== '' && !str_starts_with($relativePath, $prefix)) {
				continue;
			}

			if ($node instanceof Folder) {
				$this->collectObjectsRecursive($node, $prefix, $delimiter, $basePath, $objects, $commonPrefixes);
			} elseif ($node instanceof File) {
				$objects[] = [
					'key' => $relativePath,
					'lastModified' => gmdate('Y-m-d\TH:i:s.000\Z', $node->getMTime()),
					'etag' => $node->getEtag(),
					'size' => $node->getSize(),
				];
			}
		}
	}

	private function getRelativePath(Node $node, string $basePath): string {
		$fullPath = $node->getPath();
		if (str_starts_with($fullPath, $basePath . '/')) {
			return substr($fullPath, strlen($basePath) + 1);
		}
		return $node->getName();
	}

	private function escape(string $value): string {
		return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
	}
}
