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
        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->string('public_id')->unique();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('processor')->default('nomba');
            // The gateway token (e.g. Nomba `tokenKey`) we charge against.
            // Never a PAN. Tokens are gateway-bound: a stored card always
            // charges through the processor that minted it (schema.md §1).
            $table->string('processor_token');
            $table->string('type')->default('card');
            $table->string('brand')->nullable();
            $table->string('last4', 4)->nullable();
            $table->smallInteger('exp_month')->nullable();
            $table->smallInteger('exp_year')->nullable();
            $table->string('fingerprint')->nullable();
            $table->string('issuer')->nullable();
            $table->foreignId('billing_address_id')->nullable()->constrained('addresses')->nullOnDelete();
            $table->boolean('is_default')->default(false);
            $table->string('status')->default('active');
            $table->json('custom_data')->nullable();
            $table->timestamps();

            $table->index('team_id');
            $table->index('customer_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_methods');
    }
};
