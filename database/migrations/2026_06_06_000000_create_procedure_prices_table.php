<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabela de preço por procedimento × convênio, portada do ProcedurePrice do
 * smart_oftal. Multi-tenant: linhas da entidade sobrepõem as globais (entity_id null).
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::create('procedure_prices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('deleted_by')->nullable()->constrained('users')->nullOnDelete();

            $table->foreignUuid('entity_id')->nullable()->constrained('entities')->cascadeOnDelete();
            $table->foreignUuid('covenant_id')->constrained('covenants')->cascadeOnDelete();
            $table->foreignUuid('procedure_id')->constrained('procedures')->cascadeOnDelete();

            $table->decimal('price', 14, 2);
            $table->boolean('charging')->default(true); // faturável ao convênio
            $table->boolean('active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->unique(
                ['entity_id', 'covenant_id', 'procedure_id'],
                'procedure_prices_entity_covenant_procedure_unique',
            );
            $table->index(['entity_id', 'covenant_id'], 'procedure_prices_entity_covenant_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('procedure_prices');
    }
};
