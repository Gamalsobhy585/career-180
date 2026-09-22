<?php
// app/Http/Enums/PayoutStatus.php
namespace App\Http\Enums;

enum PayoutStatus: int
{
    case Pending = 1;
    case Processing = 2;
    case Succeeded = 3;
    case Failed = 4;
    case Unknown = 5;
}