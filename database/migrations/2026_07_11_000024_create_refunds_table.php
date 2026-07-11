<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * `payments.status = refunded` marks the terminal state on the original
     * charge row; the refund event itself gets its own auditable record —
     * amount (may be partial), reason, gateway reference (schema.md §8).
     */
    public function up(): void
    {
        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->string('public_id')->unique();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            // The original charge being reversed.
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();
            // Denormalised — avoids a join through payments for
            // invoice-scoped queries.
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->bigInteger('amount');
            $table->char('currency', 3);
            $table->string('reason')->nullable();
            $table->string('status');
            $table->string('processor_reference')->nullable();
            $table->timestamps();

            $table->index('team_id');
            $table->index('payment_id');
            $table->index('invoice_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('refunds');
    }
};
