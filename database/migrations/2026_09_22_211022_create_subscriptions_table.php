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
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('student_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('plan'); // SubscriptionPlan
            $table->unsignedBigInteger('amount_paid_cents');
            $table->date('starts_at');
            $table->date('ends_at');
            $table->unsignedTinyInteger('status')->default(1); // SubscriptionStatus
            $table->timestamps();

            $table->index(['status', 'ends_at']);
            $table->index('student_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
