<?php

declare(strict_types=1);

namespace OCA\S3Api\Listener;

use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IDBConnection;
use OCP\User\Events\UserDeletedEvent;

/**
 * Drop a deleted user's buckets, keys and open uploads.
 *
 * Their files are gone with the account, so the rows could not grant access
 * to anything any more — but they would sit in the tables for good, and a
 * user created later under the same id would inherit buckets and working
 * keys pointing into their new home folder.
 *
 * ETags and locks are keyed by file id and path rather than by user; the
 * cleanup job already removes those once their files are gone.
 *
 * @template-implements IEventListener<UserDeletedEvent>
 */
class UserDeletedListener implements IEventListener {

	public function __construct(
		private IDBConnection $db,
	) {
	}

	public function handle(Event $event): void {
		if (!($event instanceof UserDeletedEvent)) {
			return;
		}

		$userId = $event->getUser()->getUID();

		// Keys first: a key without its bucket is useless, a bucket without
		// keys harmless, so this order never leaves a usable half.
		foreach (['s3api_keys', 's3api_uploads', 's3api_buckets'] as $table) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete($table)
				->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
			$qb->executeStatement();
		}
	}
}
