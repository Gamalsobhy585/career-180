<?php
namespace App\Actions;

use App\Http\Enums\EarningStatus;
use App\Http\Enums\SubscriptionStatus;
use App\Models\InstructorBalance;
use App\Models\InstructorEarning;
use App\Models\Refund;
use App\Models\Subscription;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

class ProcessRefundAction
{
    /**
     * Refund a subscription as of a given date (defaults to now).
     *
     * Decision (documented in README): only earnings for periods that have
     * NOT YET STARTED as of the refund date are reversible. Once a period
     * has started, the instructor is treated as having earned that period
     * in full — we don't claw back mid-month. This keeps the ledger simple
     * and matches how most revenue-share platforms treat "already earned"
     * income: partially-delivered service in the current period is not
     * undone, only future, not-yet-begun periods are cancelled.
     *
     * Earnings already marked Paid are never reversed here — that money
     * has already left the platform. Recovering it (clawback from the
     * instructor) is a separate, manual process, out of scope for this
     * action. This action only prevents *future* payout of money that
     * hasn't been sent yet.
     */
    public function execute(Subscription $subscription, ?string $reason = null, ?Carbon $refundedAt = null): Refund
    {
        if ($subscription->status === SubscriptionStatus::Refunded) {
            // Idempotency guard: refunding an already-refunded subscription
            // is a no-op — return the existing refund record instead of
            // creating a duplicate or reversing anything twice.
            $existing = $subscription->refunds()->latest()->first();
            if ($existing) {
                return $existing;
            }
        }

        $refundedAt ??= now();

        return DB::transaction(function () use ($subscription, $reason, $refundedAt) {
            $reversibleEarnings = InstructorEarning::where('subscription_id', $subscription->id)
                ->where('status', EarningStatus::Pending)
                ->whereDate('period_start', '>', $refundedAt->toDateString())
                ->lockForUpdate()
                ->get();

            $reversedTotal = 0;

            foreach ($reversibleEarnings as $earning) {
                $earning->update(['status' => EarningStatus::Reversed]);
                $reversedTotal += $earning->amount_cents;

                $this->decrementBalance($earning->instructor_id, $earning->amount_cents);
            }

            $subscription->update(['status' => SubscriptionStatus::Refunded]);

            return Refund::create([
                'subscription_id' => $subscription->id,
                'amount_cents' => $reversedTotal,
                'reason' => $reason,
                'refunded_at' => $refundedAt,
            ]);
        });
    }

    protected function decrementBalance(string $instructorId, int $amountCents): void
    {
        $balance = InstructorBalance::where('instructor_id', $instructorId)->first();

        if ($balance) {
            $balance->decrement('total_earned_cents', $amountCents);
            $balance->decrement('total_outstanding_cents', $amountCents);
        }
    }
}