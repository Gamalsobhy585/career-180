<?php
namespace App\Payment;

use App\Http\Enums\ProviderOutcome;

final class PaymentResult
{
    public function __construct(
        public readonly ProviderOutcome $outcome,
        public readonly ?string $providerReference = null,
        public readonly ?string $message = null,
    ) {
    }
}