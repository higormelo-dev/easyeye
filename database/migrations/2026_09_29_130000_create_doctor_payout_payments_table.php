<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};
use Illuminate\Support\Str;

/**
 * Pagamentos PARCIAIS de um fechamento de repasse (E5, decisões de
 * 2026-09-29) — App\Models\DoctorPayoutPayment.
 *
 * Um fechamento recebe um ou mais pagamentos até quitar o total; cada
 * pagamento com valor gera UMA despesa paga no Fluxo de Caixa
 * (reference_type `doctor_payout_payment`, reference_id = pagamento). Estorno
 * é lógico (reversed_*, com motivo) — o histórico fica e a despesa é removida.
 * O status do fechamento acompanha: closed (nada pago) → partially_paid →
 * paid (quitado).
 *
 * Garantias contra duplicidade:
 *  - no máximo UMA despesa ativa por pagamento (índice único parcial em
 *    financial_cash_entries) e cada despesa ligada a um só pagamento
 *    (índice único em cash_entry_id);
 *  - a soma dos pagamentos válidos nunca passa do total: conferida sob
 *    FOR UPDATE do fechamento, junto com o "já pago" que a tela viu (duplo
 *    clique vira 422, não dois pagamentos).
 * O índice antigo "uma despesa `doctor_payout` por fechamento" continua
 * valendo para os lançamentos anteriores a esta etapa.
 *
 * Fechamentos já pagos viram UM pagamento cada (backfill idempotente, com a
 * despesa original — nenhum lançamento de caixa é alterado). Pago sem data
 * ou valor (não acontece pelo fluxo anterior, mas não havia CHECK) usa a data
 * da última alteração e o total — senão ficaria "pago" sem pagamento, sem
 * como estornar nem reabrir.
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::create('doctor_payout_payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('created_by')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->foreignUuid('entity_id')
                ->constrained('entities')->cascadeOnDelete();
            $table->foreignUuid('doctor_payout_id')
                ->constrained('doctor_payouts')->cascadeOnDelete();

            $table->decimal('amount', 12, 2);
            $table->date('paid_at');
            $table->string('payment_method', 40)->nullable(); // App\Enums\PaymentMethod
            $table->text('notes')->nullable();
            $table->foreignUuid('cash_entry_id')->nullable()
                ->constrained('financial_cash_entries')->nullOnDelete();

            $table->timestamp('reversed_at')->nullable();
            $table->foreignUuid('reversed_by')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->text('reversal_reason')->nullable();

            $table->timestamps();

            $table->index(['entity_id', 'doctor_payout_id']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE doctor_payout_payments
                ADD CONSTRAINT doctor_payout_payments_amount_check CHECK (amount >= 0)
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX doctor_payout_payments_cash_entry_unique
            ON doctor_payout_payments (cash_entry_id)
            WHERE cash_entry_id IS NOT NULL
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX IF NOT EXISTS financial_cash_entries_doctor_payout_payment_unique
            ON financial_cash_entries (reference_id)
            WHERE reference_type = 'doctor_payout_payment' AND deleted_at IS NULL
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE doctor_payouts
                DROP CONSTRAINT doctor_payouts_status_check,
                ADD CONSTRAINT doctor_payouts_status_check
                    CHECK (status IN ('closed', 'partially_paid', 'paid', 'cancelled'))
        SQL);

        $this->backfill();
    }

    /**
     * Cada fechamento pago (regime anterior a esta etapa) vira um pagamento
     * com os dados e a despesa que já tinha. Idempotente: fechamento que já
     * tem pagamento não é tocado.
     */
    public function backfill(): void
    {
        DB::table('doctor_payouts as p')
            ->where('p.status', 'paid')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')
                ->from('doctor_payout_payments as x')
                ->whereColumn('x.doctor_payout_id', 'p.id'))
            ->select(['p.id', 'p.entity_id', 'p.paid_at', 'p.paid_amount', 'p.total_amount', 'p.payment_method', 'p.payment_notes', 'p.cash_entry_id', 'p.paid_by', 'p.updated_at'])
            ->lazyById(200, 'p.id', 'id')
            ->each(function (object $payout): void {
                $at = $payout->updated_at ?? now();

                DB::table('doctor_payout_payments')->insert([
                    'id'               => (string) Str::uuid(),
                    'created_by'       => $payout->paid_by,
                    'updated_by'       => $payout->paid_by,
                    'entity_id'        => $payout->entity_id,
                    'doctor_payout_id' => $payout->id,
                    'amount'           => $payout->paid_amount ?? $payout->total_amount ?? 0,
                    'paid_at'          => $payout->paid_at ?? substr((string) $at, 0, 10),
                    'payment_method'   => $payout->payment_method,
                    'notes'            => $payout->payment_notes,
                    'cash_entry_id'    => $payout->cash_entry_id,
                    'created_at'       => $at,
                    'updated_at'       => $at,
                ]);
            });
    }

    public function down(): void
    {
        // Voltar apagaria pagamentos parciais e despesas do novo tipo sem
        // lugar no modelo antigo (um pagamento por fechamento): recusa.
        $partial = DB::table('doctor_payouts')->where('status', 'partially_paid')->exists();
        $entries = DB::table('financial_cash_entries')->where('reference_type', 'doctor_payout_payment')->exists();

        if ($partial || $entries) {
            throw new RuntimeException('Há pagamentos de repasse registrados no modelo de pagamentos parciais; estorne-os antes de reverter esta migration.');
        }

        DB::statement('DROP INDEX IF EXISTS financial_cash_entries_doctor_payout_payment_unique');

        DB::statement(<<<'SQL'
            ALTER TABLE doctor_payouts
                DROP CONSTRAINT doctor_payouts_status_check,
                ADD CONSTRAINT doctor_payouts_status_check
                    CHECK (status IN ('closed', 'paid', 'cancelled'))
        SQL);

        Schema::dropIfExists('doctor_payout_payments');
    }
};
