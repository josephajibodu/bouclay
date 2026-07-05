<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The circular half of the customers ↔ payment_methods relationship,
     * deferred until payment_methods exists (schema.md migration order).
     * `default_payment_method_id` is the canonical "default card" pointer;
     * `payment_methods.is_default` mirrors it (CUSTOMERS_DESIGN §14.9).
     */
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->foreignId('default_payment_method_id')
                ->nullable()
                ->after('country')
                ->constrained('payment_methods')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('default_payment_method_id');
        });
    }
};
