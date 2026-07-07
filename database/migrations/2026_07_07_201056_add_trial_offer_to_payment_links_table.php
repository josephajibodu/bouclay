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
        Schema::table('payment_links', function (Blueprint $table) {
            $table->foreignId('price_id')->nullable()->change();
            $table->foreignId('trial_offer_id')
                ->nullable()
                ->after('price_id')
                ->constrained('trial_offers')
                ->cascadeOnDelete();

            $table->unique(['team_id', 'trial_offer_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payment_links', function (Blueprint $table) {
            $table->dropUnique(['team_id', 'trial_offer_id']);
            $table->dropConstrainedForeignId('trial_offer_id');
            $table->foreignId('price_id')->nullable(false)->change();
        });
    }
};
