<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogo GLOBAL de modelos de lente intraocular (IOL/catarata) —
 * fabricante + modelo + foto de produto, sem escopo por entity. Cresce
 * organicamente conforme clínicas cadastram (ver IolLensCatalogService::
 * findOrCreateModel()); qualquer clínica pode ver/reaproveitar o que outra
 * já cadastrou. Mesmo padrão de Cid10Code (catálogo global) + entity_custom_
 * diagnoses (customização por clínica), aplicado aqui a lentes IOL.
 *
 * Diferente de Cid10Code (catálogo estático, seedado uma vez), este catálogo
 * é alimentado dinamicamente por ação do usuário — por isso os campos
 * created_by/updated_by/deleted_by (HasAuditColumns) foram incluídos aqui
 * mesmo sendo uma tabela global, para manter rastreabilidade de quem
 * cadastrou/alterou cada modelo.
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::create('iol_lens_models', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('created_by')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->foreignUuid('deleted_by')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->string('manufacturer');
            $table->string('model_name');
            $table->string('category')->nullable();
            $table->string('normalized_key')->unique();
            $table->string('image_path')->nullable();

            // Proveniência: qual clínica cadastrou primeiro este modelo no
            // catálogo global; null = seed do sistema.
            $table->foreignUuid('created_by_entity_id')->nullable()
                ->constrained('entities')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('iol_lens_models');
    }
};
