<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Ordered steps within a Pricing Journey (schema.md §3). Each step
     * charges a real `prices` row — never an inline/ad hoc amount — so
     * pricing always traces back to a single defined Price, and a step's
     * price must belong to the journey's own `product_id` (enforced at the
     * application layer, not the DB, since it spans two FK paths).
     *
     * `duration_interval`/`duration_count` both null marks the terminal
     * ("forever") step — always the last step in a journey by construction.
     */
    public function up(): void
    {
        Schema::create('price_phase_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('price_phases_id')->constrained('price_phases')->cascadeOnDelete();
            $table->smallInteger('sequence');
            // Restrict deletion: a price serving as a journey step must not vanish.
            $table->foreignId('price_id')->constrained('prices')->restrictOnDelete();
            $table->integer('quantity')->default(1);
            $table->string('duration_interval')->nullable();
            $table->integer('duration_count')->nullable();
            $table->timestamps();

            $table->unique(['price_phases_id', 'sequence']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('price_phase_steps');
    }
};
