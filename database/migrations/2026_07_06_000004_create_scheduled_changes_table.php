<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Future cancel / pause / resume at the next boundary — the Paddle "borrow"
     * pattern (schema.md §4). "Cancel at period end" writes a row here and
     * leaves `status` untouched; the scheduler applies it at `effective_at`
     * (SUBSCRIPTIONS_DESIGN §4, §8.3).
     */
    public function up(): void
    {
        Schema::create('scheduled_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            $table->string('action');
            $table->timestamp('effective_at');
            $table->json('payload')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();

            $table->index('subscription_id');
            // Scheduler scans for due, not-yet-applied changes.
            $table->index(['applied_at', 'effective_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('scheduled_changes');
    }
};
