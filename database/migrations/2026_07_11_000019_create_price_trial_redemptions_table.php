<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The one piece of trial state worth its own durable row: enforcing
     * `trial_once_per_customer` (schema.md §3). Query by team_id directly —
     * anti-abuse is where "never trust a join to infer the tenant" matters
     * most.
     */
    public function up(): void
    {
        Schema::create('price_trial_redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('price_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_item_id')->constrained()->cascadeOnDelete();
            $table->timestamp('redeemed_at');
            $table->timestamps();

            $table->index(['team_id', 'price_id', 'customer_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('price_trial_redemptions');
    }
};
