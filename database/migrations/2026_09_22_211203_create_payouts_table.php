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
        // create_payouts_table
        Schema::create('payouts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('instructor_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('payout_method_id')->nullable()
                ->constrained('instructor_payout_methods')->nullOnDelete();
            $table->unsignedBigInteger('amount_cents');
            $table->string('idempotency_key')->unique();
            $table->unsignedTinyInteger('status')->default(1); // PayoutStatus
            $table->string('provider_reference')->nullable();
            $table->timestamp('attempted_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();

            $table->index(['instructor_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payouts');
    }
};
