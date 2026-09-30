<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

/**
 * Recebimento MANUAL do repasse (E3, decisões de 2026-09-29) —
 * App\Models\DoctorPayoutReceiptAllocation.
 *
 * Aloca parte de uma receita JÁ lançada no Fluxo de Caixa (paga, sem vínculo
 * com agendamento/guia — nunca cria receita nova, para não duplicar o
 * faturamento) a um ato de repasse:
 *  - target schedule: o atendimento (vale para o agendamento e para os
 *    procedimentos pareados com ele) — complemento de guia paga a menor,
 *    depósito avulso do convênio;
 *  - target medical_record_procedure / patient_exam: procedimento fora do
 *    agendamento / exame de equipamento, que não têm cobrança própria.
 *
 * Origem = cash_entry_id (data e valor do dinheiro); responsável =
 * created_by; vínculo = source_type/source_id. Estorno é lógico (reversed_*,
 * com motivo) — o histórico fica. A soma das alocações válidas de uma
 * receita nunca passa do valor dela (checado sob lock da linha da receita).
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::create('doctor_payout_receipt_allocations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('created_by')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->foreignUuid('entity_id')
                ->constrained('entities')->cascadeOnDelete();
            $table->foreignUuid('cash_entry_id')
                ->constrained('financial_cash_entries')->restrictOnDelete();

            $table->string('source_type', 40); // App\Enums\DoctorPayout\DoctorPayoutSourceType
            $table->string('source_id', 120);
            $table->decimal('amount', 12, 2);
            $table->string('notes', 1000)->nullable();

            $table->timestamp('reversed_at')->nullable();
            $table->foreignUuid('reversed_by')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->string('reversal_reason', 1000)->nullable();

            $table->timestamps();

            $table->index(['entity_id', 'source_type', 'source_id']);
            $table->index('cash_entry_id');
        });

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX doctor_payout_allocations_active_unique
            ON doctor_payout_receipt_allocations (cash_entry_id, source_type, source_id)
            WHERE reversed_at IS NULL
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE doctor_payout_receipt_allocations
                ADD CONSTRAINT doctor_payout_allocations_amount_check CHECK (amount > 0),
                ADD CONSTRAINT doctor_payout_allocations_source_check
                    CHECK (source_type IN ('schedule', 'medical_record_procedure', 'patient_exam'))
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('doctor_payout_receipt_allocations');
    }
};
