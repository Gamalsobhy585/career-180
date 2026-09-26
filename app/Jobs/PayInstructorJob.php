<?php

namespace App\Jobs;

use App\Http\Enums\EarningStatus;
use App\Http\Enums\PayoutStatus;
use App\Http\Enums\ProviderOutcome;
use App\Models\Instructor;
use App\Models\InstructorBalance;
use App\Models\InstructorEarning;
use App\Models\Payout;
use App\Models\PayoutItem;
use App\Models\PaymentProviderLog;
use App\Payment\PaymentProviderInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class PayInstructorJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public string $instructorId)
    {
    }

    public function handle(PaymentProviderInterface $provider): void
    {
        $instructor = Instructor::findOrFail($this->instructorId);

        $earnings = DB::transaction(function () use ($instructor) {
            return InstructorEarning::where('instructor_id', $instructor->id)
                ->where('status', EarningStatus::Pending)
                ->whereDoesntHave('payoutItem')
                ->lockForUpdate()
                ->get();
        });

        if ($earnings->isEmpty()) {
            return;
        }

        $totalAmount = $earnings->sum('amount_cents');

        $idempotencyKey = $this->buildIdempotencyKey(
            $instructor->id,
            $earnings
        );

        $payout = Payout::firstOrCreate(
            [
                'idempotency_key' => $idempotencyKey,
            ],
            [
                'instructor_id' => $instructor->id,
                'payout_method_id' => optional(
                    $instructor->defaultPayoutMethod
                )->id,
                'amount_cents' => $totalAmount,
                'status' => PayoutStatus::Pending,
            ]
        );

        if (
            in_array(
                $payout->status,
                [
                    PayoutStatus::Succeeded,
                    PayoutStatus::Processing,
                ],
                true
            )
        ) {
            return;
        }

        $this->attemptPayment(
            $payout,
            $earnings,
            $provider
        );
    }

    protected function attemptPayment(
        Payout $payout,
        $earnings,
        PaymentProviderInterface $provider
    ): void {
        $payout->update([
            'status' => PayoutStatus::Processing,
            'attempted_at' => now(),
        ]);

        try {
            $result = $provider->pay(
                $payout->idempotency_key,
                $payout->amount_cents
            );

            PaymentProviderLog::create([
                'payout_id' => $payout->id,

                'request_payload' => [
                    'idempotency_key' => $payout->idempotency_key,
                    'amount_cents' => $payout->amount_cents,
                ],

                'response_payload' => [
                    'outcome' => $result->outcome->name,
                    'reference' => $result->providerReference,
                    'message' => $result->message,
                ],

                'outcome' => $result->outcome,
            ]);

            match ($result->outcome) {
                ProviderOutcome::Success => $this->markSucceeded(
                    $payout,
                    $earnings,
                    $result->providerReference
                ),

                ProviderOutcome::Failure => $this->markFailed(
                    $payout,
                    $result
                ),

                ProviderOutcome::Timeout,
                ProviderOutcome::NotFound => $this->markUnknown(
                    $payout,
                    $result
                ),
            };
        } catch (Throwable $exception) {
            Log::channel('payouts')->error(
                'Instructor payout provider call failed.',
                [
                    'payout_id' => $payout->id,
                    'instructor_id' => $payout->instructor_id,
                    'idempotency_key' => $payout->idempotency_key,
                    'amount_cents' => $payout->amount_cents,
                    'exception' => $exception->getMessage(),
                    'exception_class' => get_class($exception),
                ]
            );

            throw $exception;
        }
    }

    protected function markFailed(Payout $payout, $result): void
    {
        $payout->update([
            'status' => PayoutStatus::Failed,
        ]);

        Log::channel('payouts')->error(
            'Instructor payout failed.',
            [
                'payout_id' => $payout->id,
                'instructor_id' => $payout->instructor_id,
                'idempotency_key' => $payout->idempotency_key,
                'amount_cents' => $payout->amount_cents,
                'provider_outcome' => $result->outcome->name,
                'provider_reference' => $result->providerReference,
                'provider_message' => $result->message,
            ]
        );
    }

    protected function markUnknown(Payout $payout, $result): void
    {
        $payout->update([
            'status' => PayoutStatus::Unknown,
        ]);

        Log::channel('payouts')->warning(
            'Instructor payout result is unknown.',
            [
                'payout_id' => $payout->id,
                'instructor_id' => $payout->instructor_id,
                'idempotency_key' => $payout->idempotency_key,
                'amount_cents' => $payout->amount_cents,
                'provider_outcome' => $result->outcome->name,
                'provider_reference' => $result->providerReference,
                'provider_message' => $result->message,
            ]
        );
    }

    protected function markSucceeded(
        Payout $payout,
        $earnings,
        ?string $reference
    ): void {
        DB::transaction(function () use (
            $payout,
            $earnings,
            $reference
        ) {
            $payout->update([
                'status' => PayoutStatus::Succeeded,
                'provider_reference' => $reference,
                'confirmed_at' => now(),
            ]);

            foreach ($earnings as $earning) {
                PayoutItem::firstOrCreate([
                    'payout_id' => $payout->id,
                    'instructor_earning_id' => $earning->id,
                ]);

                $earning->update([
                    'status' => EarningStatus::Paid,
                ]);
            }

            $balance = InstructorBalance::where(
                'instructor_id',
                $payout->instructor_id
            )->first();

            if ($balance) {
                $balance->decrement(
                    'total_outstanding_cents',
                    $payout->amount_cents
                );

                $balance->increment(
                    'total_paid_cents',
                    $payout->amount_cents
                );
            }
        });
    }

    protected function buildIdempotencyKey(
        string $instructorId,
        $earnings
    ): string {
        $earningIds = $earnings
            ->pluck('id')
            ->sort()
            ->implode(',');

        return hash(
            'sha256',
            $instructorId . '|' . $earningIds
        );
    }

    /**
     * Called after Laravel exhausts all queue retries.
     */
    public function failed(Throwable $exception): void
    {
        Log::channel('payouts')->critical(
            'PayInstructorJob permanently failed after all retries.',
            [
                'instructor_id' => $this->instructorId,
                'job_id' => $this->job?->getJobId(),
                'attempts' => $this->attempts(),
                'exception' => $exception->getMessage(),
                'exception_class' => get_class($exception),
            ]
        );
    }
}