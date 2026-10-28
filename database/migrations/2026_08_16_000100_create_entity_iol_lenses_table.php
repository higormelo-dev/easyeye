<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inventário de lentes intraoculares (IOL/catarata) POR CLÍNICA. Dado da
 * clínica (escopado por entity_id), diferente de iol_lens_models (catálogo
 * global de referência). manufacturer/model_name/category são snapshot —
 * mesmo quando vinculado ao catálogo global via iol_lens_model_id, guardam
 * uma cópia local estável (não depende de join pra exibir/buscar).
 * iol_lens_model_id é nullable: permite cadastro 100% customizado sem
 * vincular ao catálogo global (mesmo padrão de custom_diagnosis_id nullable
 * usado em outra feature).
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::create('entity_iol_lenses', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('created_by')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->foreignUuid('deleted_by')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->foreignUuid('entity_id')
                ->constrained('entities')->cascadeOnDelete();
            $table->foreignUuid('iol_lens_model_id')->nullable()
                ->constrained('iol_lens_models')->nullOnDelete();

            $table->string('manufacturer');
            $table->string('model_name');
            $table->string('category')->nullable();
            $table->decimal('diopter_min', 5, 2)->nullable();
            $table->decimal('diopter_max', 5, 2)->nullable();
            $table->decimal('price', 10, 2)->nullable();
            $table->string('image_path')->nullable();
            $table->boolean('active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['entity_id', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entity_iol_lenses');
    }
};
