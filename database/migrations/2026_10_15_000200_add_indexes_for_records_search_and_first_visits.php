<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fase 1 / B3.
 *
 *  - Prontuário no menu (busca geral): lista da clínica, mais novos primeiro.
 *  - Relatório de primeira consulta: a 1ª consulta ATENDIDA de cada paciente
 *    na clínica (NOT EXISTS de uma atendida anterior) — índice parcial só das
 *    atendidas.
 */
return new class() extends Migration {
    public function up(): void
    {
        DB::statement('
            CREATE INDEX IF NOT EXISTS medical_records_entity_created_idx
                ON medical_records (entity_id, created_at)
             WHERE deleted_at IS NULL
        ');

        DB::statement('
            CREATE INDEX IF NOT EXISTS schedules_entity_patient_attended_idx
                ON schedules (entity_id, patient_id, date_time)
             WHERE deleted_at IS NULL
               AND situation = 7
        ');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS schedules_entity_patient_attended_idx');
        DB::statement('DROP INDEX IF EXISTS medical_records_entity_created_idx');
    }
};
