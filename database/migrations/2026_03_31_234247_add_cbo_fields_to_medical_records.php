<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Campos exigidos pelo CBO — Conselho Brasileiro de Oftalmologia.
 *
 * O prontuário oftalmológico completo deve registrar:
 *   - Anamnese: queixa principal (já existe), HDA, antecedentes cirúrgicos oculares, medicamentos em uso
 *   - Exame físico: visão cromática (FK corrigida), motilidade ocular, paquimetria, gonioscopia
 *   - Diagnóstico: CID-10 + descrição livre
 *   - Conduta: plano terapêutico e retorno
 *
 * Correção de bug: color_vision_type_id estava sendo salvo incorretamente em visual_acuity_type_id.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('medical_records', function (Blueprint $table) {
            // ── Correção: visão cromática precisa de FK própria ─────────────
            $table->foreignUuid('color_vision_type_id')
                ->nullable()
                ->constrained('color_vision_types')
                ->nullOnDelete()
                ->after('cover_test_type_id');

            // ── Anamnese completa (CBO) ──────────────────────────────────────
            // História da doença atual — narrativa clínica além da queixa principal
            $table->text('hda')->nullable()->after('main_complaint');
            // Cirurgias, traumas e patologias oculares prévias
            $table->text('ocular_surgical_history')->nullable()->after('glaucomatous_family');
            // Medicamentos em uso (sistêmicos e colírios)
            $table->text('medications_in_use')->nullable()->after('ocular_surgical_history');

            // ── Exame físico — campos obrigatórios CBO ───────────────────────
            // Motilidade ocular extrínseca (além do cover test já existente)
            $table->text('ocular_motility')->nullable()->after('medications_in_use');
            // Paquimetria central (μm) — obrigatória em avaliação de glaucoma
            $table->unsignedSmallInteger('pachymetry_right')->nullable()->after('ocular_motility');
            $table->unsignedSmallInteger('pachymetry_left')->nullable()->after('pachymetry_right');
            // Gonioscopia — avaliação do ângulo iridocorneal em glaucoma
            $table->text('gonioscopy_right')->nullable()->after('pachymetry_left');
            $table->text('gonioscopy_left')->nullable()->after('gonioscopy_right');

            // ── Diagnóstico (CBO obrigatório) ────────────────────────────────
            // Código CID-10 (ex: H40.1 — Glaucoma primário de ângulo aberto)
            $table->string('diagnosis_cid10', 10)->nullable()->after('observation_of_lenses');
            // Descrição livre do diagnóstico
            $table->text('diagnosis_description')->nullable()->after('diagnosis_cid10');

            // ── Conduta clínica (CBO obrigatório) ───────────────────────────
            // Plano terapêutico: medicamentos, cirurgia, encaminhamentos, orientações
            $table->text('clinical_conduct')->nullable()->after('diagnosis_description');
            // Retorno: intervalo em dias até próxima consulta
            $table->unsignedSmallInteger('follow_up_days')->nullable()->after('clinical_conduct');
        });
    }

    public function down(): void
    {
        Schema::table('medical_records', function (Blueprint $table) {
            $table->dropForeign(['color_vision_type_id']);
            $table->dropColumn([
                'color_vision_type_id',
                'hda',
                'ocular_surgical_history',
                'medications_in_use',
                'ocular_motility',
                'pachymetry_right',
                'pachymetry_left',
                'gonioscopy_right',
                'gonioscopy_left',
                'diagnosis_cid10',
                'diagnosis_description',
                'clinical_conduct',
                'follow_up_days',
            ]);
        });
    }
};
