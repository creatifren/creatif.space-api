<?php

namespace App\Enums;

enum SpaceVisibility: string
{
    case Private = 'private';
    case Public = 'public';
    /** Reachable by its link, not listed on the profile (v7 default). */
    case Link = 'link';
}
