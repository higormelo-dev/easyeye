<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Impede 2 lançamentos de caixa do mesmo tipo (ex.: 2 recebimentos) pra
 * mesma guia — duplo submit em "marcar como paga" duplicava receita.
 * Parcial (não deleted_at, billing_claim_id presente) porque um
 * lançamento estornado/soft-deleted libera a guia pra um novo lançamento
 * legítimo depois. Serve também de índice pra lookup por billing_claim_id.
 */
return new class() extends Migration {
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX financial_cash_entries_active_claim_type_unique
            ON financial_cash_entries (billing_claim_id, type)
            WHERE deleted_at IS NULL AND billing_claim_id IS NOT NULL
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS financial_cash_entries_active_claim_type_unique');
    }
};
