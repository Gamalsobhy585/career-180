<?php
// tests/Unit/ProcessRefundActionTest.php

use App\Actions\AllocateSubscriptionRevenueAction;
use App\Actions\ProcessRefundAction;
use App\Http\Enums\EarningStatus;
use App\Http\Enums\SubscriptionPlan;
use App\Http\Enums\SubscriptionStatus;
use App\Models\Course;
use App\Models\Instructor;
use App\Models\InstructorBalance;
use App\Models\InstructorEarning;
use App\Models\PlatformSetting;
use App\Models\Subscription;
use Illuminate\Support\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);


beforeEach(function () {
    PlatformSetting::create(['key' => 'platform_cut_percentage', 'value' => '20']);
});

it('reverses only future not-yet-started periods on refund', function () {
    $instructor = Instructor::factory()->create();
    $course = Course::factory()->create(['instructor_id' => $instructor->id]);

    $start = Carbon::parse('2026-01-01');
    $subscription = Subscription::factory()->create([
        'plan' => SubscriptionPlan::Annual,
        'amount_paid_cents' => 12000, // 1000/month gross
        'starts_at' => $start,
        'ends_at' => $start->copy()->addYear(),
    ]);
    $subscription->courses()->attach($course->id);

    app(AllocateSubscriptionRevenueAction::class)->execute($subscription->fresh());

    expect(InstructorEarning::where('subscription_id', $subscription->id)->count())->toBe(12);

    // refund as of March 15 — Jan and Feb periods already started, keep them;
    // March 1 period (starts on the 1st, before the 15th) also already started;
    // April onward (periods starting > March 15) get reversed
    $refundDate = Carbon::parse('2026-03-15');

    $refund = app(ProcessRefundAction::class)->execute($subscription, 'requested by student', $refundDate);

    $remaining = InstructorEarning::where('subscription_id', $subscription->id)
        ->where('status', EarningStatus::Pending)->count();
    $reversed = InstructorEarning::where('subscription_id', $subscription->id)
        ->where('status', EarningStatus::Reversed)->count();

    expect($remaining)->toBe(3); // Jan, Feb, Mar periods kept
    expect($reversed)->toBe(9);  // Apr through Dec reversed
    expect($subscription->fresh()->status)->toBe(SubscriptionStatus::Refunded);
    expect($refund->amount_cents)->toBeGreaterThan(0);
});

it('decrements instructor balance by the reversed amount only', function () {
    $instructor = Instructor::factory()->create();
    $course = Course::factory()->create(['instructor_id' => $instructor->id]);

    $subscription = Subscription::factory()->create([
        'plan' => SubscriptionPlan::Quarterly,
        'amount_paid_cents' => 3000,
        'starts_at' => Carbon::parse('2026-01-01'),
        'ends_at' => Carbon::parse('2026-04-01'),
    ]);
    $subscription->courses()->attach($course->id);

    app(AllocateSubscriptionRevenueAction::class)->execute($subscription->fresh());

    $balanceBefore = InstructorBalance::where('instructor_id', $instructor->id)->first()->total_outstanding_cents;

    app(ProcessRefundAction::class)->execute($subscription, null, Carbon::parse('2026-01-10'));

    $balanceAfter = InstructorBalance::where('instructor_id', $instructor->id)->first();

    expect($balanceAfter->total_outstanding_cents)->toBeLessThan($balanceBefore);
});

it('never reverses earnings that have already been paid out', function () {
    $instructor = Instructor::factory()->create();
    $course = Course::factory()->create(['instructor_id' => $instructor->id]);

    $subscription = Subscription::factory()->create([
        'plan' => SubscriptionPlan::Quarterly,
        'amount_paid_cents' => 3000,
        'starts_at' => Carbon::parse('2026-01-01'),
        'ends_at' => Carbon::parse('2026-04-01'),
    ]);
    $subscription->courses()->attach($course->id);

    app(AllocateSubscriptionRevenueAction::class)->execute($subscription->fresh());

    // simulate the first period already having been paid out
    $paidEarning = InstructorEarning::where('subscription_id', $subscription->id)
        ->orderBy('period_start')->first();
    $paidEarning->update(['status' => EarningStatus::Paid]);

    app(ProcessRefundAction::class)->execute($subscription, null, Carbon::parse('2026-01-01'));

    expect($paidEarning->fresh()->status)->toBe(EarningStatus::Paid); // untouched
});

it('is idempotent — refunding an already-refunded subscription does not reverse twice', function () {
    $instructor = Instructor::factory()->create();
    $course = Course::factory()->create(['instructor_id' => $instructor->id]);

    $subscription = Subscription::factory()->create([
        'plan' => SubscriptionPlan::Quarterly,
        'amount_paid_cents' => 3000,
        'starts_at' => Carbon::parse('2026-01-01'),
        'ends_at' => Carbon::parse('2026-04-01'),
    ]);
    $subscription->courses()->attach($course->id);

    app(AllocateSubscriptionRevenueAction::class)->execute($subscription->fresh());

    $action = app(ProcessRefundAction::class);
    $first = $action->execute($subscription, null, Carbon::parse('2026-01-10'));
    $second = $action->execute($subscription->fresh(), null, Carbon::parse('2026-01-10'));

    expect(\App\Models\Refund::where('subscription_id', $subscription->id)->count())->toBe(1);
    expect($second->id)->toBe($first->id);
});