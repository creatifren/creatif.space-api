<?php

namespace App\Enums;

enum PlanKey: string
{
    case Free = 'free';
    case Pro = 'pro';
    case Max = 'max';
    /** Planned, not sold: the row exists with is_active = false. */
    case Team = 'team';

    /**
     * The plans checkout sells.
     *
     * @return list<string>
     */
    public static function sold(): array
    {
        return [self::Pro->value, self::Max->value];
    }
}
