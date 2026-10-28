<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

/**
 * Fechamento de repasse de um médico num período — App\Models\DoctorPayout.
 * Itens (retrato de cada ato) em `doctor_payout_items`; ajustes manuais em
 * `doctor_payout_adjustments`.
 *
 * Sem soft delete: um fechamento nunca some — reabrir = status `cancelled`
 * (motivo, quem e quando na própria linha, como cash_closes). O lançamento de
 * caixa do pagamento (cash_entry_id) aponta para cá via reference_type
 * `doctor_payout`; apagar a linha deixaria o lançamento órfão.
 *
 * Totais denormalizados, escritos só pelo DoctorPayoutClosingService:
 * gross_amount = soma do valor base (produção), items_amount = soma dos
 * repasses por item, adjustments_amount = soma dos ajustes (±),
 * total_amount = items_amount + adjustments_amount (nunca negativo).
 *
 * doctor_id sem ON DELETE: um médico com fechamento não pode ser apagado de
 * fato (o cadastro de médicos só faz soft delete); a exclusão em cascata de
 * uma clínica inteira continua funcionando porque a mesma instrução apaga os
 * fechamentos pela entity_id.
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::create('doctor_payouts', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('created_by')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->foreignUuid('entity_id')
                ->constrained('entities')->cascadeOnDelete();
            $table->foreignUuid('doctor_id')->constrained('doctors');

            $table->string('code');

            // Retrato do médico no fechamento (o cadastro pode mudar ou ser removido).
            $table->string('doctor_name');
            $table->string('doctor_record')->nullable();

            $table->date('period_start');
            $table->date('period_end');
            $table->string('status', 20)->default('closed'); // App\Enums\DoctorPayout\DoctorPayoutStatus

            $table->unsignedInteger('items_count')->default(0);
            $table->decimal('gross_amount', 12, 2)->default(0);
            $table->decimal('items_amount', 12, 2)->default(0);
            $table->decimal('adjustments_amount', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2)->default(0);

            $table->timestamp('closed_at');
            $table->foreignUuid('closed_by')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->date('paid_at')->nullable();
            $table->decimal('paid_amount', 12, 2)->nullable();
            $table->string('payment_method', 40)->nullable();
            $table->text('payment_notes')->nullable();
            $table->foreignUuid('paid_by')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->foreignUuid('cash_entry_id')->nullable()
                ->constrained('financial_cash_entries')->nullOnDelete();

            $table->text('payment_reversal_reason')->nullable();
            $table->foreignUuid('payment_reversed_by')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamp('payment_reversed_at')->nullable();

            $table->text('cancel_reason')->nullable();
            $table->foreignUuid('cancelled_by')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();

            $table->text('notes')->nullable();

            $table->timestamps();

            $table->unique(['entity_id', 'code']);
            $table->index(['entity_id', 'doctor_id', 'status']);
            $table->index(['entity_id', 'status', 'period_end']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE doctor_payouts
                ADD CONSTRAINT doctor_payouts_status_check
                    CHECK (status IN ('closed', 'paid', 'cancelled')),
                ADD CONSTRAINT doctor_payouts_period_check
                    CHECK (period_end >= period_start),
                ADD CONSTRAINT doctor_payouts_total_check
                    CHECK (total_amount >= 0)
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('doctor_payouts');
    }
};
