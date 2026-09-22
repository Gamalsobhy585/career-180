<?php
// app/Models/Subscription.php
namespace App\Models;
use App\Http\Enums\SubscriptionPlan;
use App\Http\Enums\SubscriptionStatus;
use App\Models\Student;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Subscription extends Model
{
    use HasUuids;

    protected $fillable = [
        'student_id', 'plan', 'amount_paid_cents',
        'starts_at', 'ends_at', 'status',
    ];

    protected $casts = [
        'plan' => SubscriptionPlan::class,
        'status' => SubscriptionStatus::class,
        'starts_at' => 'date',
        'ends_at' => 'date',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function courses(): BelongsToMany
    {
        return $this->belongsToMany(Course::class);
    }

    public function earnings(): HasMany
    {
        return $this->hasMany(InstructorEarning::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    /** Distinct instructors covered by this subscription's courses. */
    public function instructors(): \Illuminate\Support\Collection
    {
        return $this->courses()->with('instructor')->get()
            ->pluck('instructor')
            ->unique('id');
    }
}