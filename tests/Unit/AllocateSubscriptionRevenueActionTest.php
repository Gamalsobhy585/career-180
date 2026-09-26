<?php

use App\Actions\AllocateSubscriptionRevenueAction;
use App\Http\Enums\SubscriptionPlan;
use App\Models\Course;
use App\Models\Instructor;
use App\Models\InstructorBalance;
use App\Models\InstructorEarning;
use App\Models\PlatformSetting;
use App\Models\Subscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    PlatformSetting::create(['key' => 'platform_cut_percentage', 'value' => '20']);
});

it('splits a monthly subscription evenly across instructors with no remainder', function () {
    $instructors = Instructor::factory()->count(2)->create();
    $courses = $instructors->map(fn ($i) => Course::factory()->create(['instructor_id' => $i->id]));

    $subscription = Subscription::factory()->create([
        'plan' => SubscriptionPlan::Monthly,
        'amount_paid_cents' => 1000, // $10.00
    ]);
    $subscription->courses()->attach($courses->pluck('id'));

    (app(AllocateSubscriptionRevenueAction::class))->execute($subscription->fresh());

    $earnings = InstructorEarning::where('subscription_id', $subscription->id)->get();

    expect($earnings)->toHaveCount(2);
    // net after 20% cut = 800, split across 2 = 400 each, no remainder
    expect($earnings->sum('amount_cents'))->toBe(800);
    expect($earnings->pluck('amount_cents')->unique()->count())->toBe(1); // both equal
});

it('distributes the rounding remainder without losing or inventing cents', function () {
    $instructors = Instructor::factory()->count(3)->create();
    $courses = $instructors->map(fn ($i) => Course::factory()->create(['instructor_id' => $i->id]));

    $subscription = Subscription::factory()->create([
        'plan' => SubscriptionPlan::Monthly,
        'amount_paid_cents' => 1000, // net after 20% cut = 800, ÷ 3 = 266.67
    ]);
    $subscription->courses()->attach($courses->pluck('id'));

    (app(AllocateSubscriptionRevenueAction::class))->execute($subscription->fresh());

    $earnings = InstructorEarning::where('subscription_id', $subscription->id)->get();

    expect($earnings->sum('amount_cents'))->toBe(800); // exact, nothing lost
    expect($earnings->pluck('amount_cents')->sort()->values()->all())->toBe([266, 267, 267]);
});

it('creates one earning row per month for an annual subscription', function () {
    $instructor = Instructor::factory()->create();
    $course = Course::factory()->create(['instructor_id' => $instructor->id]);

    $subscription = Subscription::factory()->create([
        'plan' => SubscriptionPlan::Annual,
        'amount_paid_cents' => 9600,
    ]);
    $subscription->courses()->attach($course->id);

    (app(AllocateSubscriptionRevenueAction::class))->execute($subscription->fresh());

    expect(InstructorEarning::where('subscription_id', $subscription->id)->count())->toBe(12);
});

it('is idempotent — running allocation twice does not duplicate earnings or balances', function () {
    $instructor = Instructor::factory()->create();
    $course = Course::factory()->create(['instructor_id' => $instructor->id]);

    $subscription = Subscription::factory()->create([
        'plan' => SubscriptionPlan::Monthly,
        'amount_paid_cents' => 1000,
    ]);
    $subscription->courses()->attach($course->id);

    $action = app(AllocateSubscriptionRevenueAction::class);
    $action->execute($subscription->fresh());
    $action->execute($subscription->fresh()); // run again

    expect(InstructorEarning::where('subscription_id', $subscription->id)->count())->toBe(1);

    $balance = InstructorBalance::where('instructor_id', $instructor->id)->first();
    expect($balance->total_earned_cents)->toBe(800); // not doubled to 1600
});

it('allocates nothing when the subscription has no courses attached', function () {
    $subscription = Subscription::factory()->create();

    (app(AllocateSubscriptionRevenueAction::class))->execute($subscription);

    expect(InstructorEarning::where('subscription_id', $subscription->id)->count())->toBe(0);
});