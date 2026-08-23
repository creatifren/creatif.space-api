<?php

namespace App\Enums;

enum SocialAccountStatus: string
{
    case Connected = 'connected';
    // The provider only reports connected|disconnected; Expired exists for
    // the frontend contract and will be derived at sync time later.
    case Expired = 'expired';
    case Disconnected = 'disconnected';
}
