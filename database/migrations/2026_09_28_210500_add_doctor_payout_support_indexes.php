<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Índices de apoio ao repasse médico.
 *
 * - medical_records(schedule_id): o repasse compara o médico do prontuário
 *   com o do agendamento e pareia procedimentos com o atendimento — sem
 *   índice, cada atendimento do período varreria medical_records.
 * - medical_record_procedures(entity_id, executed_at) só dos executados: a
 *   fonte de procedimentos filtra por clínica + data de execução.
 * - financial_cash_entries: no máximo UM lançamento ativo por fechamento de
 *   repasse (reference_type = doctor_payout) — pagamento em dobro vira
 *   violação de índice mesmo se a trava da aplicação falhar.
 */
return new class() extends Migration {
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE INDEX IF NOT EXISTS medical_records_schedule_active_idx
            ON medical_records (schedule_id)
            WHERE deleted_at IS NULL
        SQL);

        DB::statement(<<<'SQL'
            CREATE INDEX IF NOT EXISTS medical_record_procedures_done_executed_idx
            ON medical_record_procedures (entity_id, executed_at)
            WHERE status = 'done' AND deleted_at IS NULL
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX IF NOT EXISTS financial_cash_entries_doctor_payout_unique
            ON financial_cash_entries (reference_id)
            WHERE reference_type = 'doctor_payout' AND deleted_at IS NULL
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS financial_cash_entries_doctor_payout_unique');
        DB::statement('DROP INDEX IF EXISTS medical_record_procedures_done_executed_idx');
        DB::statement('DROP INDEX IF EXISTS medical_records_schedule_active_idx');
    }
};
