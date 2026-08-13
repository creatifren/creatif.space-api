<?php

namespace App\Enums;

enum PlanKey: string
{
    case Free = 'free';
    case Premium = 'premium';
    case Team = 'team';
}
