<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Corrige as restrições de integridade referencial em medical_records.
 *
 * CFM Res. 2.227/2018 — prontuários devem ser preservados por no mínimo 20 anos.
 * O cascadeOnDelete original permitia que um forceDelete de paciente, médico ou
 * agendamento destruísse permanentemente todos os prontuários vinculados.
 *
 * Novo comportamento:
 *   - patient_id: RESTRICT — impede exclusão de paciente com prontuários existentes.
 *   - doctor_id:  SET NULL — médico pode ser removido; prontuários são mantidos sem vínculo.
 *   - schedule_id: SET NULL — agendamento pode ser removido; prontuários são mantidos.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('medical_records', function (Blueprint $table) {
            $table->dropForeign(['patient_id']);
            $table->dropForeign(['doctor_id']);
            $table->dropForeign(['schedule_id']);

            // Paciente não pode ser excluído enquanto existirem prontuários
            $table->foreign('patient_id')
                ->references('id')->on('patients')
                ->restrictOnDelete();

            // Médico pode ser removido; prontuário fica com doctor_id = null
            $table->foreign('doctor_id')
                ->references('id')->on('doctors')
                ->nullOnDelete();

            // Agendamento pode ser cancelado/removido; prontuário sobrevive
            $table->foreign('schedule_id')
                ->references('id')->on('schedules')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('medical_records', function (Blueprint $table) {
            $table->dropForeign(['patient_id']);
            $table->dropForeign(['doctor_id']);
            $table->dropForeign(['schedule_id']);

            $table->foreign('patient_id')->references('id')->on('patients')->cascadeOnDelete();
            $table->foreign('doctor_id')->references('id')->on('doctors')->cascadeOnDelete();
            $table->foreign('schedule_id')->references('id')->on('schedules')->cascadeOnDelete();
        });
    }
};
