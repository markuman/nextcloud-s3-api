<?php

declare(strict_types=1);

namespace OCA\S3Api\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Record Nextcloud's own etag next to each content ETag.
 *
 * A recorded ETag was taken as current while the file kept its size and
 * mtime. A change that keeps both -- a client that sets the mtime, a write
 * within the same second -- went unnoticed, and If-Match then matched a
 * version that no longer exists. Nextcloud gives a file a new etag on every
 * write it sees, so that is the third thing compared.
 *
 * Nullable: rows from before have none, and are trusted on size and mtime
 * once more, as before, and completed on that read (EtagService). Filling
 * them in here would mean hashing every file again.
 */
class Version1007003Date20261116000000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		$table = $schema->getTable('s3api_etags');
		if ($table->hasColumn('nc_etag')) {
			return null;
		}

		// filecache.etag holds 40 characters.
		$table->addColumn('nc_etag', Types::STRING, [
			'notnull' => false,
			'length' => 40,
		]);

		return $schema;
	}
}
