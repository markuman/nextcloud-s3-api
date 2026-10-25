<?php

declare(strict_types=1);

namespace OCA\S3Api\Command;

use OCA\S3Api\Db\Bucket;
use OCA\S3Api\Service\BucketConflictException;
use OCA\S3Api\Service\BucketService;
use OCP\DB\Exception as DbException;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\ISetupManager;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\Log\Audit\CriticalActionPerformedEvent;
use OCP\Server;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Pre-provision bucket configs, so an admin can roll the app out to many
 * users without each of them clicking through the settings:
 *
 *   occ s3api:bucket:create --user alice --folder /S3 --name files --create-folder
 *   occ s3api:bucket:create --group staff --folder /S3 --name files --create-folder
 *
 * A group expands to its members at the time the command runs. No API keys
 * are created — the users create their own on the bucket that then shows up
 * in their settings.
 *
 * Re-running is safe: a user whose bucket of that name already serves that
 * folder is skipped. Any other clash — the name on another folder, the folder
 * inside another bucket — is an error for that user, and nothing is created
 * for them, not even the folder.
 *
 * An incoming share at the path is refused unless --allow-shared is given:
 * whoever shares a folder named like the one the admin picked with the group
 * would otherwise receive everything its members upload.
 */
class BucketCreate extends Command {

