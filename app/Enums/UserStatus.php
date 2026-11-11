<?php

namespace App\Enums;

enum UserStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
    /** Asked to delete the account; purged 30 days after deletion_requested_at. */
    case PendingDeletion = 'pending_deletion';
    case Deleted = 'deleted';
}
