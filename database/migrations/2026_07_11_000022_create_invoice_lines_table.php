<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Name snapshots (schema.md §8): prices are immutable so amounts are safe
     * via price_id, but catalog NAMES are edited freely — at finalise the
     * product/plan/price names are frozen onto the line so a rename can't
     * rewrite history. Render from the *_snapshot columns, never live joins.
     *
     * Discount invariant: `discount_amount` on billable lines is the
     * authoritative representation of product discounts;
     * `invoices.discount_total = SUM(discount_amount)`. `kind=discount`
     * lines are standalone adjustments only, never a discount source.
     */
    public function up(): void
    {
        Schema::create('invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_item_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('price_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind');
            $table->string('description');
            $table->string('product_name_snapshot')->nullable();
            $table->string('plan_name_snapshot')->nullable();
            $table->string('price_name_snapshot')->nullable();
            $table->integer('quantity')->default(1);
            $table->bigInteger('unit_amount');
            $table->bigInteger('subtotal');
            $table->bigInteger('discount_amount')->default(0);
            $table->bigInteger('tax_amount')->default(0);
            $table->bigInteger('total');
            // Window this line covers (drives proration).
            $table->timestamp('period_start')->nullable();
            $table->timestamp('period_end')->nullable();
            $table->boolean('proration')->default(false);
            $table->timestamps();

            $table->index('invoice_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoice_lines');
    }
};
