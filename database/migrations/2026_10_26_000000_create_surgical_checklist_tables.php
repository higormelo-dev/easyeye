<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Módulo cirúrgico (Fase 4) / M2 — checklist de cirurgia segura (OMS).
 *
 *  - `surgical_checklist_phases`: uma linha por caso + fase (Entrada, Pausa
 *    Cirúrgica, Saída). Aberta na primeira resposta; FECHADA é imutável e
 *    carrega o hash do conteúdo respondido (`content_hash`). A exceção da
 *    Pausa Cirúrgica (cirurgião realiza sem fechá-la) grava a justificativa e
 *    o médico na própria linha da fase (`override_*`).
 *  - `surgical_checklist_entries`: a resposta de cada item. O TEXTO do item é
 *    copiado no idioma de quem respondeu (`item_text`) junto com a versão do
 *    catálogo: mudar o catálogo depois não reescreve o que foi conferido.
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::create('surgical_checklist_phases', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities')->cascadeOnDelete();
            $table->foreignUuid('surgical_case_id')->constrained('surgical_cases')->cascadeOnDelete();
            $table->string('phase', 10);
            $table->string('catalog_version', 20);
            $table->string('locale', 10);
            $table->timestamp('opened_at');
            $table->foreignUuid('opened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->foreignUuid('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('closed_by_doctor_id')->nullable()->constrained('doctors')->nullOnDelete();
            $table->char('content_hash', 64)->nullable();
            $table->text('override_reason')->nullable();
            $table->foreignUuid('override_doctor_id')->nullable()->constrained('doctors')->nullOnDelete();
            $table->timestamp('override_at')->nullable();
            $table->timestamps();

            $table->unique(['surgical_case_id', 'phase'], 'surgical_checklist_phases_case_phase_unique');
            $table->index(['entity_id', 'phase', 'closed_at'], 'surgical_checklist_phases_entity_idx');
        });

        Schema::create('surgical_checklist_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities')->cascadeOnDelete();
            $table->foreignUuid('surgical_case_id')->constrained('surgical_cases')->cascadeOnDelete();
            $table->foreignUuid('surgical_checklist_phase_id')->constrained('surgical_checklist_phases')->cascadeOnDelete();
            $table->string('phase', 10);
            $table->string('item_key', 60);
            $table->text('item_text');
            $table->boolean('critical')->default(false);
            $table->string('answer', 3);
            $table->text('note')->nullable();
            $table->foreignUuid('answered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('answered_at');
            $table->timestamps();

            $table->unique(['surgical_checklist_phase_id', 'item_key'], 'surgical_checklist_entries_phase_item_unique');
            $table->index(['surgical_case_id', 'phase'], 'surgical_checklist_entries_case_phase_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('surgical_checklist_entries');
        Schema::dropIfExists('surgical_checklist_phases');
    }
};
