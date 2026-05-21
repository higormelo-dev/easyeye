<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adiciona referência opcional para a execução de IA que originou uma documentação
 * do prontuário. Permite rastreabilidade CFM/LGPD: dado um laudo/relatório, saber se
 * foi originado por IA, com qual prompt, modelo, custo e qual médico aprovou.
 *
 * Nullable porque a maioria das documentações é criada manualmente sem IA.
 * `nullOnDelete` preserva o documento mesmo se o AiRun for purgado por GDPR.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('medical_record_documentations', function (Blueprint $table) {
            $table->foreignUuid('ai_run_id')
                ->nullable()
                ->after('template_version')
                ->constrained('ai_runs')
                ->nullOnDelete();

            $table->index(['medical_record_id', 'ai_run_id'], 'mrd_record_ai_run_idx');
        });
    }

    public function down(): void
    {
        Schema::table('medical_record_documentations', function (Blueprint $table) {
            $table->dropIndex('mrd_record_ai_run_idx');
            $table->dropConstrainedForeignId('ai_run_id');
        });
    }
};
