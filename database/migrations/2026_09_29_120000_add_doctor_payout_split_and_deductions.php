<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

/**
 * Repasse médico E4 (decisões de 2026-09-29): deduções antes de dividir e
 * divisão em etapas clínica → grupo → participantes.
 *
 * - doctor_payout_deduction_rates: taxas da clínica com vigência (a que vale
 *   é a de maior valid_from ≤ data do RECEBIMENTO): cartão débito / cartão
 *   crédito (só a parte paga com cartão no balcão), imposto (alíquota única)
 *   e taxa administrativa — cada uma sobre o recebido BRUTO, sem cascata.
 * - doctor_payout_rule_participants: participantes de uma regra percentual —
 *   o executor (médico do item) e médicos fixos (ex.: líder), com % do valor
 *   do grupo somando 100%. Regra sem participantes = executor com 100%
 *   (comportamento anterior). O % da regra é a parte do GRUPO sobre o
 *   recebido líquido; a clínica fica com o restante.
 * - doctor_payout_items ganha o BENEFICIÁRIO (doctor_id — o médico do
 *   fechamento), o papel e o retrato da divisão/deduções. A garantia contra
 *   liberar duas vezes passa a ser por ato + beneficiário + parcela (cada
 *   participante tem as próprias parcelas do mesmo ato).
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::create('doctor_payout_deduction_rates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('entity_id')->constrained('entities')->cascadeOnDelete();

            $table->string('kind', 20); // App\Enums\DoctorPayout\DoctorPayoutDeductionKind
            $table->decimal('percentage', 5, 2);
            $table->date('valid_from');
            $table->string('notes', 1000)->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['entity_id', 'kind', 'valid_from']);
        });

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX doctor_payout_deduction_rates_active_unique
            ON doctor_payout_deduction_rates (entity_id, kind, valid_from)
            WHERE deleted_at IS NULL
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE doctor_payout_deduction_rates
                ADD CONSTRAINT doctor_payout_deduction_rates_kind_check
                    CHECK (kind IN ('card_debit', 'card_credit', 'tax', 'admin')),
                ADD CONSTRAINT doctor_payout_deduction_rates_percentage_check
                    CHECK (percentage >= 0 AND percentage <= 100)
        SQL);

        Schema::create('doctor_payout_rule_participants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities')->cascadeOnDelete();
            $table->foreignUuid('doctor_payout_rule_id')->constrained('doctor_payout_rules')->cascadeOnDelete();

            $table->string('role', 20); // executor | doctor
            $table->foreignUuid('doctor_id')->nullable()->constrained('doctors')->restrictOnDelete();
            $table->decimal('percentage', 5, 2);
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();

            $table->index('doctor_payout_rule_id');
            $table->index(['entity_id', 'doctor_id']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE doctor_payout_rule_participants
                ADD CONSTRAINT doctor_payout_rule_participants_role_check
                    CHECK (role IN ('executor', 'doctor')),
                ADD CONSTRAINT doctor_payout_rule_participants_doctor_check
                    CHECK ((role = 'executor' AND doctor_id IS NULL) OR (role = 'doctor' AND doctor_id IS NOT NULL)),
                ADD CONSTRAINT doctor_payout_rule_participants_percentage_check
                    CHECK (percentage > 0 AND percentage <= 100)
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX doctor_payout_rule_participants_executor_unique
            ON doctor_payout_rule_participants (doctor_payout_rule_id)
            WHERE role = 'executor'
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX doctor_payout_rule_participants_doctor_unique
            ON doctor_payout_rule_participants (doctor_payout_rule_id, doctor_id)
            WHERE role = 'doctor'
        SQL);

        Schema::table('doctor_payout_items', function (Blueprint $table) {
            // Beneficiário da parcela = médico do fechamento (a clínica paga cada participante).
            $table->foreignUuid('doctor_id')->nullable()->after('doctor_payout_id')
                ->constrained('doctors')->restrictOnDelete();
            $table->string('beneficiary_role', 20)->default('executor')->after('doctor_id'); // executor | doctor | both
            // % do grupo que cabe a este beneficiário (100 = executor sozinho).
            $table->decimal('share_percentage', 5, 2)->nullable()->after('rule_fixed_amount');
            // Recebido líquido acumulado (recebido − deduções) e deduções acumuladas do ato.
            $table->decimal('net_amount', 12, 2)->nullable()->after('received_amount');
            $table->decimal('deductions_amount', 12, 2)->default(0)->after('net_amount');
            // Retrato da divisão e das deduções: {group_percentage, participants[], deductions{}}.
            $table->json('split')->nullable()->after('receipts');
        });

        // Itens existentes: o beneficiário é o médico do fechamento.
        DB::statement(<<<'SQL'
            UPDATE doctor_payout_items i
            SET doctor_id = dp.doctor_id
            FROM doctor_payouts dp
            WHERE dp.id = i.doctor_payout_id
              AND i.doctor_id IS NULL
        SQL);

        DB::statement('DROP INDEX IF EXISTS doctor_payout_items_active_tranche_unique');

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX doctor_payout_items_active_beneficiary_tranche_unique
            ON doctor_payout_items (entity_id, source_type, source_id, doctor_id, tranche)
            WHERE voided_at IS NULL
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE doctor_payout_items
                ADD CONSTRAINT doctor_payout_items_beneficiary_role_check
                    CHECK (beneficiary_role IN ('executor', 'doctor', 'both'))
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE doctor_payout_items DROP CONSTRAINT IF EXISTS doctor_payout_items_beneficiary_role_check');
        DB::statement('DROP INDEX IF EXISTS doctor_payout_items_active_beneficiary_tranche_unique');

        Schema::table('doctor_payout_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('doctor_id');
            $table->dropColumn(['beneficiary_role', 'share_percentage', 'net_amount', 'deductions_amount', 'split']);
        });

        // Volta a garantia por ato + parcela: falha (de propósito) se houver
        // parcelas de participantes diferentes do mesmo ato — reabra antes.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX doctor_payout_items_active_tranche_unique
            ON doctor_payout_items (entity_id, source_type, source_id, tranche)
            WHERE voided_at IS NULL
        SQL);

        Schema::dropIfExists('doctor_payout_rule_participants');
        Schema::dropIfExists('doctor_payout_deduction_rates');
    }
};
