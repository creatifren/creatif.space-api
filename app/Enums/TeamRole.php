<?php

namespace App\Enums;

/**
 * What a member may do inside the owner's account.
 *
 * In v1 `admin` and `editor` behave identically — both can build, neither
 * can touch money or identity. Shipping two behaviours behind three labels
 * is more honest than inventing a third just to fill the gap; `admin` is
 * here because agencies name someone that, and the label costs nothing.
 */
enum TeamRole: string
{
    case Admin = 'admin';
    case Editor = 'editor';
    case Viewer = 'viewer';
}
