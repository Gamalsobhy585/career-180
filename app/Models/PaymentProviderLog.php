<?php
// app/Models/PaymentProviderLog.php
namespace App\Models;

use App\Http\Enums\ProviderOutcome;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentProviderLog extends Model
{
    use HasUuids;

    protected $fillable = ['payout_id', 'request_payload', 'response_payload', 'outcome'];

    protected $casts = [
        'request_payload' => 'array',
        'response_payload' => 'array',
        'outcome' => ProviderOutcome::class,
    ];

    public function payout(): BelongsTo
    {
        return $this->belongsTo(Payout::class);
    }
}