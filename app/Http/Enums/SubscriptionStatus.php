<?php
// app/Http/Enums/SubscriptionStatus.php
namespace App\Http\Enums;

enum SubscriptionStatus: int
{
    case Active = 1;
    case Refunded = 2;
    case Cancelled = 3;
}