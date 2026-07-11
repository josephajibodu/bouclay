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
            // `pay_` — one charge ATTEMPT against an invoice; failed attempts
            // are stored too because Bouclay runs its own dunning.
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
            // Drives dunning classification (hard vs soft decline).
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
            $table->index(['team_id', 'status']);
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
