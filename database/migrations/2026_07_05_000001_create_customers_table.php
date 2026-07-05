<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Created without `default_payment_method_id` — that FK points at
     * `payment_methods`, which doesn't exist yet. It's added back in a
     * later migration once both tables exist (schema.md migration order).
     */
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('public_id')->unique();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('external_ref')->nullable();
            $table->string('name')->nullable();
            $table->string('email');
            $table->string('phone')->nullable();
            $table->char('currency', 3)->nullable();
            $table->string('locale')->nullable();
            $table->char('country', 2)->nullable();
            $table->json('custom_data')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('team_id');
            $table->index(['team_id', 'email']);
            // The tenant's own customer id is unique within a team when set.
            $table->unique(['team_id', 'external_ref']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
