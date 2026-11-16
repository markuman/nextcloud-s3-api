<?php

// Classes of other apps that s3_api uses but nextcloud/ocp does not carry.

namespace OCA\Files_Trashbin\Events;

use OCP\EventDispatcher\Event;
use OCP\Files\Node;

class MoveToTrashEvent extends Event {
	public function getNode(): Node {
	}

	public function disableTrashBin(): void {
	}
}
