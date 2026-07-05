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
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->string('public_id')->unique();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            // Named slot — lets one customer hold multiple distinct subscriptions.
            $table->string('type')->default('default');
            $table->string('status');
            $table->char('currency', 3);
            $table->string('collection_mode');
            $table->foreignId('payment_method_id')->nullable()->constrained('payment_methods')->nullOnDelete();
            // discount_id references `discounts`, which is deferred (schema.md
            // build order). Column present for fidelity; FK added with discounts.
            $table->unsignedBigInteger('discount_id')->nullable();
            $table->string('billing_anchor')->nullable();
            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->string('trial_end_behavior')->nullable();
            $table->string('billing_cycle_anchor_on_trial_end')->nullable();
            $table->timestamp('paused_at')->nullable();
            $table->timestamp('pause_resumes_at')->nullable();
            $table->timestamp('canceled_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->json('custom_data')->nullable();
            $table->timestamps();

            $table->index('team_id');
            $table->index('customer_id');
            // Dashboard filters scope by team + status.
            $table->index(['team_id', 'status']);
            // Billing scheduler hot path — scans due subs by this (schema.md).
            $table->index(['status', 'current_period_end']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
