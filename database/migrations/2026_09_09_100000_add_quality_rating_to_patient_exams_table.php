<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Avaliação de qualidade da captura (0-5, null = não avaliado) — benchmark
 * contra concorrente (Ger Exames/iWayBrasil): sinaliza "essa imagem saiu
 * ruim, precisa refazer" direto no Gerenciador de Imagens.
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::table('patient_exams', function (Blueprint $table): void {
            $table->unsignedTinyInteger('quality_rating')->nullable()->after('laterality');
        });
    }

    public function down(): void
    {
        Schema::table('patient_exams', function (Blueprint $table): void {
            $table->dropColumn('quality_rating');
        });
    }
};
