<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

/**
 * Módulo cirúrgico (Fase 4) / M3b — solicitação de internação e anexo de OPME TISS.
 *
 *  - `tiss_guides.surgical_case_id`: a guia nasce do caso cirúrgico (null para
 *    consulta/SP-SADT do faturamento). `ON DELETE SET NULL`: o caso é
 *    soft-deleted, nunca apagado de verdade — o vínculo só some se a linha sumir.
 *  - `tiss_guides.xml_document_id`: o XML ATUAL da guia (o lote já tem o dele).
 *    As duas mensagens avulsas não passam por lote.
 *  - Índice único parcial: no máximo UMA guia VIGENTE de cada tipo por caso
 *    (cancelada e negada saem do índice — o recurso/reenvio nasce guia nova, com
 *    número, XML e chave de idempotência novos). É o que serializa o "gerar" de
 *    duas abas: a 2ª encontra a guia da 1ª.
 *  - `tiss_transmissions.guide_id`: transmissão de UMA guia (sem lote). Lote e
 *    guia são mutuamente exclusivos na linha.
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::table('tiss_guides', function (Blueprint $table) {
            $table->foreignUuid('surgical_case_id')->nullable()->after('medical_record_id')
                ->constrained('surgical_cases')->nullOnDelete();
            $table->foreignUuid('xml_document_id')->nullable()->after('surgical_case_id')
                ->constrained('tiss_xml_documents')->nullOnDelete();

            $table->index(['entity_id', 'surgical_case_id'], 'tiss_guides_entity_case_idx');
        });

        DB::statement("
            CREATE UNIQUE INDEX tiss_guides_surgical_case_type_unique
                ON tiss_guides (surgical_case_id, guide_type)
             WHERE surgical_case_id IS NOT NULL
               AND deleted_at IS NULL
               AND status NOT IN ('cancelled', 'denied')
        ");

        Schema::table('tiss_transmissions', function (Blueprint $table) {
            $table->foreignUuid('guide_id')->nullable()->after('batch_id')
                ->constrained('tiss_guides')->nullOnDelete();

            $table->index(['entity_id', 'guide_id'], 'tiss_transmissions_entity_guide_idx');
        });
    }

    public function down(): void
    {
        Schema::table('tiss_transmissions', function (Blueprint $table) {
            $table->dropIndex('tiss_transmissions_entity_guide_idx');
            $table->dropConstrainedForeignId('guide_id');
        });

        DB::statement('DROP INDEX IF EXISTS tiss_guides_surgical_case_type_unique');

        Schema::table('tiss_guides', function (Blueprint $table) {
            $table->dropIndex('tiss_guides_entity_case_idx');
            $table->dropConstrainedForeignId('xml_document_id');
            $table->dropConstrainedForeignId('surgical_case_id');
        });
    }
};
