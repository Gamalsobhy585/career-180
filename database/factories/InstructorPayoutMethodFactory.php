<?php
// database/factories/InstructorPayoutMethodFactory.php
namespace Database\Factories;

use App\Http\Enums\PayoutMethodType;
use App\Models\Instructor;
use App\Models\InstructorPayoutMethod;
use Illuminate\Database\Eloquent\Factories\Factory;

class InstructorPayoutMethodFactory extends Factory
{
    protected $model = InstructorPayoutMethod::class;

    public function definition(): array
    {
        return [
            'instructor_id' => Instructor::factory(),
            'type' => $this->faker->randomElement(PayoutMethodType::cases()),
            'account_identifier' => $this->faker->iban(),
            'is_default' => true,
        ];
    }
}