<?php
namespace App\Actions;

use App\Http\Enums\EarningStatus;
use App\Models\InstructorBalance;
use App\Models\InstructorEarning;
use App\Models\PlatformSetting;
use App\Models\Subscription;
use Illuminate\Support\Facades\DB;

class AllocateSubscriptionRevenueAction
{
    public function __construct(protected CalculateSubscriptionPeriodsAction $periodsAction)
    {
    }

    public function execute(Subscription $subscription): void
    {
        $instructors = $subscription->instructors();
        if ($instructors->isEmpty()) return;

        $platformCutPercent = (int) PlatformSetting::get('platform_cut_percentage', 20);
        $periods = $this->periodsAction->execute($subscription);

        DB::transaction(function () use ($subscription, $instructors, $platformCutPercent, $periods) {
            foreach ($periods as $period) {
                $this->allocatePeriod($subscription, $instructors, $platformCutPercent, $period);
            }
        });
    }

  
    protected function allocatePeriod(
        Subscription $subscription,
        \Illuminate\Support\Collection $instructors,
        int $platformCutPercent,
        array $period
    ): void {
        $months = $subscription->plan->months();
        $monthlyGross = intdiv($subscription->amount_paid_cents, $months);
        $monthlyNet = $monthlyGross - intdiv($monthlyGross * $platformCutPercent, 100);

        $instructorCount = $instructors->count();
        $baseShare = intdiv($monthlyNet, $instructorCount);
        $remainder = $monthlyNet - ($baseShare * $instructorCount);

        foreach ($instructors->values() as $index => $instructor) {
            $amount = $baseShare + ($index < $remainder ? 1 : 0);

            $earning = InstructorEarning::firstOrCreate(
                [
                    'instructor_id' => $instructor->id,
                    'subscription_id' => $subscription->id,
                    'period_start' => $period['start']->toDateString(),
                    'period_end' => $period['end']->toDateString(),
                ],
                [
                    'amount_cents' => $amount,
                    'status' => EarningStatus::Pending,
                ]
            );

            // only bump the balance if this earning was actually just created
            // (firstOrCreate on an existing row must not double-count the balance)
            if ($earning->wasRecentlyCreated) {
                $this->incrementBalance($instructor->id, $amount);
            }
        }
    }

    protected function incrementBalance(string $instructorId, int $amountCents): void
    {
        $balance = InstructorBalance::firstOrCreate(
            ['instructor_id' => $instructorId],
            ['total_earned_cents' => 0, 'total_paid_cents' => 0, 'total_outstanding_cents' => 0]
        );

        $balance->increment('total_earned_cents', $amountCents);
        $balance->increment('total_outstanding_cents', $amountCents);
    }
}