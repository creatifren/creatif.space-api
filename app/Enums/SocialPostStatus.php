<?php

namespace App\Enums;

enum SocialPostStatus: string
{
    case Scheduled = 'scheduled';
    case Published = 'published';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
}
