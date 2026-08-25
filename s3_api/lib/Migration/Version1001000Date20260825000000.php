<?php

declare(strict_types=1);

namespace OCA\S3Api\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Tracks in-flight multipart uploads.
 *
 * The parts themselves are stored as files in a scratch folder inside the
 * bucket; this table only keeps what S3 needs to answer for an upload id
 * (which key it targets, and when it started so stale uploads can be reaped).
 */
class Version1001000Date20260825000000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('s3api_uploads')) {
			$table = $schema->createTable('s3api_uploads');
			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
				'unsigned' => true,
			]);
			$table->addColumn('upload_id', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('bucket_id', Types::BIGINT, [
				'notnull' => true,
				'unsigned' => true,
			]);
			$table->addColumn('object_key', Types::STRING, [
				'notnull' => true,
				'length' => 4000,
			]);
			$table->addColumn('user_id', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('created_at', Types::DATETIME, [
				'notnull' => true,
			]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['upload_id'], 's3api_upl_uploadid');
			$table->addIndex(['bucket_id'], 's3api_upl_bucket');
		}

		return $schema;
	}
}
