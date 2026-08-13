<?php

namespace App\Enums;

enum DriveAccountStatus: string
{
    case Connected = 'connected';
    case ReconnectNeeded = 'reconnect_needed';
    case Revoked = 'revoked';
}
