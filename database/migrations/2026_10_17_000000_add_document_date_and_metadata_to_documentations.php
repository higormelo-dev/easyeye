<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Documentos do prontuário — Fase 2 / Bloco B.
 *
 *  - `document_date` (B3): data IMPRESSA no documento quando o médico escolhe
 *    uma data alternativa (dentro da faixa de config/clinical_documents.php).
 *    Nula = a data da emissão. Quando o documento foi emitido de fato continua
 *    em `created_at` (e na trilha de auditoria) — nunca é sobrescrito.
 *  - `metadata` (B1/B2/B3): dados estruturados do documento — campos do
 *    atestado (acompanhante, horários, dilatação, aptidão visual), CID com a
 *    autorização do paciente (Res. CFM 1.658/2002, art. 5º), itens do pedido
 *    de exames (kit, código TUSS, caráter, indicação, CIDs) e o modelo pessoal
 *    usado. Vai na exportação LGPD.
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::table('medical_record_documentations', function (Blueprint $table) {
            $table->date('document_date')->nullable();
            $table->json('metadata')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('medical_record_documentations', function (Blueprint $table) {
            $table->dropColumn(['document_date', 'metadata']);
        });
    }
};
