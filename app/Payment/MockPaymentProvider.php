<?php

namespace App\Payment;

use App\Http\Enums\ProviderOutcome;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class MockPaymentProvider implements PaymentProviderInterface
{
    protected function cacheKey(string $idempotencyKey): string
    {
        return "mock_provider_ledger:{$idempotencyKey}";
    }

    protected function lockKey(string $idempotencyKey): string
    {
        return "mock_provider_lock:{$idempotencyKey}";
    }

    /**
     * Concurrency-safe: the check-ledger -> roll -> write-ledger sequence runs under an
     * atomic per-key lock, so two simultaneous calls with the same idempotency key can
     * never produce two different outcomes/references.
     */
    public function pay(string $idempotencyKey, int $amountCents): PaymentResult
    {
        return Cache::lock($this->lockKey($idempotencyKey), 10)->block(5, function () use ($idempotencyKey) {
            $cached = Cache::get($this->cacheKey($idempotencyKey));

            if ($cached) {
                return unserialize($cached);
            }

            $roll = random_int(1, 100);

            if ($roll <= 70) {
                $result = new PaymentResult(ProviderOutcome::Success, 'mock_ref_' . Str::random(12));
                $ledgerEntry = $result;
            } elseif ($roll <= 85) {
                $result = new PaymentResult(ProviderOutcome::Failure, null, 'Insufficient provider balance (simulated).');
                $ledgerEntry = $result;
            } else {
                // Timeout: the caller gets no answer, but the provider really did process it.
                $result = new PaymentResult(ProviderOutcome::Timeout);
                $ledgerEntry = new PaymentResult(ProviderOutcome::Success, 'mock_ref_' . Str::random(12));
            }

            Cache::put($this->cacheKey($idempotencyKey), serialize($ledgerEntry), now()->addDay());

            return $result;
        });
    }

    public function checkStatus(string $idempotencyKey): PaymentResult
    {
        $cached = Cache::get($this->cacheKey($idempotencyKey));

        return $cached ? unserialize($cached) : new PaymentResult(ProviderOutcome::NotFound);
    }
}