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
        Schema::create('trial_offers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('trial_price_id')->constrained('prices')->cascadeOnDelete();
            $table->boolean('transition_to_different_product')->default(false);
            $table->foreignId('transition_product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->foreignId('transition_price_id')->constrained('prices')->cascadeOnDelete();
            $table->string('duration_type')->default('relative');
            $table->integer('duration_iterations')->nullable();
            $table->timestamp('duration_ends_at')->nullable();
            $table->boolean('once_per_customer')->default(true);
            $table->boolean('active')->default(true);
            $table->json('custom_data')->nullable();
            $table->timestamps();

            $table->index(['team_id', 'product_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('trial_offers');
    }
};
