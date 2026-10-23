<?php

declare(strict_types=1);

namespace OCA\S3Api\Service;

/**
 * Which keys can exist in a bucket.
 *
 * S3 keys are opaque byte strings, but here they become file paths below the
 * bucket folder, and Nextcloud resolves those: it folds `//`, `./` and `\`
 * into another path and refuses `..`. A key that relies on any of that would
 * be a second name for some other file -- with a lock of its own -- or no
 * name at all. The controller refuses such keys outright; a listing prefix,
 * which S3 allows to be any string, simply matches nothing with them.
 *
 * One place for both, so they cannot drift apart.
 */
final class KeyRules {

	/** S3's limit on a key, in UTF-8 bytes. */
	public const MAX_KEY_BYTES = 1024;

	/** The longest file or folder name a filesystem takes, in bytes. */
	public const MAX_NAME_BYTES = 255;

	/**
	 * Whether `$path` is a `/`-separated sequence of plain names, without a
	 * leading or trailing slash.
	 */
	public static function isPlainPath(string $path): bool {
		if (strlen($path) > self::MAX_KEY_BYTES) {
			return false;
		}

		foreach (explode('/', $path) as $segment) {
			if (!self::isPlainSegment($segment)) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Whether `$segment` can be one file or folder name as it stands.
	 *
	 * Empty segments come from `a//b`, `.` and `..` would resolve to another
	 * folder. Nextcloud reads a backslash as a path separator and drops NUL
	 * bytes, so either makes the name stand for a different one. Filesystems
	 * stop at 255 bytes, which Nextcloud reports as a permission problem.
	 */
	public static function isPlainSegment(string $segment): bool {
		return $segment !== ''
			&& $segment !== '.'
			&& $segment !== '..'
			&& !str_contains($segment, '\\')
			&& !str_contains($segment, "\0")
			&& strlen($segment) <= self::MAX_NAME_BYTES;
	}

	/**
	 * Whether `$path` is the multipart scratch area at the bucket root, or
	 * inside it. Internal: not addressable as a key, not listable.
	 */
	public static function isInScratchArea(string $path): bool {
		return $path === MultipartService::SCRATCH_DIR
			|| str_starts_with($path, MultipartService::SCRATCH_DIR . '/');
	}
}
