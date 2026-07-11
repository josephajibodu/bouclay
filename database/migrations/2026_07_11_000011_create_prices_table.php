<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Immutability invariant (schema.md §3): once a price row is referenced
     * by any subscription_item or invoice_line it is append-only. A merchant
     * "edit" creates a NEW row (`replaces_price_id` → the old one, version+1)
     * and archives the original. Only `status` and `custom_data` are ever
     * safe to mutate on a live price.
     */
    public function up(): void
    {
        Schema::create('prices', function (Blueprint $table) {
            $table->id();
            $table->string('public_id')->unique();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            // Denormalised — always resolvable whether or not plan_id is set.
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            // Set when this price is a variant of a plan; null for a one-time
            // price sold directly off the product. A plan-less price can
            // never be referenced by a subscription_item (application-layer).
            $table->foreignId('plan_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name')->nullable();
            $table->string('type')->default('recurring');
            $table->string('pricing_model')->default('standard');
            $table->bigInteger('unit_amount')->nullable();
            $table->char('currency', 3);
            $table->string('billing_interval')->nullable();
            $table->smallInteger('billing_frequency')->default(1);
            $table->integer('package_size')->nullable();
            $table->string('tax_mode')->default('account');
            $table->string('status')->default('active');
            // Set when this row supersedes an earlier price (a merchant
            // "edit"). Walk the chain for a price's full lineage.
            $table->foreignId('replaces_price_id')->nullable()->constrained('prices')->nullOnDelete();
            // Human-facing label only ("v2 of this price") — incremented on
            // the superseding row, never used to UPDATE an existing one.
            $table->integer('version')->default(1);
            // Simple trials live here; complex/ramp cases use price_phases.
            $table->integer('trial_length')->nullable();
            $table->string('trial_unit')->nullable();
            $table->boolean('trial_requires_payment_info')->default(false);
            $table->boolean('trial_once_per_customer')->default(true);
            // False for a price that exists only as a price_phases charge
            // target — pickers and the Products list filter on this.
            $table->boolean('purchasable')->default(true);
            $table->json('custom_data')->nullable();
            $table->timestamps();

            $table->index(['team_id', 'product_id', 'status']);
            $table->index(['team_id', 'plan_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('prices');
    }
};
