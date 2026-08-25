<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Sincroniza o catálogo global `permissions` a partir dos cases de
 * App\Enums\Permission — mesmo trabalho do PermissionsSeeder, mas em
 * migration, porque seeder não roda em deploy de ambiente já provisionado.
 *
 * Sem isso, ambientes que receberam a tabela via migrate (teste/produção)
 * ficam com o catálogo vazio: a tela de Perfis de acesso mostra
 * "Nenhuma permissão disponível para atribuir" (o modal filtra permissions
 * sem PermissionRecord correspondente — ver RoleFormModal.vue).
 *
 * Idempotente: insere apenas as keys ausentes; nunca altera registros
 * existentes nem vínculos em role_permission. Cases novos adicionados ao
 * enum no futuro continuam entrando pelo PermissionsSeeder (fresh installs)
 * ou por uma nova migration de sync como esta.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Guard: um migrate rodado fora do contexto do app (enum removido ou
        // renomeado no futuro) não pode quebrar a cadeia de migrations.
        if (! class_exists(\App\Enums\Permission::class)) {
            return;
        }

        foreach (\App\Enums\Permission::cases() as $case) {
            $exists = DB::table('permissions')->where('key', $case->value)->exists();

            if ($exists) {
                continue;
            }

            DB::table('permissions')->insert([
                'id'         => (string) Str::uuid(),
                'key'        => $case->value,
                'label'      => $case->label(),
                'group'      => $case->group(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Intencionalmente vazio: remover o catálogo poderia orfanar vínculos
        // em role_permission criados depois do sync.
    }
};
