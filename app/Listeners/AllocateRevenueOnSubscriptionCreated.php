<?php
namespace App\Listeners;

use App\Actions\AllocateSubscriptionRevenueAction;
use App\Events\SubscriptionCreated;
use Illuminate\Contracts\Queue\ShouldQueue;

class AllocateRevenueOnSubscriptionCreated implements ShouldQueue
{
    public function __construct(protected AllocateSubscriptionRevenueAction $action)
    {
    }

    public function handle(SubscriptionCreated $event): void
    {
        $this->action->execute($event->subscription);
    }
}