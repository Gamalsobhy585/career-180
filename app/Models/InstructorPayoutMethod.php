<?php
// app/Models/InstructorPayoutMethod.php
namespace App\Models;

use App\Http\Enums\PayoutMethodType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InstructorPayoutMethod extends Model
{
    use HasUuids;

    protected $fillable = ['instructor_id', 'type', 'account_identifier', 'is_default'];

    protected $casts = [
        'type' => PayoutMethodType::class,
        'account_identifier' => 'encrypted', // sensitive data, never stored plaintext
        'is_default' => 'boolean',
    ];

    public function instructor(): BelongsTo
    {
        return $this->belongsTo(Instructor::class);
    }

    public function payouts(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Payout::class, 'payout_method_id');
    }
}