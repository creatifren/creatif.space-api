<?php

namespace App\Enums;

/**
 * Invite-only: the program is approved by hand in Filament, so an
 * application sits at `applied` until somebody reads it.
 */
enum AffiliateStatus: string
{
    case Applied = 'applied';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Suspended = 'suspended';
}
