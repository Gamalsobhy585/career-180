<?php
// app/Http/Enums/PayoutMethodType.php
namespace App\Http\Enums;

enum PayoutMethodType: int
{
    case Bank = 1;
    case MockWallet = 2;
}