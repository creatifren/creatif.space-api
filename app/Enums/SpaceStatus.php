<?php

namespace App\Enums;

enum SpaceStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Archived = 'archived';
}
