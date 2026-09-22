<?php
// database/factories/RefundFactory.php
namespace Database\Factories;

use App\Models\Refund;
use App\Models\Subscription;
use Illuminate\Database\Eloquent\Factories\Factory;

class RefundFactory extends Factory
{
    protected $model = Refund::class;

    public function definition(): array
    {
        return [
            'subscription_id' => Subscription::factory(),
            'amount_cents' => $this->faker->numberBetween(500, 5000),
            'reason' => $this->faker->sentence(),
            'refunded_at' => now(),
        ];
    }
}