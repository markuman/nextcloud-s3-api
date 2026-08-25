<?php

declare(strict_types=1);

namespace OCA\S3Api\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * A mutex for conditional writes.
 *
 * Conditional PUTs have to read the current ETag and write in one indivisible
 * step, otherwise two concurrent `If-None-Match: *` requests can both observe
 * "absent" and both succeed. `ILockingProvider` cannot be relied on for this:
 * without a configured distributed cache the server installs
 * NoopLockingProvider, which locks nothing.
 *
 * A unique index is atomic in every supported database, so inserting a row is
 * the lock acquisition and deleting it is the release.
 */
class Version1002000Date20260825120000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('s3api_locks')) {
			$table = $schema->createTable('s3api_locks');
			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
				'unsigned' => true,
			]);
			// Hash of bucket id + object key: keys can exceed what an indexed
			// column may hold, and a fixed width keeps the index small.
			$table->addColumn('lock_key', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('acquired_at', Types::BIGINT, [
				'notnull' => true,
				'unsigned' => true,
			]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['lock_key'], 's3api_lock_key');
		}

		return $schema;
	}
}
