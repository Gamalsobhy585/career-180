<?php
// app/Http/Enums/EarningStatus.php
namespace App\Http\Enums;

enum EarningStatus: int
{
    case Pending = 1;
    case Paid = 2;
    case Reversed = 3;
}