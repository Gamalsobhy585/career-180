<?php
namespace App\Payment;

use App\Http\Enums\ProviderOutcome;
use Illuminate\Support\Str;

class MockPaymentProvider implements PaymentProviderInterface
{
    /**
     * In-memory store simulating the provider's own record of what
     * actually happened — this is what checkStatus() consults, so a
     * "timeout" response here can later resolve to "it actually succeeded".
     */
    protected static array $providerLedger = [];

    public function pay(string $idempotencyKey, int $amountCents): PaymentResult
    {
       
        if (isset(static::$providerLedger[$idempotencyKey])) {
            return static::$providerLedger[$idempotencyKey];
        }

        $roll = random_int(1, 100);

        $result = match (true) {
            $roll <= 70 => new PaymentResult(
                ProviderOutcome::Success,
                'mock_ref_' . Str::random(12),
            ),
            $roll <= 85 => new PaymentResult(
                ProviderOutcome::Failure,
                null,
                'Insufficient provider balance (simulated).',
            ),
            default => (function () use ($idempotencyKey) {
                // Timeout: the provider actually completed the transfer
                // behind the scenes, but the caller never got the response.
                // We record the "true" outcome for checkStatus() to reveal
                // later, but return Timeout to the caller right now.
                static::$providerLedger[$idempotencyKey] = new PaymentResult(
                    ProviderOutcome::Success,
                    'mock_ref_' . Str::random(12),
                );

                return new PaymentResult(ProviderOutcome::Timeout);
            })(),
        };

        // Only cache success/failure immediately; timeout's true result
        // was already cached above inside the closure.
        if ($result->outcome !== ProviderOutcome::Timeout) {
            static::$providerLedger[$idempotencyKey] = $result;
        }

        return $result;
    }

    public function checkStatus(string $idempotencyKey): PaymentResult
    {
        return static::$providerLedger[$idempotencyKey]
            ?? new PaymentResult(ProviderOutcome::NotFound);
    }
}