	public function __construct(
		private BucketService $bucketService,
		private IUserManager $userManager,
		private IGroupManager $groupManager,
		private IRootFolder $rootFolder,
		private IEventDispatcher $eventDispatcher,
		private LoggerInterface $logger,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this
			->setName('s3api:bucket:create')
			->setDescription('Create a bucket config for users, or for every member of a group. API keys are not created; users create their own in the personal settings.')
			->addOption('user', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'User id to create the bucket for (repeatable, comma-separated)')
			->addOption('group', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Group id; the bucket is created for every current member (repeatable, comma-separated)')
			->addOption('folder', null, InputOption::VALUE_REQUIRED, 'Folder path relative to the user\'s home, e.g. /S3 or Documents/shared; not / (the whole home)')
			->addOption('name', null, InputOption::VALUE_REQUIRED, 'Bucket name, e.g. files')
			->addOption('create-folder', null, InputOption::VALUE_NONE, 'Create the folder in each user\'s home when it does not exist')
			->addOption('allow-shared', null, InputOption::VALUE_NONE, 'Accept a folder that is, or lies in, a share from another user (refused by default)');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$folder = $input->getOption('folder');
		$name = $input->getOption('name');
		$createFolder = (bool)$input->getOption('create-folder');
		$allowShared = (bool)$input->getOption('allow-shared');

		if (!is_string($folder) || $folder === '' || !is_string($name) || $name === '') {
			$this->say($output, 'error', '--folder and --name are required');
			return Command::FAILURE;
		}

		// Everything that can be checked once is checked before anything is
		// created for anyone.
		try {
			$this->bucketService->validateBucketName($name);
			$path = $this->folderPath($folder);
		} catch (\InvalidArgumentException $e) {
			$this->say($output, 'error', $e->getMessage());
			return Command::FAILURE;
		}

		$targets = $this->targets($input, $output);
		if ($targets === null) {
			$this->say($output, 'error', 'Nothing was created');
			return Command::FAILURE;
		}
		if ($targets === []) {
			$this->say($output, 'error', 'No users to create the bucket for: the groups given have no members');
			return Command::FAILURE;
		}

		$created = 0;
		$failures = 0;
		foreach ($targets as $uid) {
			try {
				$bucket = $this->provision($uid, $path, $name, $createFolder, $allowShared);
				if ($bucket === null) {
					$this->say($output, 'comment', "Skipped {$uid}: bucket \"{$name}\" already serves {$path}");
					continue;
				}
				$this->audit('S3 bucket "%s" created on folder "%s" for user "%s" via occ', [
					'bucket' => $bucket->getBucketName(),
					'folder' => $bucket->getFolderPath(),
					'user' => $uid,
				]);
				$this->say($output, null, "Created bucket \"{$name}\" for {$uid} on {$bucket->getFolderPath()}");
				$created++;
			} catch (\InvalidArgumentException | BucketConflictException $e) {
				$this->say($output, 'error', "{$uid}: {$e->getMessage()}");
				$failures++;
			} catch (DbException $e) {
				// Only a concurrent run gets here: the name check came first.
				if ($e->getReason() !== DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
					$this->logger->error('Failed to create S3 bucket via occ', ['app' => 's3_api', 'user' => $uid, 'exception' => $e]);
				}
				$this->say($output, 'error', "{$uid}: {$e->getMessage()}");
				$failures++;
			} catch (\Throwable $e) {
				$this->logger->error('Failed to create S3 bucket via occ', ['app' => 's3_api', 'user' => $uid, 'exception' => $e]);
				$this->say($output, 'error', "{$uid}: {$e->getMessage()}");
				$failures++;
			} finally {
				$this->tearDownFilesystem();
			}
		}

		if ($created > 0) {
			$this->say($output, 'info', 'Users create their API keys in Settings → Personal → S3 API.');
		}

		return $failures > 0 ? Command::FAILURE : Command::SUCCESS;
	}

	/**
	 * The bucket for one user: null when it is already there, as asked.
	 *
	 * Every conflict is checked before the folder is created, so a refusal
	 * leaves the home as it was.
	 *
	 * @param string $path normalised, see folderPath()
	 * @throws \InvalidArgumentException|BucketConflictException
	 */
	private function provision(string $uid, string $path, string $name, bool $create, bool $allowShared): ?Bucket {
		$userFolder = $this->rootFolder->getUserFolder($uid);

		$existing = $this->bucketService->getBucketByName($uid, $name);
		if ($existing !== null) {
			$folder = $this->bucketService->resolveFolder($existing);
			if ($folder === null) {
				throw new BucketConflictException("A bucket named \"{$name}\" already exists, but its folder is gone (deleted, or in the trash)");
			}
			$current = $this->bucketService->relativePath($uid, $folder);
			if (BucketService::normalizePath($current) === $path) {
				return null;
			}
			throw new BucketConflictException("A bucket named \"{$name}\" already exists on {$current}");
		}

		$this->bucketService->validateFolderNotAlreadyBucket($uid, $path);

		$folder = $this->ensureFolder($userFolder, $path, $create, $allowShared);
		return $this->bucketService->createBucketOnFolder($uid, $folder, $name);
	}

	/**
	 * The users to provision, deduplicated, or null when a user or group does
	 * not exist — then nothing is created for anyone, so a typo does not
	 * leave a half-done rollout behind.
	 *
	 * @return string[]|null
	 */
	private function targets(InputInterface $input, OutputInterface $output): ?array {
		$targets = [];
		$missing = false;

		foreach ($this->ids($input->getOption('user')) as $uid) {
			$user = $this->userManager->get($uid);
			if ($user === null) {
				$this->say($output, 'error', "User not found: {$uid}");
				$missing = true;
				continue;
			}
			$targets[$user->getUID()] = true;
		}

		foreach ($this->ids($input->getOption('group')) as $gid) {
			$group = $this->groupManager->get($gid);
			if ($group === null) {
				$this->say($output, 'error', "Group not found: {$gid}");
				$missing = true;
				continue;
			}
			$members = $group->getUsers();
			if ($members === []) {
				$this->say($output, 'comment', "Group {$gid} has no members");
			}
			foreach ($members as $user) {
				$targets[$user->getUID()] = true;
			}
		}

		return $missing ? null : array_map('strval', array_keys($targets));
	}

	/**
	 * Option values as a flat list of ids: --user a --user b and --user a,b
	 * both work.
	 *
	 * @param mixed $value
	 * @return string[]
	 */
	private function ids($value): array {
		$ids = [];
		foreach ((array)$value as $item) {
			foreach (explode(',', (string)$item) as $id) {
				$id = trim($id);
				if ($id !== '') {
					$ids[] = $id;
				}
			}
		}
		return $ids;
	}

	/**
	 * The folder option in the shape the service compares paths in. `..` is
	 * refused rather than resolved: in a path meant for many homes it is a
	 * mistake, not a shortcut.
	 *
	 * The home itself is refused too: a bucket there overlaps every other
	 * folder and blocks all further buckets of each user it is created for.
	 * The settings page allows it when typed on purpose; for a whole group at
	 * once it is a typo far more often than a plan.
	 */
	private function folderPath(string $folder): string {
		if (in_array('..', explode('/', str_replace('\\', '/', $folder)), true)) {
			throw new \InvalidArgumentException("Folder path must not contain \"..\": {$folder}");
		}
		$path = BucketService::normalizePath($folder);
		if ($path === '/') {
			throw new \InvalidArgumentException("--folder {$folder} is the whole home folder; a bucket there blocks every other bucket. Users who really want that can register it in their personal settings");
		}
		return $path;
	}

	/**
	 * Walk the folder path inside the user's home, creating missing segments
	 * when asked to.
	 *
	 * Every existing segment is checked for being a share before anything is
	 * created below it: a folder created inside someone else's share is that
	 * someone's folder.
	 *
	 * @param string $path normalised, see folderPath()
	 */
	private function ensureFolder(Folder $userFolder, string $path, bool $create, bool $allowShared): Folder {
		$folder = $userFolder;
		$walked = '';
		foreach (array_filter(explode('/', $path), fn($s) => $s !== '') as $segment) {
			$walked .= '/' . $segment;

			if (!$folder->nodeExists($segment)) {
				if (!$create) {
					throw new \InvalidArgumentException("Folder does not exist: {$walked} (use --create-folder to create it)");
				}
				$folder = $folder->newFolder($segment);
				continue;
			}

			$node = $folder->get($segment);
			if (!($node instanceof Folder)) {
				throw new \InvalidArgumentException("Path is not a folder: {$walked}");
			}
			if (!$allowShared && BucketService::isShared($node)) {
				throw new \InvalidArgumentException("{$walked} is a share from another user; refusing to bind the bucket to it (use --allow-shared if that is intended)");
			}
			$folder = $node;
		}
		return $folder;
	}

	/**
	 * Free the user's mounts before the next one: a group of thousands would
	 * otherwise keep every member's filesystem in memory.
	 *
	 * ISetupManager is public from 34 on; 33 only has the private helper.
	 */
	private function tearDownFilesystem(): void {
		if (interface_exists(ISetupManager::class)) {
			Server::get(ISetupManager::class)->tearDown();
		} elseif (method_exists(\OC_Util::class, 'tearDownFS')) {
			\OC_Util::tearDownFS();
		}
	}

	/**
	 * One line of output. Ids, paths and messages are escaped: a folder named
	 * `<info>` is a folder name, not markup.
	 */
	private function say(OutputInterface $output, ?string $style, string $text): void {
		$text = OutputFormatter::escape($text);
		$output->writeln($style === null ? $text : "<{$style}>{$text}</{$style}>");
	}

	/**
	 * Record a created bucket: in nextcloud.log at info level, and in
	 * admin_audit's audit.log when that app is enabled — as the settings API
	 * does for the same change.
	 *
	 * @param array<string, string> $parameters filled into `$message` in order
	 */
	private function audit(string $message, array $parameters): void {
		$this->logger->info(vsprintf($message, array_values($parameters)), ['app' => 's3_api'] + $parameters);
		$this->eventDispatcher->dispatchTyped(new CriticalActionPerformedEvent($message, $parameters));
	}
}
