<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BOM (bill of materials) de estoque por procedimento — App\Models\ProcedureProduct.
 *
 * "Procedimento X normalmente consome Y unidades do produto Z". Usado só
 * como SUGESTÃO de pré-preenchimento ao confirmar consumo (ver
 * App\Services\Stock\MedicalRecordProcedureExecutionService) — nunca baixa
 * estoque sozinho; a confirmação humana de quantidade/lote continua
 * obrigatória (regra de negócio decidida na Fase 3, mesmo motivo do lote
 * OPM: ninguém automatiza qual unidade física foi usada num paciente).
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::create('procedure_products', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('created_by')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->foreignUuid('deleted_by')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->foreignUuid('entity_id')
                ->constrained('entities')->cascadeOnDelete();
            $table->foreignUuid('procedure_id')
                ->constrained('procedures')->cascadeOnDelete();
            $table->foreignUuid('entity_product_id')
                ->constrained('entity_products')->cascadeOnDelete();

            $table->decimal('quantity', 12, 3)->default(1);
            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['procedure_id', 'entity_product_id']);
            $table->index(['entity_id', 'procedure_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('procedure_products');
    }
};
