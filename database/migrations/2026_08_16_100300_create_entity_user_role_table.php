<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pivot entre EntityUser e Role — um usuário acumula 1+ perfis customizados
 * dentro de uma entity, ADITIVO à rule base (entity_users.rule).
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::create('entity_user_role', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('entity_user_id')
                ->constrained('entity_users')
                ->cascadeOnDelete();

            $table->foreignUuid('role_id')
                ->constrained('roles')
                ->cascadeOnDelete();

            $table->foreignUuid('created_by')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['entity_user_id', 'role_id'], 'entity_user_role_entity_user_id_role_id_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entity_user_role');
    }
};
