<?php
// app/Console/Commands/SimulateSubscriptionActivity.php
namespace App\Console\Commands;

use App\Events\SubscriptionCreated;
use App\Http\Enums\SubscriptionPlan;
use App\Models\Course;
use App\Models\Student;
use App\Models\Subscription;
use Illuminate\Console\Command;

class SimulateSubscriptionActivity extends Command
{
    protected $signature = 'demo:simulate-subscriptions {--count=5}';
    protected $description = 'Create random subscriptions to demonstrate live balance updates.';

    public function handle(): int
    {
        $courses = Course::inRandomOrder()->limit(20)->get();

        for ($i = 0; $i < (int) $this->option('count'); $i++) {
            $student = Student::factory()->create();
            $plan = fake()->randomElement(SubscriptionPlan::cases());

            $subscription = Subscription::factory()->create([
                'student_id' => $student->id,
                'plan' => $plan,
            ]);
            $subscription->courses()->attach($courses->random(3)->pluck('id'));

            event(new SubscriptionCreated($subscription));
        }

        $this->info("Created {$this->option('count')} demo subscriptions.");
        return self::SUCCESS;
    }
}