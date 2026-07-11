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
        // One table, three behaviours (tiered / volume / graduated) — only
        // the billing-time application differs (schema.md §3).
        Schema::create('price_tiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('price_id')->constrained()->cascadeOnDelete();
            $table->smallInteger('tier_index');
            $table->bigInteger('up_to')->nullable();
            $table->bigInteger('unit_amount');
            $table->bigInteger('flat_amount')->nullable();
        });

        // Optional multi-currency presentation — schema-present, logic
        // deferred (schema.md build order).
        Schema::create('price_currency_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('price_id')->constrained()->cascadeOnDelete();
            $table->char('currency', 3);
            $table->bigInteger('unit_amount');
            $table->timestamps();

            $table->unique(['price_id', 'currency']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('price_currency_options');
        Schema::dropIfExists('price_tiers');
    }
};
