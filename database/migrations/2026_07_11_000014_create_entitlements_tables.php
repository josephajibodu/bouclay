<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Decoupled access-control layer (schema.md §4): an entitlement is a
     * named capability, granted by one or more plans/products, checked
     * independently of invoice/payment state.
     */
    public function up(): void
    {
        Schema::create('entitlements', function (Blueprint $table) {
            $table->id();
            $table->string('public_id')->unique();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            // The key application code checks against.
            $table->string('code');
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestamps();

            $table->unique(['team_id', 'code']);
        });

        // Polymorphic join with an ENFORCED morph map — grantor_type stores
        // the stable alias (`plan` / `product`), never a class FQN. Grants
        // are catalog-only by design; a `customer` alias is reserved for
        // future per-customer grants (additive, no migration).
        Schema::create('entitlement_grants', function (Blueprint $table) {
            $table->id();
            // Denormalised — never rely on the entitlement_id join to scope
            // queries (tenancy convention).
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('entitlement_id')->constrained()->cascadeOnDelete();
            $table->morphs('grantor');
            $table->timestamps();

            $table->unique(['entitlement_id', 'grantor_type', 'grantor_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('entitlement_grants');
        Schema::dropIfExists('entitlements');
    }
};
