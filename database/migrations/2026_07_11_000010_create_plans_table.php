<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The named tier a customer actually picks — "Premium". Deliberately
     * thin: no billing_interval, no pricing_model, no trial fields here; all
     * of that varies per billable variant and lives on `prices` (schema.md §3).
     *
     * A `draft`/`archived` plan's prices are not purchasable regardless of
     * the price's own status — enforced at the application layer, not here.
     */
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('public_id')->unique();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('code')->nullable();
            $table->string('name');
            $table->string('status')->default('active');
            $table->json('custom_data')->nullable();
            $table->timestamps();

            $table->index(['team_id', 'product_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
