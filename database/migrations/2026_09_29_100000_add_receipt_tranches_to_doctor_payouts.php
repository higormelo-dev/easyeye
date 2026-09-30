<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

/**
 * Repasse pelo RECEBIMENTO (regime decidido em 2026-09-29): um ato pode ser
 * liberado em parcelas — cada fechamento libera o devido sobre o recebido
 * acumulado até o fim do período, menos o que já foi liberado (recebimento
 * complementar = parcela nova; estorno = parcela negativa).
 *
 * Compatível com o histórico: fechamentos/itens existentes ficam com
 * basis = 'production' (regime anterior, retrato intocado) e parcela 1.
 *
 * Garantia contra liberar o mesmo ato duas vezes (substitui o índice por ato):
 * índice único parcial (entity_id, source_type, source_id, tranche) WHERE
 * voided_at IS NULL + o cálculo "devido acumulado − já liberado" feito sob o
 * advisory lock do médico. Dois fechamentos concorrentes do mesmo ato
 * disputariam o mesmo número de parcela → violação → 422.
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::table('doctor_payouts', function (Blueprint $table) {
            $table->string('basis', 20)->default('production')->after('status'); // App\Enums\DoctorPayout\DoctorPayoutBasis
        });

        Schema::table('doctor_payout_items', function (Blueprint $table) {
            $table->string('basis', 20)->default('production')->after('base_source');
            $table->unsignedSmallInteger('tranche')->default(1)->after('basis');
            // Recebido acumulado do ato até receipts_until (base de cálculo desta parcela).
            $table->decimal('received_amount', 12, 2)->nullable()->after('tranche');
            // Líquido esperado do atendimento (faturado − glosa) — proporção da regra de valor fixo.
            $table->decimal('expected_amount', 12, 2)->nullable()->after('received_amount');
            // Repasse já liberado em parcelas anteriores do mesmo ato.
            $table->decimal('released_before_amount', 12, 2)->default(0)->after('expected_amount');
            $table->date('receipts_until')->nullable()->after('released_before_amount');
            // Recebimentos considerados: [{id, date, amount, kind}] (auditoria da parcela).
            $table->json('receipts')->nullable()->after('receipts_until');
        });

        DB::statement('DROP INDEX IF EXISTS doctor_payout_items_active_source_unique');

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX doctor_payout_items_active_tranche_unique
            ON doctor_payout_items (entity_id, source_type, source_id, tranche)
            WHERE voided_at IS NULL
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE doctor_payout_items
                ADD CONSTRAINT doctor_payout_items_basis_check
                    CHECK (basis IN ('production', 'receipt')),
                ADD CONSTRAINT doctor_payout_items_tranche_check
                    CHECK (tranche >= 1)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE doctor_payouts
                ADD CONSTRAINT doctor_payouts_basis_check
                    CHECK (basis IN ('production', 'receipt'))
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE doctor_payouts DROP CONSTRAINT IF EXISTS doctor_payouts_basis_check');
        DB::statement('ALTER TABLE doctor_payout_items DROP CONSTRAINT IF EXISTS doctor_payout_items_tranche_check');
        DB::statement('ALTER TABLE doctor_payout_items DROP CONSTRAINT IF EXISTS doctor_payout_items_basis_check');
        DB::statement('DROP INDEX IF EXISTS doctor_payout_items_active_tranche_unique');

        Schema::table('doctor_payout_items', function (Blueprint $table) {
            $table->dropColumn(['basis', 'tranche', 'received_amount', 'expected_amount', 'released_before_amount', 'receipts_until', 'receipts']);
        });

        Schema::table('doctor_payouts', function (Blueprint $table) {
            $table->dropColumn('basis');
        });

        // Volta a garantia por ato: falha (de propósito) se houver parcelas
        // complementares válidas — reabra esses fechamentos antes de reverter.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX doctor_payout_items_active_source_unique
            ON doctor_payout_items (entity_id, source_type, source_id)
            WHERE voided_at IS NULL
        SQL);
    }
};
