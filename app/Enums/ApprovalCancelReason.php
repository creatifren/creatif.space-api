<?php

namespace App\Enums;

enum ApprovalCancelReason: string
{
    case FileVersionChanged = 'file_version_changed';
    case OwnerReset = 'owner_reset';
}
