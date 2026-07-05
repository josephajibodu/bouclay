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
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            // "txn_" — the dashboard calls this object a Transaction (Paddle's
            // word); the model/table stay `Payment`/`payments` (schema.md).
            $table->string('public_id')->unique();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_method_id')->nullable()->constrained()->nullOnDelete();
            $table->string('processor')->default('nomba');
            $table->string('processor_reference')->nullable();
            $table->bigInteger('amount');
            $table->char('currency', 3);
            $table->string('status');
            $table->string('risk_level')->nullable();
            $table->string('failure_code')->nullable();
            $table->string('failure_reason')->nullable();
            $table->integer('attempt_number')->default(1);
            $table->string('idempotency_key')->unique();
            $table->json('raw_response')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index('team_id');
            $table->index('invoice_id');
            $table->index('customer_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
