<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    /**
     * Contador de uso por diagnóstico (CID-10 ou customizado) por entity, usado
     * para ordenar sugestões "mais usados" no diagnóstico de exame do
     * Gerenciador de Imagens. `diagnosis_type` é string livre validada pela
     * aplicação via App\Enums\DiagnosisType (sem check constraint no banco).
     */
    public function up(): void
    {
        Schema::create('entity_diagnosis_usages', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('entity_id')
                ->constrained('entities')
                ->cascadeOnDelete();
            $table->string('diagnosis_type');
            $table->foreignUuid('cid10_code_id')
                ->nullable()
                ->constrained('cid10_codes')
                ->cascadeOnDelete();
            $table->foreignUuid('entity_custom_diagnosis_id')
                ->nullable()
                ->constrained('entity_custom_diagnoses')
                ->cascadeOnDelete();
            $table->integer('use_count')->default(0);
            $table->timestamp('last_used_at')->nullable();

            $table->timestamps();

            $table->unique(
                ['entity_id', 'diagnosis_type', 'cid10_code_id', 'entity_custom_diagnosis_id'],
                'entity_diagnosis_usages_unique_target',
            );
            $table->index(['entity_id', 'use_count'], 'entity_diagnosis_usages_entity_id_use_count_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entity_diagnosis_usages');
    }
};
