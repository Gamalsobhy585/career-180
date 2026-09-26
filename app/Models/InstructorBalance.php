<?php
// app/Models/InstructorBalance.php
namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InstructorBalance extends Model
{
    use HasUuids;

    public $timestamps = false; // has updated_at only, managed manually

    protected $fillable = [
        'instructor_id', 'total_earned_cents',
        'total_paid_cents', 'total_outstanding_cents', 'updated_at',
    ];

    protected $casts = [
        'updated_at' => 'datetime',
    ];

    public function instructor(): BelongsTo
    {
        return $this->belongsTo(Instructor::class);
    }
    public function payouts()
{
    return $this->hasManyThrough(
        \App\Models\Payout::class,
        \App\Models\Instructor::class,
        'id',            // Instructor's key
        'instructor_id', // Payout's foreign key
        'instructor_id', // InstructorBalance's local key
        'id'             // Instructor's local key
    );
}
}