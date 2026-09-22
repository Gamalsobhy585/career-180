<?php
// app/Models/Instructor.php
namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Instructor extends Model
{
    use HasUuids;

    protected $fillable = ['name', 'email'];

    public function courses(): HasMany
    {
        return $this->hasMany(Course::class);
    }

    public function payoutMethods(): HasMany
    {
        return $this->hasMany(InstructorPayoutMethod::class);
    }

    public function defaultPayoutMethod(): HasOne
    {
        return $this->hasOne(InstructorPayoutMethod::class)->where('is_default', true);
    }

    public function earnings(): HasMany
    {
        return $this->hasMany(InstructorEarning::class);
    }

    public function payouts(): HasMany
    {
        return $this->hasMany(Payout::class);
    }

    public function balance(): HasOne
    {
        return $this->hasOne(InstructorBalance::class);
    }
}