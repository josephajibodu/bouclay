<?php

use App\Enums\PermissionName;
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
        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('label');
            $table->text('description')->nullable();
            $table->string('group');
            $table->timestamps();
        });

        $now = now();

        DB::table('permissions')->insert(
            collect(PermissionName::cases())
                ->map(fn (PermissionName $permission) => [
                    'name' => $permission->value,
                    'label' => $permission->label(),
                    'group' => $permission->group(),
                    'created_at' => $now,
                    'updated_at' => $now,
                ])
                ->all(),
        );

        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->boolean('is_system')->default(false);
            $table->timestamps();

            $table->unique(['team_id', 'name']);
        });

        Schema::create('role_permission', function (Blueprint $table) {
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();

            $table->primary(['role_id', 'permission_id']);
        });

        Schema::table('team_members', function (Blueprint $table) {
            $table->foreignId('role_id')->after('user_id')->constrained()->restrictOnDelete();
            $table->boolean('is_owner')->after('role_id')->default(false);
        });

        Schema::table('team_invitations', function (Blueprint $table) {
            $table->foreignId('role_id')->after('email')->constrained()->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('team_invitations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('role_id');
        });

        Schema::table('team_members', function (Blueprint $table) {
            $table->dropColumn('is_owner');
            $table->dropConstrainedForeignId('role_id');
        });

        Schema::dropIfExists('role_permission');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('permissions');
    }
};
