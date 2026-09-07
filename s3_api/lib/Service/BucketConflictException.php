<?php

declare(strict_types=1);

namespace OCA\S3Api\Service;

/**
 * A bucket cannot be created because an existing one already claims the name
 * or the folder.
 *
 * Separate from InvalidArgumentException so the controller can answer 409
 * rather than 400: the request is well formed, it conflicts with current
 * state.
 */
class BucketConflictException extends \RuntimeException {
}
