<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A subscription carries many priced items (base plan + add-ons). Only
     * plan-bearing recurring prices are valid here — `plan_id` is NOT NULL,
     * so a plan-less one-time price can never be attached (schema.md §3).
     */
    public function up(): void
    {
        Schema::create('subscription_items', function (Blueprint $table) {
            $table->id();
            $table->string('public_id')->unique();
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            $table->foreignId('price_id')->constrained('prices')->cascadeOnDelete();
            // Denormalised alongside price_id (schema.md §6).
            $table->foreignId('plan_id')->constrained('plans')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            // Distinguishes the base charge from add-ons.
            $table->string('kind')->default('plan');
            $table->integer('quantity')->default(1);
            $table->string('status')->default('active');
            // Snapshotted from price.trial_length/trial_unit at creation — a
            // later catalog edit doesn't rewrite history for active items.
            $table->timestamp('trial_ends_at')->nullable();
            // Null unless this item is progressing through price_phases.
            $table->smallInteger('current_phase_sequence')->nullable();
            $table->timestamps();

            $table->index('subscription_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscription_items');
    }
};
