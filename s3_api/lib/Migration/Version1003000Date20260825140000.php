<?php

declare(strict_types=1);

namespace OCA\S3Api\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Content-derived ETags.
 *
 * Nextcloud's own etag is `md5(mtime + inode + dev + size)`, and mtime has
 * one-second resolution, so two writes of equally sized but different content
 * within the same second produce the same etag. S3 clients use the ETag as an
 * opaque version for compare-and-swap, so that collision lets a lost update
 * report success.
 *
 * This table holds an MD5 of the actual bytes per file, which changes whenever
 * the content does.
 */
class Version1003000Date20260825140000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('s3api_etags')) {
			$table = $schema->createTable('s3api_etags');
			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
				'unsigned' => true,
			]);
			$table->addColumn('file_id', Types::BIGINT, [
				'notnull' => true,
				'unsigned' => true,
			]);
			$table->addColumn('etag', Types::STRING, [
				'notnull' => true,
				'length' => 80,
			]);
			// Size and mtime of the file when the etag was recorded, so a
			// modification made outside this API can be detected.
			$table->addColumn('file_size', Types::BIGINT, [
				'notnull' => true,
				'unsigned' => true,
			]);
			$table->addColumn('file_mtime', Types::BIGINT, [
				'notnull' => true,
				'unsigned' => true,
			]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['file_id'], 's3api_etag_fileid');
		}

		return $schema;
	}
}
