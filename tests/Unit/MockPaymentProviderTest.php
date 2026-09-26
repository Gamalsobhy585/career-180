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

it('resolves a timeout to a real outcome via checkStatus', function () {
    $provider = new MockPaymentProvider();
    $key = 'timeout-check-key';

    $result = null;
    for ($i = 0; $i < 50; $i++) {
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
});

it('returns NotFound for an unknown idempotency key', function () {
    $provider = new MockPaymentProvider();

    $status = $provider->checkStatus('never-used-key');

    expect($status->outcome)->toBe(ProviderOutcome::NotFound);
});