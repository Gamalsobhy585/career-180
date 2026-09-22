<?php
// database/factories/PayoutFactory.php
namespace Database\Factories;

use App\Http\Enums\PayoutStatus;
use App\Models\Instructor;
use App\Models\Payout;
use Illuminate\Database\Eloquent\Factories\Factory;

class PayoutFactory extends Factory
{
    protected $model = Payout::class;

    public function definition(): array
    {
        return [
            'instructor_id' => Instructor::factory(),
            'payout_method_id' => null,
            'amount_cents' => $this->faker->numberBetween(1000, 20000),
            'idempotency_key' => $this->faker->unique()->uuid(),
            'status' => PayoutStatus::Pending,
            'provider_reference' => null,
            'attempted_at' => null,
            'confirmed_at' => null,
        ];
    }
}