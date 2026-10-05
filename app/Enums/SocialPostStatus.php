<?php

namespace App\Enums;

enum SocialPostStatus: string
{
    /** Saved, never sent, uses no quota. Scheduling it is what sends it. */
    case Draft = 'draft';
    case Scheduled = 'scheduled';
    case Published = 'published';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
}
