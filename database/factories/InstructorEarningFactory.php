<?php
// database/factories/InstructorEarningFactory.php
namespace Database\Factories;

use App\Http\Enums\EarningStatus;
use App\Models\Instructor;
use App\Models\InstructorEarning;
use App\Models\Subscription;
use Illuminate\Database\Eloquent\Factories\Factory;

class InstructorEarningFactory extends Factory
{
    protected $model = InstructorEarning::class;

    public function definition(): array
    {
        $start = $this->faker->dateTimeBetween('-3 months', 'now');
        $end = (clone $start)->modify('+1 month');

        return [
            'instructor_id' => Instructor::factory(),
            'subscription_id' => Subscription::factory(),
            'period_start' => $start,
            'period_end' => $end,
            'amount_cents' => $this->faker->numberBetween(500, 5000),
            'status' => EarningStatus::Pending,
        ];
    }
}