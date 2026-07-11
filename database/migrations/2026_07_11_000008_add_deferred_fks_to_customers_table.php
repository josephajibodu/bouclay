<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The circular half of the customers ↔ payment_methods relationship plus
     * the self-referencing parent pointer, both deferred until their target
     * tables exist (schema.md migration order).
     *
     * `default_payment_method_id` is the canonical "default card" pointer;
     * `payment_methods.is_default` mirrors it. `parent_customer_id` is
     * reserved for future parent/child billing — unused in MVP logic, cheap
     * to reserve now vs. expensive to retrofit later (schema.md §2).
     */
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->foreignId('default_payment_method_id')
                ->nullable()
                ->after('country')
                ->constrained('payment_methods')
                ->nullOnDelete();

            $table->foreignId('parent_customer_id')
                ->nullable()
                ->after('default_payment_method_id')
                ->constrained('customers')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_customer_id');
            $table->dropConstrainedForeignId('default_payment_method_id');
        });
    }
};
