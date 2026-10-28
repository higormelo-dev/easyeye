<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modelos PESSOAIS do médico — Fase 2 / B3 ("Salvar como modelo").
 *
 * Por clínica e por médico (só o dono lista, edita e exclui). `kind`:
 * prescription (receita), procedure (pedido de exames) ou report (relatório /
 * documento livre). `report_setting_content_id` é o modelo da clínica que dá
 * o tipo e o layout do PDF; `body` é o texto do atendimento salvo (texto
 * simples nos modais de receita/pedido, HTML no editor de documentos) e
 * `payload` os itens estruturados (medicamentos, exames, caráter, indicações)
 * para a checagem de alergia e o pedido voltarem iguais.
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::create('doctor_document_templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities')->cascadeOnDelete();
            $table->foreignUuid('doctor_id')->constrained('doctors')->cascadeOnDelete();
            $table->string('kind', 20);
            $table->foreignUuid('report_setting_content_id')->nullable()->constrained('report_setting_contents')->nullOnDelete();
            $table->string('name', 120);
            $table->text('body');
            $table->string('body_format', 10)->default('text');
            $table->json('payload')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['entity_id', 'doctor_id', 'kind'], 'doctor_document_templates_owner_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('doctor_document_templates');
    }
};
