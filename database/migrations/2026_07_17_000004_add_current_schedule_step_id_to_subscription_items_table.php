<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Deferred FK (same pattern as
     * 2026_07_11_000008_add_deferred_fks_to_customers_table): a schedule
     * step can only be referenced once `subscription_schedule_steps`
     * exists, which itself depends on `subscription_items` already being
     * created — so this column can't live on the original table.
     *
     * Null means the item isn't currently on a schedule — either it was
     * never on one, or its schedule finished with `end_behavior=release`
     * and collapsed back to a flat, ordinary priced item (schema.md §5).
     */
    public function up(): void
    {
        Schema::table('subscription_items', function (Blueprint $table) {
            $table->dropColumn('current_phase_sequence');

            $table->foreignId('current_schedule_step_id')
                ->nullable()
                ->after('trial_ends_at')
                ->constrained('subscription_schedule_steps')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('subscription_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('current_schedule_step_id');
            $table->smallInteger('current_phase_sequence')->nullable();
        });
    }
};
