<?php

namespace App\Enums;

enum FileRequestStatus: string
{
    case Open = 'open';
    case Closed = 'closed';
}
