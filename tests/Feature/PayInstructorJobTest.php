<?php
// tests/Feature/PayInstructorJobTest.php

use App\Http\Enums\EarningStatus;
use App\Http\Enums\PayoutStatus;
use App\Http\Enums\ProviderOutcome;
use App\Jobs\PayInstructorJob;
use App\Jobs\ReconcilePendingPayoutsJob;
use App\Models\Instructor;
use App\Models\InstructorBalance;
use App\Models\InstructorEarning;
use App\Models\Payout;
use App\Models\PayoutItem;
use App\Payment\PaymentProviderInterface;
use App\Payment\PaymentResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function makeInstructorWithEarning(int $amount): array
{
    $instructor = Instructor::factory()->create();

    $earning = InstructorEarning::factory()->create([
        'instructor_id' => $instructor->id,
        'amount_cents' => $amount,
        'status' => EarningStatus::Pending,
    ]);

    InstructorBalance::create([
        'instructor_id' => $instructor->id,
        'total_earned_cents' => $amount,
        'total_paid_cents' => 0,
        'total_outstanding_cents' => $amount,
    ]);

    return [$instructor, $earning];
}

it('pays an instructor exactly once even if the command runs twice', function () {
    [$instructor, $earning] = makeInstructorWithEarning(1000);

    $this->mock(PaymentProviderInterface::class, function ($mock) {
        $mock->shouldReceive('pay')->once()->andReturn(
            new PaymentResult(ProviderOutcome::Success, 'ref_123')
        );
    });

    (new PayInstructorJob($instructor->id))->handle(app(PaymentProviderInterface::class));
    (new PayInstructorJob($instructor->id))->handle(app(PaymentProviderInterface::class));

    expect(Payout::where('instructor_id', $instructor->id)->count())->toBe(1);
    expect(PayoutItem::count())->toBe(1);
    expect($earning->fresh()->status)->toBe(EarningStatus::Paid);

    $balance = InstructorBalance::where('instructor_id', $instructor->id)->first();
    expect((int) $balance->total_paid_cents)->toBe(1000);
    expect((int) $balance->total_outstanding_cents)->toBe(0);
});

it('does not double-pay when the same job is retried', function () {
    [$instructor] = makeInstructorWithEarning(500);

    $this->mock(PaymentProviderInterface::class, fn ($mock) =>
        $mock->shouldReceive('pay')->once()->andReturn(new PaymentResult(ProviderOutcome::Success, 'ref_abc'))
    );

    $job = new PayInstructorJob($instructor->id);
    $job->handle(app(PaymentProviderInterface::class));
    $job->handle(app(PaymentProviderInterface::class)); // retry of the SAME job instance

    expect(Payout::where('instructor_id', $instructor->id)->count())->toBe(1);
});

it('reserves the earnings in payout_items before calling the provider', function () {
    [$instructor, $earning] = makeInstructorWithEarning(900);

    $this->mock(PaymentProviderInterface::class, function ($mock) use ($instructor, $earning) {
        $mock->shouldReceive('pay')->once()->andReturnUsing(function () use ($instructor, $earning) {
            // At the moment the provider is called, the reservation must already exist.
            expect(PayoutItem::count())->toBe(1);
            expect(PayoutItem::first()->instructor_earning_id)->toBe($earning->id);
            expect(Payout::where('instructor_id', $instructor->id)->first()->status)
                ->toBe(PayoutStatus::Processing);

            return new PaymentResult(ProviderOutcome::Success, 'ref_reserved');
        });
    });

    (new PayInstructorJob($instructor->id))->handle(app(PaymentProviderInterface::class));

    expect($earning->fresh()->status)->toBe(EarningStatus::Paid);
});

it('applies a payout success only once even if it is finalized twice', function () {
    [$instructor, $earning] = makeInstructorWithEarning(700);

    $this->mock(PaymentProviderInterface::class, fn ($mock) =>
        $mock->shouldReceive('pay')->once()->andReturn(new PaymentResult(ProviderOutcome::Timeout))
    );

    (new PayInstructorJob($instructor->id))->handle(app(PaymentProviderInterface::class));

    $payout = Payout::where('instructor_id', $instructor->id)->first();

    // Simulates two workers (pay job + reconciliation) both learning about the success.
    PayInstructorJob::finalizePayout($payout->id, 'ref_a');
    PayInstructorJob::finalizePayout($payout->id, 'ref_b');

    $balance = InstructorBalance::where('instructor_id', $instructor->id)->first();

    expect((int) $balance->total_paid_cents)->toBe(700);
    expect((int) $balance->total_outstanding_cents)->toBe(0);
    expect($payout->fresh()->provider_reference)->toBe('ref_a');
    expect($earning->fresh()->status)->toBe(EarningStatus::Paid);
});

