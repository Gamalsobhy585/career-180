<?php
// app/Http/Enums/ProviderOutcome.php
namespace App\Http\Enums;

enum ProviderOutcome: int
{
    case Success = 1;
    case Failure = 2;
    case Timeout = 3;
    case NotFound = 4; 
}