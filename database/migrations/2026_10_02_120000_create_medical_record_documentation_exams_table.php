<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quais imagens de exame (PatientExam) fizeram parte de cada laudo
 * (MedicalRecordDocumentation) — manual ou aprovado da IA. Antes o laudo
 * manual recebia exam_ids só pra checar posse e não gravava o vínculo, e o
 * PDF não dizia quais exames tinham sido laudados; com o laudo de vários
 * exames num documento só, isso precisa ficar registrado. Mesmo formato de
 * ai_run_patient_exam (entity_id pra isolamento por clínica).
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::create('medical_record_documentation_exams', function (Blueprint $table) {
            $table->foreignUuid('medical_record_documentation_id')
                ->constrained('medical_record_documentations')
                ->cascadeOnDelete();
            $table->foreignUuid('patient_exam_id')->constrained('patient_exams')->cascadeOnDelete();
            $table->foreignUuid('entity_id')->constrained('entities')->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->primary(['medical_record_documentation_id', 'patient_exam_id'], 'mrd_exams_pk');
            $table->index(['entity_id', 'patient_exam_id'], 'mrd_exams_entity_exam_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('medical_record_documentation_exams');
    }
};
