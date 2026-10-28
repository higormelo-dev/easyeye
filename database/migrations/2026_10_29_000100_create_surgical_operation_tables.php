<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

/**
 * Módulo cirúrgico (Fase 4) / M4 — a cirurgia realizada.
 *
 *  - `surgical_operations`: o ato de registrar a cirurgia como realizada (quem,
 *    quando, equipe confirmada, intercorrências e as justificativas das
 *    exceções que o cirurgião assumiu). 1:1 com o caso.
 *  - `surgical_implants`: implante CONGELADO (RDC 556/2021). Copia produto,
 *    fabricante, ANVISA, lote, série e validade: `ProductLotsController::update`
 *    edita lote/validade retroativamente, então o registro nunca aponta só para
 *    o cadastro vivo. `serial_scope` = produto de estoque (ou fabricante+modelo
 *    quando o implante veio de fora, ex.: consignado do hospital): a série é
 *    única por escopo entre os implantes ATIVOS.
 *  - `surgical_descriptions` / `surgical_description_addenda`: descrição
 *    cirúrgica (rascunho → finalizada com hash); depois de finalizada, só
 *    adendo.
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::create('surgical_operations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities')->cascadeOnDelete();
            $table->foreignUuid('surgical_case_id')->constrained('surgical_cases')->cascadeOnDelete();
            $table->foreignUuid('performed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('performed_by_doctor_id')->nullable()->constrained('doctors')->nullOnDelete();
            $table->timestamp('performed_at');
            $table->timestamp('team_confirmed_at')->nullable();
            $table->text('complications')->nullable();
            $table->text('no_implant_reason')->nullable();
            $table->jsonb('override_reasons')->default('{}');
            $table->timestamps();

            $table->unique('surgical_case_id', 'surgical_operations_case_unique');
        });

        Schema::create('surgical_implants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities')->cascadeOnDelete();
            $table->foreignUuid('surgical_case_id')->constrained('surgical_cases')->cascadeOnDelete();
            $table->foreignUuid('patient_id')->constrained('patients')->cascadeOnDelete();
            $table->string('kind', 8);
            $table->string('stock_source', 12)->default('clinic_stock');
            $table->foreignUuid('entity_product_id')->nullable()->constrained('entity_products')->nullOnDelete();
            $table->foreignUuid('stock_lot_id')->nullable()->constrained('stock_lots')->nullOnDelete();
            $table->string('eye', 2);
            $table->string('product_name', 255);
            $table->string('manufacturer', 120)->nullable();
            $table->string('anvisa_registration', 15)->nullable();
            $table->string('model_reference', 80)->nullable();
            $table->decimal('power', 5, 2)->nullable();
            $table->string('lot_number', 60)->nullable();
            $table->string('serial_number', 60)->nullable();
            $table->date('expiry_date')->nullable();
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->string('serial_scope', 255);
            $table->foreignUuid('stock_movement_id')->nullable()->constrained('stock_movements')->nullOnDelete();
            $table->foreignUuid('reversal_movement_id')->nullable()->constrained('stock_movements')->nullOnDelete();
            $table->foreignUuid('loss_movement_id')->nullable()->constrained('stock_movements')->nullOnDelete();
            $table->string('status', 8)->default('active');
            $table->foreignUuid('registered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('registered_by_doctor_id')->nullable()->constrained('doctors')->nullOnDelete();
            $table->timestamp('implanted_at');
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason', 500)->nullable();
            $table->string('void_stock_action', 10)->nullable();
            $table->foreignUuid('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->char('content_hash', 64);
            $table->timestamps();

            $table->index(['surgical_case_id', 'status'], 'surgical_implants_case_status_idx');
            $table->index(['entity_id', 'patient_id'], 'surgical_implants_patient_idx');
        });

        // A série é única entre os implantes ATIVOS do mesmo escopo (produto/modelo).
        // Implante anulado libera a série.
        DB::statement("
            CREATE UNIQUE INDEX surgical_implants_serial_active_unique
                ON surgical_implants (entity_id, serial_scope, serial_number)
             WHERE status = 'active' AND serial_number IS NOT NULL
        ");

        Schema::create('surgical_descriptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities')->cascadeOnDelete();
            $table->foreignUuid('surgical_case_id')->constrained('surgical_cases')->cascadeOnDelete();
            $table->foreignUuid('patient_id')->constrained('patients')->cascadeOnDelete();
            $table->foreignUuid('surgeon_doctor_id')->constrained('doctors');
            $table->string('status', 10)->default('draft');
            $table->timestamp('performed_start_at')->nullable();
            $table->timestamp('performed_end_at')->nullable();
            $table->string('anesthesia_used', 24)->nullable();
            $table->text('findings')->nullable();
            $table->text('technique_performed')->nullable();
            $table->boolean('no_complications')->default(false);
            $table->text('complications')->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->jsonb('header_snapshot')->nullable();
            $table->timestamp('finalized_at')->nullable();
            $table->foreignUuid('finalized_by_doctor_id')->nullable()->constrained('doctors')->nullOnDelete();
            $table->char('content_hash', 64)->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique('surgical_case_id', 'surgical_descriptions_case_unique');
            $table->index(['entity_id', 'patient_id'], 'surgical_descriptions_patient_idx');
        });

        Schema::create('surgical_description_addenda', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities')->cascadeOnDelete();
            $table->foreignUuid('surgical_description_id')->constrained('surgical_descriptions')->cascadeOnDelete();
            $table->foreignUuid('surgical_case_id')->constrained('surgical_cases')->cascadeOnDelete();
            $table->foreignUuid('patient_id')->constrained('patients')->cascadeOnDelete();
            $table->text('text');
            $table->string('reason', 500);
            $table->foreignUuid('doctor_id')->nullable()->constrained('doctors')->nullOnDelete();
            $table->string('doctor_name', 150)->nullable();
            $table->string('doctor_registry', 40)->nullable();
            $table->char('content_hash', 64);
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['surgical_description_id', 'created_at'], 'surgical_description_addenda_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('surgical_description_addenda');
        Schema::dropIfExists('surgical_descriptions');
        Schema::dropIfExists('surgical_implants');
        Schema::dropIfExists('surgical_operations');
    }
};
