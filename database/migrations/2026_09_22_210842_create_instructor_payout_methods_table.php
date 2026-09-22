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
        Schema::create('instructor_payout_methods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('instructor_id')->constrained()->cascadeOnDelete();
            $table->enum('type', ['bank', 'mock_wallet']);
            $table->string('account_identifier'); // IBAN, wallet id, etc. (mocked)
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->index(['instructor_id', 'is_default']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('instructor_payout_methods');
    }
};
