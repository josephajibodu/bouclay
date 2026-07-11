<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The generalized mechanism for anything beyond a simple trial: a paid
     * multi-iteration trial, a transition to a different plan/price when a
     * trial ends, or a true multi-step ramp (schema.md §3). A simple trial
     * (`prices.trial_length` set) never touches this table.
     */
    public function up(): void
    {
        Schema::create('price_phases', function (Blueprint $table) {
            $table->id();
            // The "home" price this schedule is attached to — what a
            // subscription_item nominally references.
            $table->foreignId('price_id')->constrained()->cascadeOnDelete();
            $table->smallInteger('sequence');
            // The price actually charged during this phase — can live under
            // a different plan entirely ("transition after trial"). Restrict
            // deletion: a price serving as a phase target must not vanish.
            $table->foreignId('charge_price_id')->constrained('prices')->restrictOnDelete();
            $table->string('duration_interval');
            $table->integer('duration_count');
            $table->timestamps();

            $table->unique(['price_id', 'sequence']);
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
