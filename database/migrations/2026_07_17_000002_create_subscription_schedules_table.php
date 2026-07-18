<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A customer-owned copy of a Pricing Journey (or an ad hoc, journey-less
     * step sequence), forked off the moment a subscription is created
     * through it (schema.md §5) — the same snapshot pattern already used
     * for invoices. From this point on, editing the source journey never
     * touches this row, and editing this row never touches the journey or
     * any other customer.
     *
     * `subscription_item_id` is the specific plan-kind item this schedule
     * drives (v1: base plan item only, never an add-on). `price_phases_id`
     * is kept as a non-authoritative reference for reporting only ("how
     * many active subs came from Starter Offer") — no billing, invoicing,
     * or dunning logic may read from it; it's nulled if the journey is
     * later hard-deleted (journeys are archived, not deleted, in practice).
     */
    public function up(): void
    {
        Schema::create('subscription_schedules', function (Blueprint $table) {
            $table->id();
            $table->string('public_id')->unique();
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_item_id')->constrained('subscription_items')->cascadeOnDelete();
            $table->foreignId('price_phases_id')->nullable()->constrained('price_phases')->nullOnDelete();
            // enum: release / cancel — what happens once the last step is reached.
            $table->string('end_behavior');
            // enum: active / completed / canceled.
            $table->string('status')->default('active');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index('subscription_id');
            $table->index('subscription_item_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscription_schedules');
    }
};
