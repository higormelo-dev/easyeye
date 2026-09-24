<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Estrela de prioridade/triagem do paciente (0-5, null = sem prioridade) —
 * benchmark contra concorrente (Ger Exames/iWayBrasil): sinaliza urgência
 * na fila do Gerenciador de Imagens. Mesmo tipo de coluna de
 * patient_exams.quality_rating (2026_09_09_100000), mas em patients — é
 * triagem do PACIENTE, não nota de qualidade de uma captura específica.
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::table('patients', function (Blueprint $table): void {
            $table->unsignedTinyInteger('priority_rating')->nullable()->after('active');
        });
    }

    public function down(): void
    {
        Schema::table('patients', function (Blueprint $table): void {
            $table->dropColumn('priority_rating');
        });
    }
};
