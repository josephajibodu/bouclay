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
        Schema::create('discounts', function (Blueprint $table) {
            $table->id();
            $table->string('public_id')->unique();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('code')->nullable();
            $table->string('type');
            // Minor units (flat); null for percentage.
            $table->bigInteger('amount')->nullable();
            $table->decimal('percentage', 5, 2)->nullable();
            // Required for flat.
            $table->char('currency', 3)->nullable();
            $table->string('duration');
            $table->integer('duration_in_intervals')->nullable();
            $table->integer('max_redemptions')->nullable();
            $table->integer('times_redeemed')->default(0);
            // Array of plan ids; null = all plans.
            $table->json('eligible_plan_ids')->nullable();
            // When set, the COMPLETE authoritative eligibility list —
            // eligible_plan_ids is ignored, never combined (schema.md §7).
            $table->json('eligible_price_ids')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            // Unique within a team when set (nulls don't collide).
            $table->unique(['team_id', 'code']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('discounts');
    }
};
