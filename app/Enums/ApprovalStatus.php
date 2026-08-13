<?php

namespace App\Enums;

enum ApprovalStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Revision = 'revision';
    case Cancelled = 'cancelled';
}
