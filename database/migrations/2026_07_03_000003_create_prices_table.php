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
        Schema::create('prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('name')->nullable();
            $table->string('type')->default('recurring');
            $table->string('pricing_model')->default('standard');
            $table->bigInteger('unit_amount')->nullable();
            $table->char('currency', 3);
            $table->string('billing_interval')->nullable();
            $table->smallInteger('billing_frequency')->default(1);
            $table->integer('package_size')->nullable();
            $table->string('tax_mode')->default('account');
            $table->string('status')->default('active');
            $table->integer('version')->default(1);
            $table->json('custom_data')->nullable();
            $table->timestamps();

            $table->index(['team_id', 'product_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('prices');
    }
};