it('marks payout as unknown on timeout without paying twice after reconciliation confirms success', function () {
    [$instructor, $earning] = makeInstructorWithEarning(700);

    $this->mock(PaymentProviderInterface::class, fn ($mock) =>
        $mock->shouldReceive('pay')->once()->andReturn(new PaymentResult(ProviderOutcome::Timeout))
    );

    (new PayInstructorJob($instructor->id))->handle(app(PaymentProviderInterface::class));

    $payout = Payout::where('instructor_id', $instructor->id)->first();
    expect($payout->status)->toBe(PayoutStatus::Unknown);

    // Earnings stay reserved (not paid, not re-payable) while the outcome is unknown.
    expect($earning->fresh()->status)->toBe(EarningStatus::Pending);
    expect(PayoutItem::where('payout_id', $payout->id)->count())->toBe(1);

    // Another run must not create a second payout for the same earnings.
    (new PayInstructorJob($instructor->id))->handle(app(PaymentProviderInterface::class));
    expect(Payout::where('instructor_id', $instructor->id)->count())->toBe(1);

    // Reconciliation discovers it actually succeeded.
    $this->mock(PaymentProviderInterface::class, fn ($mock) =>
        $mock->shouldReceive('checkStatus')->once()->andReturn(new PaymentResult(ProviderOutcome::Success, 'ref_late'))
    );

    (new ReconcilePendingPayoutsJob())->handle(app(PaymentProviderInterface::class));

    expect($payout->fresh()->status)->toBe(PayoutStatus::Succeeded);
    expect($earning->fresh()->status)->toBe(EarningStatus::Paid);
    expect(Payout::where('instructor_id', $instructor->id)->count())->toBe(1);

    $balance = InstructorBalance::where('instructor_id', $instructor->id)->first();
    expect((int) $balance->total_paid_cents)->toBe(700);
});

it('releases the earnings after a provider failure so a later run can pay them', function () {
    [$instructor, $earning] = makeInstructorWithEarning(300);

    $this->mock(PaymentProviderInterface::class, fn ($mock) =>
        $mock->shouldReceive('pay')->once()->andReturn(
            new PaymentResult(ProviderOutcome::Failure, null, 'declined')
        )
    );

    (new PayInstructorJob($instructor->id))->handle(app(PaymentProviderInterface::class));

    expect(Payout::where('instructor_id', $instructor->id)->first()->status)->toBe(PayoutStatus::Failed);
    expect($earning->fresh()->status)->toBe(EarningStatus::Pending);
    expect(PayoutItem::count())->toBe(0);

    $this->mock(PaymentProviderInterface::class, fn ($mock) =>
        $mock->shouldReceive('pay')->once()->andReturn(new PaymentResult(ProviderOutcome::Success, 'ref_retry'))
    );

    (new PayInstructorJob($instructor->id))->handle(app(PaymentProviderInterface::class));

    expect(Payout::where('instructor_id', $instructor->id)->count())->toBe(2);
    expect($earning->fresh()->status)->toBe(EarningStatus::Paid);

    $balance = InstructorBalance::where('instructor_id', $instructor->id)->first();
    expect((int) $balance->total_paid_cents)->toBe(300);
});

it('releases reserved earnings when reconciliation confirms a provider failure', function () {
    [$instructor, $earning] = makeInstructorWithEarning(450);

    $this->mock(PaymentProviderInterface::class, fn ($mock) =>
        $mock->shouldReceive('pay')->once()->andReturn(new PaymentResult(ProviderOutcome::Timeout))
    );

    (new PayInstructorJob($instructor->id))->handle(app(PaymentProviderInterface::class));

    $this->mock(PaymentProviderInterface::class, fn ($mock) =>
        $mock->shouldReceive('checkStatus')->once()->andReturn(
            new PaymentResult(ProviderOutcome::Failure, null, 'declined late')
        )
    );

    (new ReconcilePendingPayoutsJob())->handle(app(PaymentProviderInterface::class));

    $payout = Payout::where('instructor_id', $instructor->id)->first();

    expect($payout->status)->toBe(PayoutStatus::Failed);
    expect(PayoutItem::count())->toBe(0);
    expect($earning->fresh()->status)->toBe(EarningStatus::Pending);
});