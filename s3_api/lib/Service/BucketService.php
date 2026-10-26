<?php

declare(strict_types=1);

namespace OCA\S3Api\Service;

use DateTime;
use OCA\S3Api\Db\Bucket;
use OCA\S3Api\Db\BucketMapper;
use OCA\S3Api\Db\ApiKeyMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\Files\Storage\ISharedStorage;
use Psr\Log\LoggerInterface;

class BucketService {

	public function __construct(
		private BucketMapper $bucketMapper,
		private ApiKeyMapper $apiKeyMapper,
		private IRootFolder $rootFolder,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @return Bucket[]
	 */
	public function listBuckets(string $userId): array {
		return $this->bucketMapper->findByUserId($userId);
	}

	/**
	 * The user's buckets, each with the path its folder has now.
	 *
	 * The stored path is only refreshed when the bucket is used, so after a
	 * rename the settings page would show the old one until then.
	 *
	 * @return Bucket[]
	 */
	public function listBucketsWithCurrentPaths(string $userId): array {
		$buckets = $this->listBuckets($userId);
		foreach ($buckets as $bucket) {
			$this->resolveFolder($bucket);
		}
		return $buckets;
	}

	public function getBucketByName(string $userId, string $bucketName): ?Bucket {
		try {
			return $this->bucketMapper->findByUserAndName($userId, $bucketName);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	public function createBucket(string $userId, string $folderPath, string $bucketName): Bucket {
		$this->validateBucketName($bucketName);
		return $this->createBucketOnFolder($userId, $this->existingFolder($userId, $folderPath), $bucketName);
	}

	/**
	 * createBucket() for a folder already resolved in the user's home -- the
	 * occ command walks the path itself, since it may create it.
	 *
	 * The conflict checks run again here even if the caller already ran them
	 * (the occ command does, before creating the folder): this is the last
	 * point before the insert, and cheap compared to a wrong bucket.
	 *
	 * @throws BucketConflictException
	 */
	public function createBucketOnFolder(string $userId, Folder $folder, string $bucketName): Bucket {
		$this->validateBucketName($bucketName);
		$this->validateNameIsFree($userId, $bucketName);

		$path = $this->relativePath($userId, $folder);
		$this->validateFolderNotAlreadyBucket($userId, $path);

		$bucket = new Bucket();
		$bucket->setUserId($userId);
		$bucket->setFolderPath($path);
		$bucket->setFolderId($folder->getId());
		$bucket->setBucketName($bucketName);
		$bucket->setCreatedAt(new DateTime());

		return $this->bucketMapper->insert($bucket);
	}

	public function deleteBucket(int $id, string $userId): Bucket {
		$bucket = $this->bucketMapper->findByIdAndUser($id, $userId);
		$this->apiKeyMapper->deleteByBucketId($bucket->getId());
		$this->bucketMapper->delete($bucket);
		return $bucket;
	}

	/**
	 * The folder a bucket serves, or null when it is gone.
	 *
	 * Resolved by file id, inside the owner's home: the bucket follows its
	 * folder through renames and moves, and a folder that merely has the old
	 * name -- a new one, or a share from another user mounted there -- is not
	 * it. Moved to the trash, the folder is outside the home and the bucket
	 * answers NoSuchBucket until it is restored.
	 *
	 * A bucket from before 1.6.2 is bound here, on its path -- by the
	 * migration, or on first use if the folder could not be found then. See
	 * bindLegacyBucket() for which folders that may be.
	 */
	public function resolveFolder(Bucket $bucket): ?Folder {
		$userId = $bucket->getUserId();
		try {
			$userFolder = $this->rootFolder->getUserFolder($userId);
		} catch (\Throwable) {
			return null;
		}

		$folderId = $bucket->getFolderId();
		if ($folderId === null) {
			return $this->bindLegacyBucket($bucket, $userFolder);
		}

		$node = $userFolder->getFirstNodeById($folderId);
		if (!($node instanceof Folder)) {
			return null;
		}

		// Keep the displayed path in step with the folder.
		$path = $userFolder->getRelativePath($node->getPath());
		if ($path !== null && $path !== $bucket->getFolderPath()) {
			$bucket->setFolderPath($path);
			$this->bucketMapper->update($bucket);
		}

		return $node;
	}

	/**
	 * Bind a path-bound bucket to the folder its path names now.
	 *
	 * The path is all a bucket from before 1.6.2 has, and it may no longer
	 * name the folder that was registered: renamed away, and something else
	 * put in its place. Only one thing there is someone else's doing -- an
	 * incoming share, which another user creates and Nextcloud mounts under
	 * its own name without asking. Binding the bucket to that would make the
	 * attack this binding is there to stop permanent, so a share is never
	 * taken by its path; such a bucket has to be registered again.
	 *
	 * Up to 1.7.0 a share older than the bucket was taken. The share's age
	 * says nothing about when it appeared at the path, though: a group share
	 * from before the bucket shows up when its recipient joins the group,
	 * which can be long after.
	 *
	 * Anything else at the path -- the user's own folders, group folders,
	 * external storage -- is placed there by the user or an admin and is
	 * taken as it is.
	 */
	private function bindLegacyBucket(Bucket $bucket, Folder $userFolder): ?Folder {
		try {
			$node = $userFolder->get($bucket->getFolderPath());
		} catch (NotFoundException | NotPermittedException) {
			return null;
		}

		if (!($node instanceof Folder)) {
			return null;
		}

		if (self::isShared($node)) {
			// Info, not warning: the settings page resolves every bucket on
			// each load, so this would repeat for as long as the bucket
			// exists. The migration already warned once, where the admin
			// looks during an upgrade.
			$this->logger->info('Not binding S3 bucket to an incoming share by its path; register the folder again', [
				'app' => 's3_api',
				'bucket' => $bucket->getBucketName(),
				'userId' => $bucket->getUserId(),
				'folderPath' => $bucket->getFolderPath(),
			]);
			return null;
		}

		$bucket->setFolderId($node->getId());
		$this->bucketMapper->update($bucket);
		$this->logger->info('Bound S3 bucket to its folder', [
			'app' => 's3_api',
			'bucket' => $bucket->getBucketName(),
			'userId' => $bucket->getUserId(),
			'folderId' => $node->getId(),
		]);

		return $node;
	}

	/**
	 * Whether a folder is, or lies inside, an incoming share -- something
	 * another user put into this home, under a name of their choosing.
	 */
	public static function isShared(Folder $node): bool {
		$storage = $node->getStorage();
		// Two interfaces, and both are needed: internal shares implement the
		// public one, federated shares only the files_sharing marker. A
		// string, so nothing breaks with files_sharing disabled.
		return $storage->instanceOfStorage(ISharedStorage::class)
			|| $storage->instanceOfStorage('OCA\\Files_Sharing\\ISharedStorage');
	}

	/**
	 * The name has a unique index on (user_id, bucket_name), so the database
	 * would catch this anyway — with a driver exception that surfaced as a 500
	 * carrying raw SQL. Checking here turns the common mistake into a 409.
	 *
	 * This is not a substitute for the index: two simultaneous requests can
	 * both pass the check, and the insert is what actually decides. The
	 * controller maps that collision to the same 409.
	 *
	 * @throws BucketConflictException
	 */
	private function validateNameIsFree(string $userId, string $bucketName): void {
		if ($this->getBucketByName($userId, $bucketName) !== null) {
			throw new BucketConflictException('A bucket named "' . $bucketName . '" already exists');
		}
	}

	/**
	 * Refuse a folder that is already served by another bucket, and refuse one
	 * that nests with an existing bucket's folder.
	 *
	 * Two names for one file are not merely redundant: a conditional write
	 * takes its lock on the object's resolved path, and while that now makes
	 * the two names share a lock, the rest of the app still treats them as
	 * separate buckets — separate multipart scratch areas under the same
	 * directory, and two ETag rows racing over one file id. Overlapping
	 * buckets have no use case that a single bucket with a key prefix does not
	 * cover better.
	 *
	 * Compared on where the folders are now, not on the paths stored when
	 * they were registered: a bucket's folder may have been renamed since,
	 * and its old path then says nothing about what it contains.
	 *
	 * Public for the occ command, which checks before it creates a folder that
	 * would then go unused.
	 *
	 * @param string $path the new folder, relative to the home; normalised here
	 * @throws BucketConflictException
	 */
	public function validateFolderNotAlreadyBucket(string $userId, string $path): void {
		$needle = self::normalizePath($path);

		foreach ($this->listBuckets($userId) as $other) {
			$folder = $this->resolveFolder($other);
			$otherPath = $folder === null
				? $other->getFolderPath()
				: $this->relativePath($userId, $folder);

			if (self::pathsOverlap(self::normalizePath($otherPath), $needle)) {
				throw new BucketConflictException(
					'Folder overlaps with bucket "' . $other->getBucketName()
					. '" (' . $otherPath . '). Use one bucket and address the'
					. ' subfolder with a key prefix.',
				);
			}
		}
	}

	/**
	 * Whether two normalised paths name the same folder or one contains the
	 * other.
	 *
	 * Comparison is on whole segments — `/Documents` and `/Documents2` share a
	 * string prefix but are unrelated folders, so a plain str_starts_with on
	 * the bare paths would be wrong. Appending the separator to both sides is
	 * what makes the boundary explicit.
	 */
	private static function pathsOverlap(string $a, string $b): bool {
		if ($a === $b) {
			return true;
		}

		// The home root contains every path, and normalises to "/" — for which
		// the trailing-separator trick degenerates into "//" and matches
		// nothing. Special-cased rather than papered over, because a bucket on
		// the whole home is a legitimate thing to have registered.
		if ($a === '/' || $b === '/') {
			return true;
		}

		return str_starts_with($a . '/', $b . '/')
			|| str_starts_with($b . '/', $a . '/');
	}

	/**
	 * A path in one comparable shape: leading slash, no trailing slash, no
	 * empty, `.` or `..` segments, Unicode NFC.
	 *
	 * Paths resolved through Nextcloud already have that shape; this is for
	 * the stored path of a bucket whose folder is gone, which may be from
	 * before paths were stored the way Nextcloud spells them. `Documents`,
	 * `/Documents/`, `/Documents//sub` and `/Documents/./sub` all name what
	 * Nextcloud would resolve them to, and so does an NFD spelling, since
	 * Nextcloud normalises every path to NFC.
	 */
	public static function normalizePath(string $path): string {
		if (class_exists(\Normalizer::class)) {
			$path = \Normalizer::normalize($path, \Normalizer::FORM_C) ?: $path;
		}

		$segments = [];
		foreach (explode('/', str_replace('\\', '/', $path)) as $segment) {
			if ($segment === '' || $segment === '.') {
				continue;
			}
			if ($segment === '..') {
				array_pop($segments);
				continue;
			}
			$segments[] = $segment;
		}

		return '/' . implode('/', $segments);
	}

	/**
	 * Public for the occ command, which validates the name once before it
	 * creates anything for anyone.
	 */
	public function validateBucketName(string $name): void {
		if (strlen($name) < 3 || strlen($name) > 63) {
			throw new \InvalidArgumentException('Bucket name must be between 3 and 63 characters');
		}
		if (!preg_match('/^[a-z0-9][a-z0-9.\-]*[a-z0-9]$/', $name)) {
			throw new \InvalidArgumentException('Bucket name must be DNS-compatible (lowercase alphanumeric, dots, hyphens)');
		}
		if (preg_match('/\.\./', $name)) {
			throw new \InvalidArgumentException('Bucket name must not contain consecutive dots');
		}
	}

	private function existingFolder(string $userId, string $folderPath): Folder {
		$userFolder = $this->rootFolder->getUserFolder($userId);
		if (!$userFolder->nodeExists($folderPath)) {
			throw new \InvalidArgumentException('Folder does not exist: ' . $folderPath);
		}
		$node = $userFolder->get($folderPath);
		if (!($node instanceof Folder)) {
			throw new \InvalidArgumentException('Path is not a folder: ' . $folderPath);
		}
		return $node;
	}

	/**
	 * A folder's path relative to the user's home, as Nextcloud spells it:
	 * `/` for the home itself, `/Documents/sub` below it.
	 */
	public function relativePath(string $userId, Folder $folder): string {
		$path = $this->rootFolder->getUserFolder($userId)->getRelativePath($folder->getPath());
		return $path === null || $path === '' ? '/' : $path;
	}
}
