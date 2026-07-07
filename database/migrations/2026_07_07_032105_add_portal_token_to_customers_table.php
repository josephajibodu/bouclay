<?php

use App\Models\Customer;
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
        Schema::table('customers', function (Blueprint $table) {
            // $table->string('portal_token', 64)->nullable()->unique()->after('public_id');
        });

        foreach (Customer::withTrashed()->whereNull('portal_token')->get() as $customer) {
            $customer->update(['portal_token' => Customer::generatePortalToken()]);
        }

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('portal_token');
        });
    }
};
