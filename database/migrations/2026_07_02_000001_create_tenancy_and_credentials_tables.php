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
        Schema::create('team_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('invoice_prefix')->default('INV');
            $table->unsignedBigInteger('next_invoice_number')->default(1);
            $table->string('invoice_template')->nullable();
            $table->text('invoice_footer')->nullable();
            $table->string('billing_timezone')->default('UTC');
            $table->string('tax_behavior')->default('exclusive');
            $table->json('dunning_config')->nullable();
            $table->timestamps();
        });

        // Nomba credentials are three values per environment (accountId,
        // clientId, clientSecret) exchanged for a short-lived OAuth2 access
        // token — not a single static secret key. See app/Services/Nomba.
        Schema::create('team_processor_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('processor')->default('nomba');

            // account_id authenticates (always the parent business account).
            // subaccount_id, when set, is the account individual requests
            // are scoped to instead — see TeamProcessorConnection::requestAccountFor().
            $table->text('nomba_test_account_id')->nullable();
            $table->text('nomba_test_subaccount_id')->nullable();
            $table->text('nomba_test_client_id')->nullable();
            $table->text('nomba_test_client_secret')->nullable();
            $table->text('nomba_live_account_id')->nullable();
            $table->text('nomba_live_subaccount_id')->nullable();
            $table->text('nomba_live_client_id')->nullable();
            $table->text('nomba_live_client_secret')->nullable();

            $table->string('inbound_webhook_token')->unique();
            $table->text('nomba_test_webhook_secret')->nullable();
            $table->text('nomba_live_webhook_secret')->nullable();

            $table->timestamp('test_connected_at')->nullable();
            $table->timestamp('live_connected_at')->nullable();
            $table->timestamps();
        });

        Schema::create('api_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->string('mode');
            $table->string('kind');
            $table->string('hashed_secret')->unique();
            $table->string('last_four', 4)->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['team_id', 'mode']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('api_keys');
        Schema::dropIfExists('team_processor_connections');
        Schema::dropIfExists('team_settings');
    }
};
