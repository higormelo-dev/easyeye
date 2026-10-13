<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Médico usado na ÚLTIMA pré-visualização do rascunho (Fase 5 — revisão
 * Codex). Templates personalizados podem citar {{MEDICO_NOME}}/{{MEDICO_CRM}}
 * no CORPO do texto (os seedados não usam — só a assinatura do PDF, que lê
 * o médico certo na hora de gerar); como o conteúdo é só texto depois de
 * resolvido (sem rastro de qual trecho veio de qual variável), não dá pra
 * "corrigir" esse texto automaticamente na emissão sem risco de trocar o
 * nome errado num parágrafo clínico qualquer. Serve só pra COMPARAR contra
 * quem está de fato emitindo e avisar o médico — nunca pra editar o texto
 * sozinho.
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::table('eye_image_report_drafts', function (Blueprint $table) {
            $table->foreignUuid('resolved_doctor_id')->nullable()->after('report_setting_content_id')->constrained('doctors')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('eye_image_report_drafts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('resolved_doctor_id');
        });
    }
};
