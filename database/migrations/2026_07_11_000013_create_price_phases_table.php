<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A "Pricing Journey" (schema.md §3) — a reusable, merchant-authored
     * multi-phase commercial offer scoped to one Product, e.g. "$1/mo for 3
     * months, then $10/mo forever." Its steps live in `price_phase_steps`
     * and reference real `prices` rows across any of the product's plans.
     * A journey is a template: it never holds billing state itself — that's
     * copied into a customer-owned `subscription_schedules` row the moment
     * a subscription is created through it (schema.md §5).
     */
    public function up(): void
    {
        Schema::create('price_phases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            // enum: active / archived — never hard-deleted once referenced by
            // a subscription_schedules row (preserves reporting integrity).
            $table->string('status')->default('active');
            $table->timestamps();

            $table->index(['team_id', 'product_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('price_phases');
    }
};
