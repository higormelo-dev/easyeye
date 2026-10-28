<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogo global de permissões granulares (App\Enums\Permission é a fonte
 * única de verdade dos cases; esta tabela apenas espelha os cases para uso
 * em queries/relacionamentos). Não é editável por admin — mantida somente
 * por PermissionsSeeder + o enum. Por isso não tem soft delete nem colunas
 * de auditoria (created_by/updated_by/deleted_by): não é registro de negócio
 * de uma clínica, é catálogo fixo do sistema, igual report_categories.
 *
 * IMPORTANTE (compliance): permissões aqui cadastradas são SEMPRE
 * administrativas/operacionais não-clínicas. Nenhuma ação clínica/regulatória
 * travada (assinar laudo, prescrever, etc.) pode ser modelada como Permission
 * — essas continuam hardcoded via ClientRule::Doctor direto nos Gates.
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::create('permissions', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->string('key')->unique()
                ->comment('Chave canônica, ex: settings.manage. Espelha App\Enums\Permission.');
            $table->string('label');
            $table->string('group')
                ->comment('Agrupamento para UI, ex: Configurações, Financeiro, Pacientes, IA.');
            $table->text('description')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('permissions');
    }
};
