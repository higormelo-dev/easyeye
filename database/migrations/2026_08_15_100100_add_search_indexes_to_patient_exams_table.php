<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    /**
     * patient_exams só tinha PK até aqui — Postgres não indexa FK automaticamente
     * (diferente do MySQL). Sem esses índices, os filtros do Gerenciador de Imagens
     * (período, tipo de exame, equipamento, médico) viram seq scan conforme a tabela
     * cresce, o que anula o objetivo da melhoria de busca.
     */
    public function up(): void
    {
        Schema::table('patient_exams', function (Blueprint $table) {
            $table->index(['patient_id', 'created_at'], 'patient_exams_patient_created_idx');
            $table->index('created_at', 'patient_exams_created_at_idx');
            $table->index('exam_id', 'patient_exams_exam_id_idx');
            $table->index('entity_integrator_equipment_id', 'patient_exams_equipment_idx');
            $table->index('doctor_id', 'patient_exams_doctor_idx');
        });
    }

    public function down(): void
    {
        Schema::table('patient_exams', function (Blueprint $table) {
            $table->dropIndex('patient_exams_patient_created_idx');
            $table->dropIndex('patient_exams_created_at_idx');
            $table->dropIndex('patient_exams_exam_id_idx');
            $table->dropIndex('patient_exams_equipment_idx');
            $table->dropIndex('patient_exams_doctor_idx');
        });
    }
};
