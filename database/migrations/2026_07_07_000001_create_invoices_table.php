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
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->string('public_id')->unique();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            // Null for a one-off transaction (schema.md).
            $table->foreignId('subscription_id')->nullable()->constrained()->nullOnDelete();
            $table->string('number')->nullable();
            $table->string('status');
            $table->string('billing_reason');
            $table->string('collection_mode');
            $table->char('currency', 3);
            $table->bigInteger('subtotal');
            $table->bigInteger('discount_total')->default(0);
            $table->bigInteger('tax_total')->default(0);
            $table->bigInteger('total');
            $table->bigInteger('amount_paid')->default(0);
            $table->bigInteger('amount_due');
            $table->json('billing_address')->nullable();
            $table->json('customer_snapshot')->nullable();
            $table->timestamp('period_start')->nullable();
            $table->timestamp('period_end')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->timestamp('finalized_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->json('custom_data')->nullable();
            $table->timestamps();

            $table->index('team_id');
            $table->index('customer_id');
            $table->index('subscription_id');
            $table->index(['team_id', 'status']);
            $table->unique(['team_id', 'number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
