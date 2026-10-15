<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

/**
 * Orientações pós-procedimento — Fase 6, M3 / S14.
 *
 *  - `care_guidance_templates`: o modelo da clínica (opcionalmente de UM procedimento do catálogo): título, texto, o
 *    local (rótulo livre — "onde ir": centro cirúrgico, sala de curativo) e o esquema de colírios.
 *  - `care_guidance_attachments`: PDFs que acompanham o modelo (preparo, termo), até 5 por modelo e 5 MB cada.
 *  - `care_guidance_issues`: o que foi ENTREGUE ao paciente — um retrato CONGELADO (texto, colírios, local, anexos e o
 *    hash do conteúdo). Mudar ou apagar o modelo depois não muda o que o paciente recebeu; a emissão é imutável.
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::create('care_guidance_templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities')->cascadeOnDelete();
            // Nulo = vale para qualquer procedimento.
            $table->foreignUuid('procedure_id')->nullable()->constrained('procedures')->nullOnDelete();
            $table->string('title', 160);
            $table->string('location_label', 160)->nullable();
            $table->text('body');
            // [{ name, how, times_per_day, days }]
            $table->json('eye_drops')->nullable();
            $table->boolean('active')->default(true);
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['entity_id', 'active']);
            $table->index(['entity_id', 'procedure_id']);
        });

        Schema::create('care_guidance_attachments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities')->cascadeOnDelete();
            $table->foreignUuid('template_id')->constrained('care_guidance_templates')->cascadeOnDelete();
            $table->string('original_name', 200);
            $table->string('path', 300);
            $table->unsignedInteger('size_bytes');
            $table->string('sha256', 64);
            $table->foreignUuid('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['entity_id', 'template_id']);
        });

        Schema::create('care_guidance_issues', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities')->cascadeOnDelete();
            $table->foreignUuid('patient_id')->constrained('patients')->cascadeOnDelete();
            $table->foreignUuid('medical_record_procedure_id')->nullable()->constrained('medical_record_procedures')->nullOnDelete();
            $table->foreignUuid('template_id')->nullable()->constrained('care_guidance_templates')->nullOnDelete();
            $table->foreignUuid('doctor_id')->nullable()->constrained('doctors')->nullOnDelete();
            $table->string('title', 160);
            $table->string('location_label', 160)->nullable();
            // right | left | both | nulo — o olho do procedimento na hora.
            $table->string('eye', 5)->nullable();
            $table->text('body');
            $table->json('eye_drops')->nullable();
            // [{ name, path, size_bytes, sha256 }] — arquivos COPIADOS para a emissão.
            $table->json('attachments')->nullable();
            $table->string('content_hash', 70);
            $table->timestamp('issued_at');
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['entity_id', 'patient_id', 'issued_at']);
            $table->index(['medical_record_procedure_id']);
        });

        DB::statement("ALTER TABLE care_guidance_issues ADD CONSTRAINT care_guidance_issues_eye_check CHECK (eye IS NULL OR eye IN ('right', 'left', 'both'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('care_guidance_issues');
        Schema::dropIfExists('care_guidance_attachments');
        Schema::dropIfExists('care_guidance_templates');
    }
};
