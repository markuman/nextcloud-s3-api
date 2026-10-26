<?php

declare(strict_types=1);

namespace OCA\S3Api\Migration;

use Closure;
use OCA\S3Api\Db\BucketMapper;
use OCA\S3Api\Service\BucketService;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Bind each bucket to its folder's file id instead of its path.
 *
 * The path was checked when the bucket was created and never again. Rename
 * the folder and the bucket answers NoSuchBucket; put something else under
 * the old name -- a new folder, or a share from another user, which Nextcloud
 * mounts under its own name -- and the bucket's keys read and write that
 * instead.
 *
 * Existing buckets are bound to what their path names now, by the same rule
 * as on first use (BucketService::bindLegacyBucket()): an incoming share is
 * not taken, since a share mounted under the old name is exactly what the
 * attack looks like. A bucket whose folder cannot be found, or is a share,
 * keeps a null id and a warning; BucketService tries again on first use.
 */
class Version1006002Date20261024000000 extends SimpleMigrationStep {

	public function __construct(
		private BucketMapper $bucketMapper,
		private BucketService $bucketService,
	) {
	}

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		$table = $schema->getTable('s3api_buckets');
		if ($table->hasColumn('folder_id')) {
			return null;
		}

		$table->addColumn('folder_id', Types::BIGINT, [
			'notnull' => false,
			'unsigned' => true,
		]);

		return $schema;
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		foreach ($this->bucketMapper->findUnbound() as $bucket) {
			try {
				$folder = $this->bucketService->resolveFolder($bucket);
			} catch (\Throwable $e) {
				$output->warning('S3 bucket "' . $bucket->getBucketName() . '" of ' . $bucket->getUserId()
					. ': could not resolve its folder, will retry on first use: ' . $e->getMessage());
				continue;
			}

			if ($folder === null) {
				$output->warning('S3 bucket "' . $bucket->getBucketName() . '" of ' . $bucket->getUserId()
					. ': folder ' . $bucket->getFolderPath() . ' not found or an incoming share; not bound.'
					. ' A missing folder is retried on first use, a share has to be registered again');
			}
		}
	}
}
