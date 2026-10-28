<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rascunho de laudo de imagem — Fase 5 (fluxo secretária-rascunha /
 * médico-emite, "Laudos de exames").
 *
 * Secretária/admin preparam o conteúdo; só o médico EMITE de fato (sempre
 * via MedicalRecordDocumentationService::store(), doctor-only, inalterado —
 * este rascunho nunca vira documento assinado sozinho). `exam_ids` é
 * revalidado contra o catálogo do paciente a cada leitura/escrita
 * (ownedExamIds() do controller), nunca confiado cru. `image_report_payload`
 * é o bloco de imagens a embutir no laudo (colunas, pareamento OD/OE,
 * ordem/legenda) — mesmo shape gravado em
 * medical_record_documentations.metadata.image_report na emissão.
 *
 * Sem granularidade de "dono" de propósito (camada leve pedida): qualquer
 * admin/doctor/secretary da clínica edita/descarta o rascunho de um colega.
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::create('eye_image_report_drafts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities')->cascadeOnDelete();
            $table->foreignUuid('patient_id')->constrained('patients')->cascadeOnDelete();
            $table->json('exam_ids')->nullable();
            $table->foreignUuid('report_setting_content_id')->nullable()->constrained('report_setting_contents')->nullOnDelete();
            $table->string('title')->nullable();
            $table->longText('content')->nullable();
            $table->text('clinical_indication')->nullable();
            $table->text('observations')->nullable();
            $table->date('document_date')->nullable();
            $table->string('requesting_doctor_name')->nullable();
            $table->string('requesting_doctor_email')->nullable();
            $table->json('image_report_payload')->nullable();
            $table->string('status', 20)->default('draft');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->foreignUuid('issued_documentation_id')->nullable()->constrained('medical_record_documentations')->nullOnDelete();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['entity_id', 'status'], 'eye_image_report_drafts_entity_status_idx');
            $table->index(['entity_id', 'patient_id'], 'eye_image_report_drafts_entity_patient_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('eye_image_report_drafts');
    }
};
