<?php
// tests/Unit/MockPaymentProviderTest.php

use App\Http\Enums\ProviderOutcome;
use App\Payment\MockPaymentProvider;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    Cache::flush();
});

it('returns a result for every payment attempt', function () {
    $provider = new MockPaymentProvider();
    $result = $provider->pay('key-' . uniqid(), 1000);

    expect($result->outcome)->toBeInstanceOf(ProviderOutcome::class);
});

it('never returns duplicate different outcomes for the same idempotency key', function () {
    $provider = new MockPaymentProvider();
    $key = 'fixed-key-123';

    $first = $provider->pay($key, 1000);
    $second = $provider->pay($key, 1000);

    expect($second->outcome)->toBe(
        $first->outcome === ProviderOutcome::Timeout
            ? ProviderOutcome::Success
            : $first->outcome
    );
});

it('always resolves to a single outcome and reference for the same key across many calls', function () {
    $provider = new MockPaymentProvider();
    $key = 'repeat-key-456';

    $results = [];
    for ($i = 0; $i < 10; $i++) {
        $results[] = $provider->pay($key, 1000);
    }

    // Ignore the very first call if it was the (one-time) timeout response.
    $settled = array_values(array_filter(
        $results,
        fn ($r) => $r->outcome !== ProviderOutcome::Timeout
    ));

    expect($settled)->not->toBeEmpty();
    expect(collect($settled)->pluck('outcome')->unique()->count())->toBe(1);
    expect(collect($settled)->pluck('providerReference')->unique()->count())->toBe(1);

    // The ledger agrees with what was returned.
    expect($provider->checkStatus($key)->outcome)->toBe($settled[0]->outcome);
});

it('resolves a timeout to a real outcome via checkStatus', function () {
    $provider = new MockPaymentProvider();
    $key = null;
    $result = null;

    for ($i = 0; $i < 300; $i++) {
        $attempt = $provider->pay('probe-' . $i, 1000);

        if ($attempt->outcome === ProviderOutcome::Timeout) {
            $result = $attempt;
            $key = 'probe-' . $i;
            break;
        }
    }

    expect($result)->not->toBeNull();

    $status = $provider->checkStatus($key);
    expect($status->outcome)->toBe(ProviderOutcome::Success);
    expect($status->providerReference)->not->toBeNull();
});

it('returns NotFound for an unknown idempotency key', function () {
    $provider = new MockPaymentProvider();

    $status = $provider->checkStatus('never-used-key');

    expect($status->outcome)->toBe(ProviderOutcome::NotFound);
});