<?php

declare(strict_types=1);

namespace OCA\S3Api\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version1000000Date20260302000000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('s3api_buckets')) {
			$table = $schema->createTable('s3api_buckets');
			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
				'unsigned' => true,
			]);
			$table->addColumn('user_id', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('folder_path', Types::STRING, [
				'notnull' => true,
				'length' => 4000,
			]);
			$table->addColumn('bucket_name', Types::STRING, [
				'notnull' => true,
				'length' => 255,
			]);
			$table->addColumn('created_at', Types::DATETIME, [
				'notnull' => true,
			]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['user_id', 'bucket_name'], 's3api_bkt_user_name');
		}

		if (!$schema->hasTable('s3api_keys')) {
			$table = $schema->createTable('s3api_keys');
			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
				'unsigned' => true,
			]);
			$table->addColumn('user_id', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('bucket_id', Types::BIGINT, [
				'notnull' => true,
				'unsigned' => true,
			]);
			$table->addColumn('access_key', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('secret_key_enc', Types::STRING, [
				'notnull' => true,
				'length' => 512,
			]);
			$table->addColumn('permission', Types::STRING, [
				'notnull' => true,
				'length' => 16,
			]);
			$table->addColumn('label', Types::STRING, [
				'notnull' => false,
				'length' => 255,
			]);
			$table->addColumn('created_at', Types::DATETIME, [
				'notnull' => true,
			]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['access_key'], 's3api_key_access');
			$table->addIndex(['bucket_id'], 's3api_key_bucket');
		}

		return $schema;
	}
}
