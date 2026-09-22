<?php
// app/Models/Payout.php
namespace App\Models;

use App\Http\Enums\PayoutStatus;
use App\Models\PayoutItem;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Payout extends Model
{
    use HasUuids;

    protected $fillable = [
        'instructor_id', 'payout_method_id', 'amount_cents',
        'idempotency_key', 'status', 'provider_reference',
        'attempted_at', 'confirmed_at',
    ];

    protected $casts = [
        'status' => PayoutStatus::class,
        'attempted_at' => 'datetime',
        'confirmed_at' => 'datetime',
    ];

    public function instructor(): BelongsTo
    {
        return $this->belongsTo(Instructor::class);
    }

    public function payoutMethod(): BelongsTo
    {
        return $this->belongsTo(InstructorPayoutMethod::class, 'payout_method_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PayoutItem::class);
    }

    public function providerLogs(): HasMany
    {
        return $this->hasMany(PaymentProviderLog::class);
    }
}