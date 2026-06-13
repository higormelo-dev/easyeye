<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Onda 3, P5 — Feedback loop.
 *
 * Quando o médico edita >30% do rascunho da IA antes de aprovar, captura
 * categorias estruturadas + nota opcional. Vira dataset para análise de
 * qualidade e eventual fine-tuning.
 *
 * Unique(ai_run_id) garante 1 feedback por run. Re-submit do mesmo run
 * substitui o anterior (updateOrCreate).
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::create('ai_run_feedbacks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('ai_run_id')->constrained('ai_runs')->cascadeOnDelete();
            $table->foreignUuid('entity_id')->constrained('entities')->cascadeOnDelete();
            $table->foreignUuid('doctor_id')->nullable()->constrained('doctors')->nullOnDelete();
            $table->unsignedSmallInteger('edit_ratio_percent')->comment('0-100: % de palavras alteradas pelo médico');
            $table->json('tags')->comment('list<string>: diagnosis_wrong|language|missing_context|excess|other');
            $table->text('note')->nullable();
            $table->timestamp('submitted_at');
            $table->timestamps();

            $table->unique('ai_run_id', 'ai_run_feedbacks_run_unique');
            $table->index(['entity_id', 'submitted_at'], 'ai_run_feedbacks_entity_submitted_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_run_feedbacks');
    }
};
