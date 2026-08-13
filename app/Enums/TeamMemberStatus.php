<?php

namespace App\Enums;

enum TeamMemberStatus: string
{
    case Invited = 'invited';
    case Active = 'active';
    case Removed = 'removed';
}
