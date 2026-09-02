<?php

namespace App\Enums;

enum ApprovalCancelReason: string
{
    case OwnerReset = 'owner_reset';

    /**
     * A new version of the file was uploaded. The client approved the bytes
     * they were shown, and those are not the bytes on screen any more.
     */
    case FileReplaced = 'file_replaced';
}
