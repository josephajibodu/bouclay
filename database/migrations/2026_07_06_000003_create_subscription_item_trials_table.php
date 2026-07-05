<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The concrete trial applied to one subscription item — a snapshot of the
     * catalog `trial_offers` row so later edits don't rewrite history
     * (schema.md §5). Trials attach per item, not per subscription.
     */
    public function up(): void
    {
        Schema::create('subscription_item_trials', function (Blueprint $table) {
            $table->id();
            $table->string('public_id')->unique();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_item_id')->constrained()->cascadeOnDelete();
            // Null = ad-hoc inline trial with no catalog offer behind it.
            $table->foreignId('trial_offer_id')->nullable()->constrained('trial_offers')->nullOnDelete();
            // Denormalised — enforces once_per_customer anti-abuse.
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('trial_price_id')->constrained('prices')->cascadeOnDelete();
            $table->foreignId('transition_price_id')->constrained('prices')->cascadeOnDelete();
            $table->string('duration_type');
            $table->integer('duration_iterations')->nullable();
            $table->timestamp('duration_ends_at')->nullable();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->string('status')->default('active');
            $table->timestamp('converted_at')->nullable();
            $table->timestamps();

            $table->index('subscription_item_id');
            // Anti-abuse: has this customer used this offer before?
            $table->index(['customer_id', 'trial_offer_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscription_item_trials');
    }
};
