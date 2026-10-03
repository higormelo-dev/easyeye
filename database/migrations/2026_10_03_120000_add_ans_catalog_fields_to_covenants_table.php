<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

/**
 * Catálogo global de convênios (manager → Convênios) sincronizado com o
 * Cadastro de Operadoras da ANS: dados oficiais que a importação mantém
 * atualizados, sem mexer no nome de exibição (importações de pacientes e
 * agenda casam convênio pelo nome).
 *
 * Só dados da empresa — nada de representante legal, e-mail ou telefone de
 * pessoa (LGPD: minimização).
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::table('covenants', function (Blueprint $table) {
            $table->string('trade_name')->nullable()->after('company_name');
            $table->string('ans_modality', 60)->nullable()->after('ans_registry');
            $table->string('city', 120)->nullable()->after('ans_modality');
            $table->char('uf', 2)->nullable()->after('city');
            $table->date('ans_registered_at')->nullable()->after('uf');
            $table->string('ans_status', 20)->nullable()->after('ans_registered_at');
            $table->date('ans_cancelled_at')->nullable()->after('ans_status');
            $table->string('ans_cancellation_reason')->nullable()->after('ans_cancelled_at');
            $table->string('source', 20)->default('manual')->after('ans_cancellation_reason');
            $table->timestamp('source_synced_at')->nullable()->after('source');

            // Casamento da importação: operadora global pelo registro ANS.
            $table->index(['entity_id', 'ans_registry'], 'covenants_entity_ans_registry_idx');
        });

        // Operadoras já semeadas (CovenantsSeeder) passam a ser da ANS; o
        // PARTICULAR e os convênios das clínicas continuam manuais.
        DB::table('covenants')
            ->whereNull('entity_id')
            ->whereNotNull('ans_registry')
            ->where('ans_registry', '<>', '')
            ->update(['source' => 'ans']);
    }

    public function down(): void
    {
        Schema::table('covenants', function (Blueprint $table) {
            $table->dropIndex('covenants_entity_ans_registry_idx');
            $table->dropColumn([
                'trade_name', 'ans_modality', 'city', 'uf', 'ans_registered_at', 'ans_status',
                'ans_cancelled_at', 'ans_cancellation_reason', 'source', 'source_synced_at',
            ]);
        });
    }
};
