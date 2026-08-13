<?php

namespace App\Enums;

enum OfferType: string
{
    case Service = 'service';
    case Product = 'product';
    case Booking = 'booking';
    case Tip = 'tip';
}
