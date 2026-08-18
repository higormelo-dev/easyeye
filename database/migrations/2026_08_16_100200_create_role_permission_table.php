<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pivot simples Role <-> Permission (App\Models\Role <-> App\Models\PermissionRecord).
 * Sem timestamps — apenas a associação importa.
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::create('role_permission', function (Blueprint $table) {
            $table->foreignUuid('role_id')->constrained('roles')->cascadeOnDelete();
            $table->foreignUuid('permission_id')->constrained('permissions')->cascadeOnDelete();

            $table->unique(['role_id', 'permission_id'], 'role_permission_role_id_permission_id_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_permission');
    }
};
