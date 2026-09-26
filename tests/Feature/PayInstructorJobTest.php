<?php
// tests/Feature/PayInstructorJobTest.php

use App\Http\Enums\EarningStatus;
use App\Http\Enums\PayoutStatus;
use App\Jobs\PayInstructorJob;
use App\Models\Instructor;
use App\Models\InstructorBalance;
use App\Models\InstructorEarning;
use App\Models\Payout;
use App\Payment\PaymentProviderInterface;
use App\Http\Enums\ProviderOutcome;
use App\Payment\PaymentResult;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;


uses(TestCase::class, RefreshDatabase::class);


it('pays an instructor exactly once even if the command runs twice', function () {
    $instructor = Instructor::factory()->create();
    $earning = InstructorEarning::factory()->create([
        'instructor_id' => $instructor->id,
        'amount_cents' => 1000,
        'status' => EarningStatus::Pending,
    ]);
    InstructorBalance::create([
        'instructor_id' => $instructor->id,
        'total_earned_cents' => 1000,
        'total_paid_cents' => 0,
        'total_outstanding_cents' => 1000,
    ]);

    $this->mock(PaymentProviderInterface::class, function ($mock) {
        $mock->shouldReceive('pay')->once()->andReturn(
            new PaymentResult(ProviderOutcome::Success, 'ref_123')
        );
    });

    // run the job's logic twice in a row (simulating command run twice)
    (new PayInstructorJob($instructor->id))->handle(app(PaymentProviderInterface::class));
    (new PayInstructorJob($instructor->id))->handle(app(PaymentProviderInterface::class));

    expect(Payout::where('instructor_id', $instructor->id)->count())->toBe(1);
    expect($earning->fresh()->status)->toBe(EarningStatus::Paid);
});

it('does not double-pay when the same job is retried', function () {
    $instructor = Instructor::factory()->create();
    InstructorEarning::factory()->create([
        'instructor_id' => $instructor->id,
        'amount_cents' => 500,
        'status' => EarningStatus::Pending,
    ]);
    InstructorBalance::create([
        'instructor_id' => $instructor->id,
        'total_earned_cents' => 500, 'total_paid_cents' => 0, 'total_outstanding_cents' => 500,
    ]);

    $this->mock(PaymentProviderInterface::class, fn ($mock) =>
        $mock->shouldReceive('pay')->once()->andReturn(new PaymentResult(ProviderOutcome::Success, 'ref_abc'))
    );

    $job = new PayInstructorJob($instructor->id);
    $job->handle(app(PaymentProviderInterface::class));
    $job->handle(app(PaymentProviderInterface::class)); // simulate retry of the SAME job instance

    expect(Payout::where('instructor_id', $instructor->id)->count())->toBe(1);
});

it('marks payout as unknown on timeout without paying twice after reconciliation confirms success', function () {
    $instructor = Instructor::factory()->create();
    InstructorEarning::factory()->create([
        'instructor_id' => $instructor->id,
        'amount_cents' => 700,
        'status' => EarningStatus::Pending,
    ]);
    InstructorBalance::create([
        'instructor_id' => $instructor->id,
        'total_earned_cents' => 700, 'total_paid_cents' => 0, 'total_outstanding_cents' => 700,
    ]);

    $this->mock(PaymentProviderInterface::class, fn ($mock) =>
        $mock->shouldReceive('pay')->once()->andReturn(new PaymentResult(ProviderOutcome::Timeout))
    );

    (new PayInstructorJob($instructor->id))->handle(app(PaymentProviderInterface::class));

    $payout = Payout::where('instructor_id', $instructor->id)->first();
    expect($payout->status)->toBe(PayoutStatus::Unknown);

    // now reconciliation runs and discovers it actually succeeded
    $this->mock(PaymentProviderInterface::class, fn ($mock) =>
        $mock->shouldReceive('checkStatus')->once()->andReturn(new PaymentResult(ProviderOutcome::Success, 'ref_late'))
    );

    (new \App\Jobs\ReconcilePendingPayoutsJob())->handle(app(PaymentProviderInterface::class));

    expect($payout->fresh()->status)->toBe(PayoutStatus::Succeeded);
    expect(Payout::where('instructor_id', $instructor->id)->count())->toBe(1); // still just one payout row
});