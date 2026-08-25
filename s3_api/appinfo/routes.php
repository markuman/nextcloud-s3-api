<?php

declare(strict_types=1);

return [
	'routes' => [
		// S3 API - catch-all routes (public, no CSRF)
		['name' => 's3#listBucketsOrRoot', 'url' => '/s3', 'verb' => 'GET'],
		['name' => 's3#handleGet', 'url' => '/s3/{path}', 'verb' => 'GET', 'requirements' => ['path' => '.+']],
		['name' => 's3#handlePut', 'url' => '/s3/{path}', 'verb' => 'PUT', 'requirements' => ['path' => '.+']],
		// POST carries the multipart upload and bulk delete subresources.
		['name' => 's3#handlePost', 'url' => '/s3/{path}', 'verb' => 'POST', 'requirements' => ['path' => '.+']],
		['name' => 's3#handleDelete', 'url' => '/s3/{path}', 'verb' => 'DELETE', 'requirements' => ['path' => '.+']],
		['name' => 's3#handleHead', 'url' => '/s3/{path}', 'verb' => 'HEAD', 'requirements' => ['path' => '.+']],
		['name' => 's3#handleHeadRoot', 'url' => '/s3', 'verb' => 'HEAD'],

		// Settings API (authenticated, CSRF-protected)
		['name' => 'settings_api#listBuckets', 'url' => '/api/v1/buckets', 'verb' => 'GET'],
		['name' => 'settings_api#createBucket', 'url' => '/api/v1/buckets', 'verb' => 'POST'],
		['name' => 'settings_api#deleteBucket', 'url' => '/api/v1/buckets/{id}', 'verb' => 'DELETE'],
		['name' => 'settings_api#listKeys', 'url' => '/api/v1/buckets/{bucketId}/keys', 'verb' => 'GET'],
		['name' => 'settings_api#createKey', 'url' => '/api/v1/buckets/{bucketId}/keys', 'verb' => 'POST'],
		['name' => 'settings_api#deleteKey', 'url' => '/api/v1/buckets/{bucketId}/keys/{keyId}', 'verb' => 'DELETE'],
	],
];
