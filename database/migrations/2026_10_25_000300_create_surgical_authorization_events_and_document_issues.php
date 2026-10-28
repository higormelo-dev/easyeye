<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

/**
 * Módulo cirúrgico (Fase 4) / M1 e M3 — log de autorização e documentos.
 *
 *  - `surgical_authorization_events`: log IMUTÁVEL (só created_at). O estado
 *    corrente fica desnormalizado em `surgical_cases`; o evento guarda número,
 *    senha, validade, itens autorizados e motivo de negativa.
 *  - `surgery_document_issues`: documento cirúrgico CONGELADO (TCLE,
 *    orientações, termo do implante, alta): texto copiado + hash do conteúdo.
 *    Nunca editado; reemissão cria nova linha e marca a anterior
 *    `superseded_by_id`. A cópia digitalizada assinada pelo paciente é só
 *    metadado de arquivo (não entra no hash do conteúdo).
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::create('surgical_authorization_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities')->cascadeOnDelete();
            $table->foreignUuid('surgical_case_id')->constrained('surgical_cases')->cascadeOnDelete();
            $table->string('type', 24);
            $table->timestamp('occurred_at');
            $table->string('authorization_number', 40)->nullable();
            $table->string('authorization_password', 40)->nullable();
            $table->date('valid_until')->nullable();
            $table->jsonb('authorized_items')->nullable();
            $table->text('denial_reason')->nullable();
            $table->string('protocol_number', 40)->nullable();
            $table->string('channel', 16)->nullable();
            $table->uuid('tiss_guide_id')->nullable();
            $table->text('note')->nullable();
            $table->foreignUuid('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['surgical_case_id', 'occurred_at'], 'surgical_auth_events_case_idx');
            $table->index(['entity_id', 'type', 'occurred_at'], 'surgical_auth_events_entity_type_idx');
        });

        Schema::create('surgery_document_issues', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities')->cascadeOnDelete();
            $table->foreignUuid('surgical_case_id')->constrained('surgical_cases')->cascadeOnDelete();
            $table->foreignUuid('patient_id')->constrained('patients')->cascadeOnDelete();
            $table->string('type', 24);
            $table->string('title', 200);
            $table->longText('content');
            $table->jsonb('payload')->nullable();
            $table->char('content_hash', 64);
            $table->uuid('template_id')->nullable();
            $table->unsignedSmallInteger('template_version')->nullable();
            $table->foreignUuid('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('issued_by_doctor_id')->nullable()->constrained('doctors')->nullOnDelete();
            $table->timestamp('issued_at');
            $table->uuid('superseded_by_id')->nullable();
            $table->string('signed_copy_path', 255)->nullable();
            $table->string('signed_copy_name', 255)->nullable();
            $table->string('signed_copy_mime', 100)->nullable();
            $table->unsignedInteger('signed_copy_size')->nullable();
            $table->char('signed_copy_hash', 64)->nullable();
            $table->timestamp('signed_copy_uploaded_at')->nullable();
            $table->foreignUuid('signed_copy_uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['surgical_case_id', 'type', 'issued_at'], 'surgery_document_issues_case_type_idx');
            $table->index(['entity_id', 'patient_id'], 'surgery_document_issues_patient_idx');
        });

        Schema::table('surgery_document_issues', function (Blueprint $table) {
            $table->foreign('superseded_by_id')->references('id')->on('surgery_document_issues')->nullOnDelete();
        });

        // Uma só emissão VIGENTE por caso + tipo (as substituídas ficam no histórico).
        DB::statement('
            CREATE INDEX surgery_document_issues_current_idx
                ON surgery_document_issues (surgical_case_id, type)
             WHERE superseded_by_id IS NULL
        ');
    }

    public function down(): void
    {
        Schema::dropIfExists('surgery_document_issues');
        Schema::dropIfExists('surgical_authorization_events');
    }
};
