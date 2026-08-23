<?php

namespace App\Enums;

enum SocialPostTargetStatus: string
{
    case Pending = 'pending';
    case Published = 'published';
    case Failed = 'failed';
}
