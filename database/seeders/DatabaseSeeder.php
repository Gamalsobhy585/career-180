<?php
// database/seeders/DatabaseSeeder.php
namespace Database\Seeders;

use App\Http\Enums\SubscriptionPlan;
use App\Http\Enums\SubscriptionStatus;
use App\Models\Course;
use App\Models\Instructor;
use App\Models\InstructorPayoutMethod;
use App\Models\PlatformSetting;
use App\Models\Student;
use App\Models\Subscription;
use Illuminate\Database\Seeder;
use App\Events\SubscriptionCreated;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        PlatformSetting::factory()->create([
            'key' => 'platform_cut_percentage',
            'value' => '20',
        ]);

        $instructors = Instructor::factory()
            ->count(20)
            ->has(InstructorPayoutMethod::factory(), 'payoutMethods')
            ->has(Course::factory()->count(5), 'courses')
            ->create();

        $students = Student::factory()->count(200)->create();

        $allCourses = Course::all();

        $students->each(function (Student $student) use ($allCourses) {
            $subscriptionsCount = fake()->numberBetween(1, 2);

            for ($i = 0; $i < $subscriptionsCount; $i++) {
                $plan = fake()->randomElement(SubscriptionPlan::cases());
                $start = fake()->dateTimeBetween('-6 months', 'now');
                $end = (clone $start)->modify("+{$plan->months()} months");

                $subscription = Subscription::factory()->create([
                    'student_id' => $student->id,
                    'plan' => $plan,
                    'starts_at' => $start,
                    'ends_at' => $end,
                    'status' => SubscriptionStatus::Active,
                ]);

                $courses = $allCourses->random(fake()->numberBetween(2, 5));
                $subscription->courses()->attach($courses->pluck('id'));
                event(new SubscriptionCreated($subscription->fresh())); 
            }
        });
    }
}