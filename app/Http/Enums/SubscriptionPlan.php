<?php
// app/Http/Enums/SubscriptionPlan.php
namespace App\Http\Enums;

enum SubscriptionPlan: int
{
    case Monthly = 1;
    case Quarterly = 2;
    case Annual = 3;

    public function label(): string
    {
        return match ($this) {
            self::Monthly => 'Monthly',
            self::Quarterly => 'Quarterly (3 months)',
            self::Annual => 'Annual',
        };
    }

    public function months(): int
    {
        return match ($this) {
            self::Monthly => 1,
            self::Quarterly => 3,
            self::Annual => 12,
        };
    }
}