<?php
// database/factories/SubscriptionFactory.php
namespace Database\Factories;

use App\Http\Enums\SubscriptionPlan;
use App\Http\Enums\SubscriptionStatus;
use App\Models\Student;
use App\Models\Subscription;
use Illuminate\Database\Eloquent\Factories\Factory;

class SubscriptionFactory extends Factory
{
    protected $model = Subscription::class;

    public function definition(): array
    {
        $plan = $this->faker->randomElement(SubscriptionPlan::cases());
        $start = $this->faker->dateTimeBetween('-6 months', 'now');
        $end = (clone $start)->modify("+{$plan->months()} months");

        return [
            'student_id' => Student::factory(),
            'plan' => $plan,
            'amount_paid_cents' => match ($plan) {
                SubscriptionPlan::Monthly => 1000,
                SubscriptionPlan::Quarterly => 2700,
                SubscriptionPlan::Annual => 9600,
            },
            'starts_at' => $start,
            'ends_at' => $end,
            'status' => SubscriptionStatus::Active,
        ];
    }
}