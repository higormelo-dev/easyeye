<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Segurança clínica — Fase 2 / Bloco A (A2, A3 e A4).
 *
 *  - `medical_records.medications_snapshot` / `allergies_snapshot`: retrato
 *    das medicações ativas e das alergias do paciente gravado pelo SERVIDOR a
 *    cada salvar — o prontuário reflete o momento da consulta (a lista do
 *    paciente continua mudando depois). O texto livre `medications_in_use`
 *    segue como observação; registros antigos ficam como estão.
 *  - `medical_records.contact_lens_use`: uso de lente de contato na anamnese
 *    (por consulta; a tela pré-preenche com a da última consulta).
 *  - `medical_record_documentations.safety_override`: receita emitida apesar
 *    de bater com alergia ativa — conflitos, motivo, quem e quando (também
 *    vai para a trilha de auditoria).
 *
 * Colunas nulas ficam fora do hash de assinatura (signatureOptionalAttributes):
 * prontuários assinados antes delas continuam conferindo.
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::table('medical_records', function (Blueprint $table) {
            $table->json('medications_snapshot')->nullable();
            $table->json('allergies_snapshot')->nullable();
            $table->json('contact_lens_use')->nullable();
        });

        Schema::table('medical_record_documentations', function (Blueprint $table) {
            $table->json('safety_override')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('medical_record_documentations', function (Blueprint $table) {
            $table->dropColumn('safety_override');
        });

        Schema::table('medical_records', function (Blueprint $table) {
            $table->dropColumn(['medications_snapshot', 'allergies_snapshot', 'contact_lens_use']);
        });
    }
};
