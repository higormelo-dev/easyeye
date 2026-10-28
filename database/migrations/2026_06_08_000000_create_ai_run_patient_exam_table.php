<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vínculo entre uma execução de IA (AiRun) e os exames de imagem ocular
 * (PatientExam) que ela analisou. Permite mostrar o laudo da IA no próprio
 * exame no módulo Eye Image e rastrear quais imagens entraram em cada análise.
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::create('ai_run_patient_exam', function (Blueprint $table) {
            $table->foreignUuid('ai_run_id')->constrained('ai_runs')->cascadeOnDelete();
            $table->foreignUuid('patient_exam_id')->constrained('patient_exams')->cascadeOnDelete();
            $table->foreignUuid('entity_id')->constrained('entities')->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->primary(['ai_run_id', 'patient_exam_id']);
            $table->index(['entity_id', 'patient_exam_id'], 'ai_run_patient_exam_entity_exam_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_run_patient_exam');
    }
};
