<?php

namespace App\Actions;

use App\Models\Subscription;

class CalculateSubscriptionPeriodsAction
{
    public function execute(Subscription $subscription): array
    {
        $months = $subscription->plan->months();
        $periods = [];
        $cursor = $subscription->starts_at->copy();

        for ($i = 0; $i < $months; $i++) {
            $periods[] = [
                'start' => $cursor->copy(),
                'end' => $cursor->copy()->addMonth()->subDay(),
            ];
            $cursor->addMonth();
        }

        return $periods;
    }
}