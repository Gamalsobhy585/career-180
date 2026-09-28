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
use Illuminate\Support\Str;
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

        // Step 1: reserve earnings + create payout atomically (before any provider call).
        $payout = $this->reservePayout($instructor);

        if (! $payout) {
            return;
        }

        // Step 2: call the provider outside the reservation transaction.
        $this->attemptPayment($payout, $provider);
    }

    /**
     * Creates the payout AND attaches the earnings (payout_items) inside ONE transaction.
     * Locking the instructor row serializes every worker for this instructor, so a second
     * worker can only run after the first has committed its reservation.
     */
    protected function reservePayout(Instructor $instructor): ?Payout
    {
        return DB::transaction(function () use ($instructor) {
            Instructor::whereKey($instructor->id)->lockForUpdate()->first();

            // A payout that was reserved but never attempted (e.g. worker crashed): resume it.
            $resumable = Payout::where('instructor_id', $instructor->id)
                ->where('status', PayoutStatus::Pending)
                ->oldest()
                ->lockForUpdate()
                ->first();

            if ($resumable) {
                return $resumable;
            }

            $earnings = InstructorEarning::where('instructor_id', $instructor->id)
                ->where('status', EarningStatus::Pending)
                ->whereDoesntHave('payoutItem')
                ->lockForUpdate()
                ->get();

            if ($earnings->isEmpty()) {
                return null;
            }

            $payout = Payout::create([
                'instructor_id' => $instructor->id,
                'payout_method_id' => optional($instructor->defaultPayoutMethod)->id,
                'amount_cents' => $earnings->sum('amount_cents'),
                'status' => PayoutStatus::Pending,
                'idempotency_key' => $this->buildIdempotencyKey($instructor->id, $earnings),
            ]);

            foreach ($earnings as $earning) {
                PayoutItem::create([
                    'payout_id' => $payout->id,
                    'instructor_earning_id' => $earning->id,
                ]);
            }

            return $payout;
        });
    }

    protected function attemptPayment(
        Payout $payout,
        PaymentProviderInterface $provider
    ): void {
        // Claim: only one worker can move the payout Pending -> Processing.
        $payout = DB::transaction(function () use ($payout) {
            $locked = Payout::whereKey($payout->id)->lockForUpdate()->first();

            if (! $locked || $locked->status !== PayoutStatus::Pending) {
                return null;
            }

            $locked->update([
                'status' => PayoutStatus::Processing,
                'attempted_at' => now(),
            ]);

            return $locked;
        });

        if (! $payout) {
            return;
        }

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
                ProviderOutcome::Success => self::finalizePayout(
                    $payout->id,
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
            // We can't be sure what happened at the provider: let reconciliation decide.
            Payout::whereKey($payout->id)
                ->where('status', PayoutStatus::Processing)
                ->update(['status' => PayoutStatus::Unknown]);

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
        self::releaseFailedPayout($payout->id);

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

    /**
     * Idempotent + concurrency-safe success handling. Shared with ReconcilePendingPayoutsJob.
     * The payout row lock guarantees that only the first caller applies the success;
     * everyone else waits, sees Succeeded, and returns.
     */
    public static function finalizePayout(int|string $payoutId, ?string $reference): void
    {
        DB::transaction(function () use ($payoutId, $reference) {
            $payout = Payout::whereKey($payoutId)->lockForUpdate()->firstOrFail();

            if ($payout->status === PayoutStatus::Succeeded) {
                return;
            }

            $payout->update([
                'status' => PayoutStatus::Succeeded,
                'provider_reference' => $reference,
                'confirmed_at' => now(),
            ]);

            // Immutable snapshot: only the earnings reserved for THIS payout.
            $earningIds = PayoutItem::where('payout_id', $payout->id)
                ->pluck('instructor_earning_id');

            $earnings = InstructorEarning::whereIn('id', $earningIds)
                ->where('status', EarningStatus::Pending)
                ->lockForUpdate()
                ->get();

            foreach ($earnings as $earning) {
                $earning->update(['status' => EarningStatus::Paid]);
            }

            $balance = InstructorBalance::where('instructor_id', $payout->instructor_id)
                ->lockForUpdate()
                ->first();

            if ($balance) {
                $balance->decrement('total_outstanding_cents', $payout->amount_cents);
                $balance->increment('total_paid_cents', $payout->amount_cents);
            }
        });
    }

    /**
     * Definitive provider failure: release the reserved earnings so a later run
     * can pay them in a new payout.
     */
    public static function releaseFailedPayout(int|string $payoutId): void
    {
        DB::transaction(function () use ($payoutId) {
            $payout = Payout::whereKey($payoutId)->lockForUpdate()->firstOrFail();

            if (in_array($payout->status, [PayoutStatus::Succeeded, PayoutStatus::Failed], true)) {
                return;
            }

            PayoutItem::where('payout_id', $payout->id)->delete();

            $payout->update(['status' => PayoutStatus::Failed]);
        });
    }

    protected function buildIdempotencyKey(string $instructorId, $earnings): string
    {
        $earningIds = $earnings->pluck('id')->sort()->implode(',');

        // The UUID makes each reservation unique, so a released (failed) payout
        // can be retried with a fresh key. Idempotency comes from the reservation itself.
        return hash('sha256', $instructorId . '|' . $earningIds . '|' . Str::uuid());
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