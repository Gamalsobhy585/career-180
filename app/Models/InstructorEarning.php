<?php
// app/Models/InstructorEarning.php
namespace App\Models;

use App\Http\Enums\EarningStatus;
use App\Models\PayoutItem;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class InstructorEarning extends Model
{
    use HasUuids;

    protected $fillable = [
        'instructor_id', 'subscription_id',
        'period_start', 'period_end', 'amount_cents', 'status',
    ];

    protected $casts = [
        'status' => EarningStatus::class,
        'period_start' => 'date',
        'period_end' => 'date',
    ];

    public function instructor(): BelongsTo
    {
        return $this->belongsTo(Instructor::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function payoutItem(): HasOne
    {
        return $this->hasOne(PayoutItem::class);
    }
}