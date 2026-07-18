<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The resolved, customer-specific counterpart of `price_phase_steps`
     * (schema.md §5): durations become absolute `starts_at`/`ends_at`
     * dates at copy time, so the advance-schedule worker never re-derives
     * boundaries from interval arithmetic — it just reads `ends_at`.
     * `ends_at` null marks the terminal ("forever") step.
     */
    public function up(): void
    {
        Schema::create('subscription_schedule_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('schedule_id')->constrained('subscription_schedules')->cascadeOnDelete();
            $table->smallInteger('sequence');
            $table->foreignId('price_id')->constrained('prices')->restrictOnDelete();
            $table->integer('quantity')->default(1);
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();

            $table->unique(['schedule_id', 'sequence']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscription_schedule_steps');
    }
};
