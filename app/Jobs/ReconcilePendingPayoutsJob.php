<?php

namespace App\Jobs;

use App\Http\Enums\PayoutStatus;
use App\Http\Enums\ProviderOutcome;
use App\Models\Payout;
use App\Models\PaymentProviderLog;
use App\Payment\PaymentProviderInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class ReconcilePendingPayoutsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function handle(PaymentProviderInterface $provider): void
    {
        Payout::where('status', PayoutStatus::Unknown)
            ->chunkById(
                100,
                function ($payouts) use ($provider) {
                    foreach ($payouts as $payout) {
                        $this->reconcile($payout, $provider);
                    }
                }
            );
    }

    protected function reconcile(
        Payout $payout,
        PaymentProviderInterface $provider
    ): void {
        try {
            $result = $provider->checkStatus($payout->idempotency_key);

            PaymentProviderLog::create([
                'payout_id' => $payout->id,

                'request_payload' => [
                    'idempotency_key' => $payout->idempotency_key,
                ],

                'response_payload' => [
                    'outcome' => $result->outcome->name,
                    'reference' => $result->providerReference,
                    'message' => $result->message ?? null,
                ],

                'outcome' => $result->outcome,
            ]);

            match ($result->outcome) {
                // Shared, lock-protected, idempotent success handling.
                // It uses the payout's own reserved items (payout_items),
                // never "whatever earnings are pending right now".
                ProviderOutcome::Success => PayInstructorJob::finalizePayout(
                    $payout->id,
                    $result->providerReference
                ),

                ProviderOutcome::Failure => $this->markFailed(
                    $payout,
                    $result
                ),

                ProviderOutcome::Timeout,
                ProviderOutcome::NotFound => $this->logUnknown(
                    $payout,
                    $result
                ),
            };
        } catch (Throwable $exception) {
            Log::channel('payouts')->error(
                'Payout reconciliation failed.',
                [
                    'payout_id' => $payout->id,
                    'instructor_id' => $payout->instructor_id,
                    'idempotency_key' => $payout->idempotency_key,
                    'exception' => $exception->getMessage(),
                    'exception_class' => get_class($exception),
                ]
            );

            throw $exception;
        }
    }

    protected function markFailed(Payout $payout, $result): void
    {
        // Marks the payout Failed and releases its reserved earnings for a new payout.
        PayInstructorJob::releaseFailedPayout($payout->id);

        Log::channel('payouts')->error(
            'Payout reconciliation confirmed provider failure.',
            [
                'payout_id' => $payout->id,
                'instructor_id' => $payout->instructor_id,
                'idempotency_key' => $payout->idempotency_key,
                'provider_outcome' => $result->outcome->name,
                'provider_reference' => $result->providerReference,
                'provider_message' => $result->message ?? null,
            ]
        );
    }

    protected function logUnknown(Payout $payout, $result): void
    {
        Log::channel('payouts')->warning(
            'Payout reconciliation still has unknown provider status.',
            [
                'payout_id' => $payout->id,
                'instructor_id' => $payout->instructor_id,
                'idempotency_key' => $payout->idempotency_key,
                'provider_outcome' => $result->outcome->name,
                'provider_reference' => $result->providerReference,
                'provider_message' => $result->message ?? null,
            ]
        );
    }

    public function failed(Throwable $exception): void
    {
        Log::channel('payouts')->critical(
            'ReconcilePendingPayoutsJob permanently failed after all retries.',
            [
                'job_id' => $this->job?->getJobId(),
                'attempts' => $this->attempts(),
                'exception' => $exception->getMessage(),
                'exception_class' => get_class($exception),
            ]
        );
    }
}