<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Impede 2 guias de faturamento ativas pro mesmo agendamento (duplo clique/
 * duas abas faturando o mesmo atendimento). Índice único parcial em vez de
 * unique() simples porque a regra só vale enquanto a guia está ativa —
 * cancelada/negada libera o agendamento pra refaturar. Serve também de
 * índice pra lookup por schedule_id (não existia nenhum até então).
 */
return new class() extends Migration {
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX billing_claims_active_schedule_unique
            ON billing_claims (schedule_id)
            WHERE deleted_at IS NULL AND status NOT IN ('cancelled', 'denied')
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS billing_claims_active_schedule_unique');
    }
};
