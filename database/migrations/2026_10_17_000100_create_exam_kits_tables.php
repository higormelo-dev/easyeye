<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

/**
 * Kits de exames por convênio — Fase 2 / B2.
 *
 *  - `exam_kits`: kit GLOBAL do SaaS (entity_id nulo, mantido no manager e
 *    semeado por ExamKitsSeeder) ou da CLÍNICA (copiado de um global —
 *    `source_kit_id` — ou criado do zero).
 *  - `exam_kit_items`: exames do kit pelo CÓDIGO TUSS + nome. O catálogo de
 *    procedimentos é por clínica: no pedido o item é resolvido pelo código
 *    no catálogo da clínica; código inexistente vira item de texto com aviso.
 *    `procedure_id` só em kit da clínica (item escolhido do catálogo dela).
 *  - `exam_kit_covenant`: convênios do kit da clínica (nenhum = todos).
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::create('exam_kits', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->nullable()->constrained('entities')->cascadeOnDelete();
            $table->uuid('source_kit_id')->nullable();
            $table->string('slug', 60)->nullable();
            $table->string('name', 120);
            $table->string('description', 500)->nullable();
            $table->boolean('active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['entity_id', 'active'], 'exam_kits_entity_active_idx');
        });

        // FK auto-referente depois da tabela (com a PK) criada.
        Schema::table('exam_kits', function (Blueprint $table) {
            $table->foreign('source_kit_id')->references('id')->on('exam_kits')->nullOnDelete();
        });

        // Kit global identificado pelo slug (seeder idempotente).
        DB::statement('CREATE UNIQUE INDEX exam_kits_global_slug_unique ON exam_kits (slug) WHERE entity_id IS NULL AND slug IS NOT NULL AND deleted_at IS NULL');

        Schema::create('exam_kit_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('exam_kit_id')->constrained('exam_kits')->cascadeOnDelete();
            $table->string('tuss_code', 10)->nullable();
            $table->string('name', 160);
            $table->foreignUuid('procedure_id')->nullable()->constrained('procedures')->nullOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['exam_kit_id', 'sort_order'], 'exam_kit_items_kit_order_idx');
        });

        Schema::create('exam_kit_covenant', function (Blueprint $table) {
            $table->foreignUuid('exam_kit_id')->constrained('exam_kits')->cascadeOnDelete();
            $table->foreignUuid('covenant_id')->constrained('covenants')->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->primary(['exam_kit_id', 'covenant_id']);
            $table->index('covenant_id', 'exam_kit_covenant_covenant_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_kit_covenant');
        Schema::dropIfExists('exam_kit_items');
        Schema::dropIfExists('exam_kits');
    }
};
