<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Segurança clínica — Fase 2 / Bloco A (A1 e A2).
 *
 *  - `patient_allergies`: alergias do PACIENTE (não da consulta). Recepção e
 *    médico registram; a recepção só como "informada pelo paciente"; só o
 *    médico com cadastro ativo confirma ou descarta (descartada fica no
 *    histórico, nunca some). Dado sensível (LGPD art. 11): auditado.
 *  - `patients.allergies_denied_*`: "nega alergias" explícito (quem/quando) —
 *    diferencia "não perguntado" de "perguntado e nega".
 *  - `patient_medications`: medicações em uso, lista persistente entre
 *    consultas (catálogo CMED opcional ou nome livre + princípio ativo). Os
 *    alertas de risco ocular saem do princípio ativo.
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->timestamp('allergies_denied_at')->nullable();
            $table->foreignUuid('allergies_denied_by')->nullable()->constrained('users')->nullOnDelete();
        });

        Schema::create('patient_allergies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities')->cascadeOnDelete();
            $table->foreignUuid('patient_id')->constrained('patients')->cascadeOnDelete();
            $table->string('category', 30);
            $table->string('substance', 160);
            $table->foreignUuid('medicine_id')->nullable()->constrained('medicines')->nullOnDelete();
            // 1000, igual à coluna do catálogo (medicines.active_ingredient) —
            // combinação com vários princípios ativos do CMED passa de 500
            // (14ª revisão Codex, P2).
            $table->string('active_ingredient', 1000)->nullable();
            $table->string('reaction', 500)->nullable();
            $table->string('severity', 20)->nullable();
            $table->string('status', 20);
            $table->timestamp('confirmed_at')->nullable();
            $table->foreignUuid('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('discarded_at')->nullable();
            $table->foreignUuid('discarded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('discard_reason', 500)->nullable();
            $table->text('notes')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['entity_id', 'patient_id', 'status'], 'patient_allergies_entity_patient_status_idx');
        });

        Schema::create('patient_medications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities')->cascadeOnDelete();
            $table->foreignUuid('patient_id')->constrained('patients')->cascadeOnDelete();
            $table->foreignUuid('medicine_id')->nullable()->constrained('medicines')->nullOnDelete();
            $table->string('name', 255);
            // 1000, igual à coluna do catálogo (medicines.active_ingredient) —
            // combinação com vários princípios ativos do CMED passa de 500
            // (14ª revisão Codex, P2).
            $table->string('active_ingredient', 1000)->nullable();
            $table->string('posology', 1000)->nullable();
            $table->date('started_on')->nullable();
            $table->date('ended_on')->nullable();
            $table->string('status', 20);
            $table->string('source', 30);
            $table->timestamp('confirmed_at')->nullable();
            $table->foreignUuid('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('discarded_at')->nullable();
            $table->foreignUuid('discarded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['entity_id', 'patient_id', 'status'], 'patient_medications_entity_patient_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_medications');
        Schema::dropIfExists('patient_allergies');

        Schema::table('patients', function (Blueprint $table) {
            $table->dropConstrainedForeignId('allergies_denied_by');
            $table->dropColumn('allergies_denied_at');
        });
    }
};
