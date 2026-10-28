<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    /**
     * Diagnósticos customizados por clínica (fora do catálogo CID-10), usados
     * no diagnóstico de exame do Gerenciador de Imagens quando não há código
     * CID-10 aplicável. `normalized_name` é preenchido pela aplicação (não
     * gerado no banco) para permitir unicidade case/acento-insensível por
     * entity via índice único composto.
     */
    public function up(): void
    {
        Schema::create('entity_custom_diagnoses', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('created_by')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->foreignUuid('deleted_by')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->foreignUuid('entity_id')
                ->constrained('entities')
                ->cascadeOnDelete();
            $table->string('name');
            $table->string('normalized_name');
            $table->foreignUuid('cid10_code_id')
                ->nullable()
                ->constrained('cid10_codes')
                ->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['entity_id', 'normalized_name'], 'entity_custom_diagnoses_entity_id_normalized_name_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entity_custom_diagnoses');
    }
};
