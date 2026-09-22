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
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->enum('plan', ['monthly', 'quarterly', 'annual']);
            $table->unsignedBigInteger('amount_paid_cents'); // store money as integer cents
            $table->date('starts_at');
            $table->date('ends_at');
            $table->enum('status', ['active', 'refunded', 'cancelled'])->default('active');
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
