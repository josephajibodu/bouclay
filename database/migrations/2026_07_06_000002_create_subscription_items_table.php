<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A subscription carries many priced items (base + add-ons). Each item is
     * a plain price line or a trial line (its trial lives in
     * `subscription_item_trials`) — SUBSCRIPTIONS_DESIGN §11.
     */
    public function up(): void
    {
        Schema::create('subscription_items', function (Blueprint $table) {
            $table->id();
            $table->string('public_id')->unique();
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            $table->foreignId('price_id')->constrained('prices')->cascadeOnDelete();
            // Denormalised — the product the price belongs to at application time.
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->integer('quantity')->default(1);
            $table->string('status')->default('active');
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
