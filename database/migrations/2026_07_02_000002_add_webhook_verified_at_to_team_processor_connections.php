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
        Schema::table('team_processor_connections', function (Blueprint $table) {
            $table->timestamp('webhook_verified_at')->nullable()->after('inbound_webhook_token');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('team_processor_connections', function (Blueprint $table) {
            $table->dropColumn('webhook_verified_at');
        });
    }
};
