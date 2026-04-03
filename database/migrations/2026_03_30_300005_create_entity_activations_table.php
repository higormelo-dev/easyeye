<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registro dos marcos de ativação de cada clínica durante o trial.
 *
 * Ativação = profundidade de uso do produto no período de trial.
 * Alta ativação = alta retenção = maior conversão = menor CAC efetivo.
 * Dados usados para: nudges in-app, alertas de trial sem engajamento,
 * e análise de cohort de conversão no painel do gestor.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('entity_activations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities')->cascadeOnDelete();

            $table->string('step'); // ActivationStep enum

            $table->timestamp('completed_at')->useCurrent();

            // Cada etapa é registrada apenas uma vez por clínica
            $table->unique(['entity_id', 'step']);

            $table->index(['entity_id', 'completed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entity_activations');
    }
};
