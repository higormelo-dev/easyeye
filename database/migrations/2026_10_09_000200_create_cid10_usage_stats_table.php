<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Uso agregado de cada código CID-10 pelas clínicas (Manager → CID-10):
 * quantos prontuários e exames citam o código, em quantas clínicas, e
 * quantos vínculos de clínica (mais usados / diagnósticos próprios) apontam
 * para ele. Só contagens — nenhum dado de paciente.
 *
 * Recalculada por App\Services\Cid10\Cid10UsageStats (uma consulta
 * agregada, no máximo a cada 15 min) — a tela nunca varre os prontuários
 * por página ou por linha.
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::create('cid10_usage_stats', function (Blueprint $table) {
            $table->string('code', 10)->primary();
            $table->unsignedInteger('records_count')->default(0);
            $table->unsignedInteger('exams_count')->default(0);
            $table->unsignedInteger('clinics_count')->default(0);
            $table->unsignedInteger('links_count')->default(0);
            // records + exams (ordenação "uso pelas clínicas").
            $table->unsignedInteger('total_count')->default(0);

            $table->index('total_count');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cid10_usage_stats');
    }
};
