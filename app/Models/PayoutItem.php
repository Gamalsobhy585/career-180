<?php
// app/Models/PayoutItem.php
namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayoutItem extends Model
{
    use HasUuids;

    protected $fillable = ['payout_id', 'instructor_earning_id'];

    public function payout(): BelongsTo
    {
        return $this->belongsTo(Payout::class);
    }

    public function earning(): BelongsTo
    {
        return $this->belongsTo(InstructorEarning::class, 'instructor_earning_id');
    }
}