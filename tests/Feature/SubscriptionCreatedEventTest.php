<?php
// tests/Feature/SubscriptionCreatedEventTest.php

use App\Events\SubscriptionCreated;
use App\Listeners\AllocateRevenueOnSubscriptionCreated;
use App\Models\Subscription;
use Illuminate\Support\Facades\Event;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);
it('dispatches SubscriptionCreated and triggers the allocation listener', function () {
    Event::fake();

    $subscription = Subscription::factory()->create();
    event(new SubscriptionCreated($subscription));

    Event::assertDispatched(SubscriptionCreated::class);
    Event::assertListening(SubscriptionCreated::class, AllocateRevenueOnSubscriptionCreated::class);
});