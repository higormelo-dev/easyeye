<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Índices de apoio do recall (Fase 6, ADR 0002): o avaliador lê, POR PACIENTE, os atendimentos e o
 * próximo agendamento em aberto. Sem eles a leitura varre a clínica inteira.
 *
 *  - medical_records(patient_id, created_at DESC) WHERE deleted_at IS NULL — não existia índice por
 *    paciente (só por clínica + data);
 *  - schedules(entity_id, patient_id, date_time) WHERE deleted_at IS NULL AND situation NOT IN (7,8,9) —
 *    o próximo agendamento ABERTO do paciente (o índice de atendidos, situation = 7, já existe).
 *
 * CREATE INDEX CONCURRENTLY não trava a escrita da agenda e do prontuário em produção, mas não roda
 * dentro de transação: `$withinTransaction = false`. Em bancos que não são PostgreSQL (suíte local),
 * cria o índice comum.
 */
return new class() extends Migration {
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS medical_records_patient_created_idx ON medical_records (patient_id, created_at DESC) WHERE deleted_at IS NULL');
            DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS schedules_entity_patient_open_idx ON schedules (entity_id, patient_id, date_time) WHERE deleted_at IS NULL AND situation NOT IN (7, 8, 9)');

            return;
        }

        DB::statement('CREATE INDEX IF NOT EXISTS medical_records_patient_created_idx ON medical_records (patient_id, created_at) WHERE deleted_at IS NULL');
        DB::statement('CREATE INDEX IF NOT EXISTS schedules_entity_patient_open_idx ON schedules (entity_id, patient_id, date_time) WHERE deleted_at IS NULL AND situation NOT IN (7, 8, 9)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS medical_records_patient_created_idx');
        DB::statement('DROP INDEX IF EXISTS schedules_entity_patient_open_idx');
    }
};
