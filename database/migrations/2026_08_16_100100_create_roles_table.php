<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Perfis customizados (Role) por clínica — camada ADITIVA de RBAC granular
 * por cima da ClientRule fixa de entity_users.rule.
 *
 * Cada clínica (entity) cria e mantém seus próprios perfis, compostos por um
 * conjunto de Permission (ver role_permission). Um usuário pode acumular
 * 1+ perfis customizados via entity_user_role, além da sua rule base.
 *
 * NUNCA usar Role/Permission para conceder ações clínicas/regulatórias
 * travadas (assinar laudo, prescrever, etc.) — essas continuam hardcoded via
 * ClientRule::Doctor direto nos Gates (ex: EntityGate::IssueReport, CFM Res.
 * 2.227/2018), fora do alcance de qualquer Role customizada.
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('created_by')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->foreignUuid('deleted_by')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->foreignUuid('entity_id')
                ->constrained('entities')
                ->cascadeOnDelete();

            $table->string('name');
            $table->text('description')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['entity_id', 'name'], 'roles_entity_id_name_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};
