<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

/**
 * Retrato de cada ato incluído num fechamento — App\Models\DoctorPayoutItem.
 *
 * Gravado em lote pelo DoctorPayoutClosingService (sem Auditable por linha:
 * a auditoria fica no fechamento). Valores e regra aplicada são copiados no
 * fechamento: editar agendamento, preço ou regra depois não altera o
 * demonstrativo.
 *
 * Garantia contra pagar o mesmo ato duas vezes: índice único parcial
 * (entity_id, source_type, source_id) WHERE voided_at IS NULL. Reabrir um
 * fechamento preenche voided_at nos itens, liberando os atos para um novo
 * fechamento.
 *
 * source_id é texto: uuid do agendamento/procedimento ou a chave
 * "paciente|tipo de exame|data local" do exame de equipamento.
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::create('doctor_payout_items', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('entity_id')
                ->constrained('entities')->cascadeOnDelete();
            $table->foreignUuid('doctor_payout_id')
                ->constrained('doctor_payouts')->cascadeOnDelete();

            $table->string('source_type', 40); // App\Enums\DoctorPayout\DoctorPayoutSourceType
            $table->string('source_id', 120);
            $table->string('service_type', 20); // App\Enums\DoctorPayout\DoctorPayoutServiceType
            $table->timestamp('performed_at');

            $table->foreignUuid('patient_id')->nullable()
                ->constrained('patients')->nullOnDelete();
            $table->foreignUuid('covenant_id')->nullable()
                ->constrained('covenants')->nullOnDelete();
            $table->boolean('is_particular')->default(false);
            $table->string('covenant_name')->nullable();
            $table->string('description');

            $table->foreignUuid('visit_type_id')->nullable()
                ->constrained('visit_types')->nullOnDelete();
            $table->foreignUuid('procedure_id')->nullable()
                ->constrained('procedures')->nullOnDelete();
            $table->foreignUuid('exam_type_id')->nullable()
                ->constrained('exam_types')->nullOnDelete();

            $table->decimal('base_amount', 12, 2)->default(0);
            $table->string('base_source', 20); // App\Enums\DoctorPayout\DoctorPayoutBaseSource

            $table->foreignUuid('doctor_payout_rule_id')->nullable()
                ->constrained('doctor_payout_rules')->nullOnDelete();
            $table->string('rule_calculation', 20)->nullable();
            $table->decimal('rule_percentage', 5, 2)->nullable();
            $table->decimal('rule_fixed_amount', 12, 2)->nullable();

            $table->decimal('payout_amount', 12, 2)->default(0);
            $table->json('warnings')->nullable();

            $table->timestamp('voided_at')->nullable();
            $table->timestamps();

            $table->index('doctor_payout_id');
            $table->index(['entity_id', 'performed_at']);
        });

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX doctor_payout_items_active_source_unique
            ON doctor_payout_items (entity_id, source_type, source_id)
            WHERE voided_at IS NULL
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('doctor_payout_items');
    }
};
