<?php

namespace App\Support\Media;

use Carbon\CarbonImmutable;

/**
 * One file's fate under the retention rules: when it may be deleted (null =
 * never automatically) and which rule decided.
 */
final readonly class RetentionVerdict
{
    public const KEPT = 'kept';

    /** Used by a Space, a transfer, an avatar or brand asset. */
    public const PROTECTED = 'protected';

    /** PDFs, SVGs: retention is for post media only. */
    public const NOT_POST_MEDIA = 'not_post_media';

    /** Pending, failed or in the Trash: other jobs own those. */
    public const NOT_READY = 'not_ready';

    /** On a draft or scheduled post. */
    public const QUEUED = 'queued';

    /** Paid: never on a published post, so no clock is running. */
    public const NEVER_PUBLISHED = 'never_published';

    public const PAID_AFTER_PUBLISH = 'paid_after_publish';

    public const FREE_PUBLISHED = 'free_published';

    public const FREE_FAILED_WINDOW = 'free_failed_window';

    public const FREE_UNATTACHED = 'free_unattached';

    public function __construct(
        public string $rule,
        public ?CarbonImmutable $dueAt,
        public bool $kept,
    ) {}

    public function isDue(?CarbonImmutable $now = null): bool
    {
        return $this->dueAt !== null && $this->dueAt->lessThanOrEqualTo($now ?? CarbonImmutable::now());
    }
}
