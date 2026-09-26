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

    public function pay(string $idempotencyKey, int $amountCents): PaymentResult
    {
        $cached = Cache::get($this->cacheKey($idempotencyKey));
        if ($cached) {
            return unserialize($cached);
        }

        $roll = random_int(1, 100);

        $result = match (true) {
            $roll <= 70 => new PaymentResult(ProviderOutcome::Success, 'mock_ref_' . Str::random(12)),
            $roll <= 85 => new PaymentResult(ProviderOutcome::Failure, null, 'Insufficient provider balance (simulated).'),
            default => (function () use ($idempotencyKey) {
                Cache::put(
                    $this->cacheKey($idempotencyKey),
                    serialize(new PaymentResult(ProviderOutcome::Success, 'mock_ref_' . Str::random(12))),
                    now()->addDay()
                );
                return new PaymentResult(ProviderOutcome::Timeout);
            })(),
        };

        if ($result->outcome !== ProviderOutcome::Timeout) {
            Cache::put($this->cacheKey($idempotencyKey), serialize($result), now()->addDay());
        }

        return $result;
    }

    public function checkStatus(string $idempotencyKey): PaymentResult
    {
        $cached = Cache::get($this->cacheKey($idempotencyKey));

        return $cached ? unserialize($cached) : new PaymentResult(ProviderOutcome::NotFound);
    }
}