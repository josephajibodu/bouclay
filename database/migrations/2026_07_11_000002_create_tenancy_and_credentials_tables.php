<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
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

        // BYOK link between a team and one payment gateway. A team holds one
        // row per processor (`unique(team_id, processor)`) with one marked
        // default for NEW checkouts only — charges on a stored card always
        // route through the gateway that minted the token (schema.md §1).
        //
        // Gateway config is a code manifest, not columns: each driver declares
        // its own `configSchema()` and the credentials are stored as one
        // encrypted JSON blob per mode (e.g. Nomba: {account_id,
        // subaccount_id?, client_id, client_secret, webhook_secret}).
        Schema::create('team_processor_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('processor')->default('nomba');
            $table->boolean('is_default')->default(false);
            $table->text('test_credentials')->nullable();
            $table->text('live_credentials')->nullable();
            $table->string('inbound_webhook_token')->unique();
            $table->timestamp('webhook_verified_at')->nullable();
            $table->timestamp('test_connected_at')->nullable();
            $table->timestamp('live_connected_at')->nullable();
            $table->timestamps();

            $table->unique(['team_id', 'processor']);
        });

        // Exactly one default gateway per team (partial unique index).
        // MySQL/MariaDB have no partial indexes — there the rule is enforced
        // at the application layer only.
        if (in_array(DB::connection()->getDriverName(), ['pgsql', 'sqlite'], true)) {
            DB::statement(
                'CREATE UNIQUE INDEX team_processor_connections_default_unique
                 ON team_processor_connections (team_id) WHERE is_default'
            );
        }

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
