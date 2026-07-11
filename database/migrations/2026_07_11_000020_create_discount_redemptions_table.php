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
        Schema::create('discount_redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('discount_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            // How many billing cycles this discount may STILL be applied to
            // this subscription. Snapshotted at redemption: once → 1,
            // repeating → duration_in_intervals, forever → null (never
            // decrements). The renewal worker applies while null or > 0,
            // decrementing each cycle it applies (GAP-1 resolution).
            $table->integer('remaining_intervals')->nullable();
            $table->timestamp('applied_at');
            $table->timestamp('last_applied_at')->nullable();
            $table->timestamps();

            $table->index(['discount_id', 'customer_id']);
            $table->index('subscription_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('discount_redemptions');
    }
};
