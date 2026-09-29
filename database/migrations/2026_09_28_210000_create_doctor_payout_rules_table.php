<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

/**
 * Regras de repasse médico — App\Models\DoctorPayoutRule.
 *
 * Escopo: médico (null = todos os médicos da clínica), tipo de serviço
 * (obrigatório), no máximo um item específico (tipo de atendimento,
 * procedimento ou tipo de exame), pagador (qualquer/particular/convênio) e
 * convênio específico opcional. Cálculo: percentual sobre o valor base do item
 * ou valor fixo por item. Vigência opcional (valid_from/valid_until): a regra
 * vale para itens cuja data cai no intervalo; sobreposição de vigência no
 * mesmo escopo é recusada pelo DoctorPayoutRuleService (sob lock por clínica).
 *
 * Fechamentos guardam o retrato da regra aplicada em cada item — editar uma
 * regra nunca altera fechamento já feito.
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::create('doctor_payout_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('created_by')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->foreignUuid('deleted_by')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->foreignUuid('entity_id')
                ->constrained('entities')->cascadeOnDelete();
            $table->foreignUuid('doctor_id')->nullable()
                ->constrained('doctors')->cascadeOnDelete();

            $table->string('service_type', 20); // App\Enums\DoctorPayout\DoctorPayoutServiceType
            $table->foreignUuid('visit_type_id')->nullable()
                ->constrained('visit_types')->cascadeOnDelete();
            $table->foreignUuid('procedure_id')->nullable()
                ->constrained('procedures')->cascadeOnDelete();
            $table->foreignUuid('exam_type_id')->nullable()
                ->constrained('exam_types')->cascadeOnDelete();

            $table->string('payer_scope', 20)->default('any'); // App\Enums\DoctorPayout\DoctorPayoutPayerScope
            $table->foreignUuid('covenant_id')->nullable()
                ->constrained('covenants')->cascadeOnDelete();

            $table->string('calculation', 20); // App\Enums\DoctorPayout\DoctorPayoutCalculation
            $table->decimal('percentage', 5, 2)->nullable();
            $table->decimal('fixed_amount', 12, 2)->nullable();

            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable();

            $table->boolean('active')->default(true);
            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['entity_id', 'service_type']);
            $table->index(['entity_id', 'doctor_id']);
        });

        // Invariantes que nenhum caminho (form, service, tinker) pode violar.
        DB::statement(<<<'SQL'
            ALTER TABLE doctor_payout_rules
                ADD CONSTRAINT doctor_payout_rules_single_item_check
                    CHECK (num_nonnulls(visit_type_id, procedure_id, exam_type_id) <= 1),
                ADD CONSTRAINT doctor_payout_rules_calculation_check
                    CHECK (
                        (calculation = 'percentage' AND percentage IS NOT NULL
                            AND percentage >= 0 AND percentage <= 100 AND fixed_amount IS NULL)
                        OR (calculation = 'fixed' AND fixed_amount IS NOT NULL
                            AND fixed_amount >= 0 AND percentage IS NULL)
                    ),
                ADD CONSTRAINT doctor_payout_rules_covenant_scope_check
                    CHECK (covenant_id IS NULL OR payer_scope = 'covenant'),
                ADD CONSTRAINT doctor_payout_rules_validity_check
                    CHECK (valid_from IS NULL OR valid_until IS NULL OR valid_until >= valid_from)
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('doctor_payout_rules');
    }
};
