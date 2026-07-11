<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Future actions applied at a boundary (the Paddle "borrow" pattern) —
     * lifecycle changes AND deferred item changes. `action=update` carries a
     * payload `{subscription_item_id, price_id?, plan_id?, quantity?,
     * remove?}`; one row per item change (schema.md §6).
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
