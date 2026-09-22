<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('instructor_earnings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('instructor_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->unsignedBigInteger('amount_cents');
            $table->enum('status', ['pending', 'paid', 'reversed'])->default('pending');
            $table->timestamps();

            // prevents double-allocating the same instructor for the same
            // subscription + period — your main allocation-side idempotency guard
            $table->unique(
                ['instructor_id', 'subscription_id', 'period_start', 'period_end'],
                'earning_unique_period'
            );
            $table->index(['instructor_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('instructor_earnings');
    }
};
