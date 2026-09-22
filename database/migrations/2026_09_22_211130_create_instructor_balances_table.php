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
        Schema::create('instructor_balances', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('instructor_id')->constrained()->cascadeOnDelete()->unique();
            $table->unsignedBigInteger('total_earned_cents')->default(0);
            $table->unsignedBigInteger('total_paid_cents')->default(0);
            $table->unsignedBigInteger('total_outstanding_cents')->default(0);
            $table->timestamp('updated_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('instructor_balances');
    }
};
