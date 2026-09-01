<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Derivados de visualização do exame, gerados pelo job
 * GenerateExamDerivatives após o upload (integrador ou import manual):
 * - display_archive: JPEG em alta resolução (lado maior <= 2560px) usado no
 *   viewer do Gerenciador de Imagens — inclusive a 1ª página de laudos PDF
 *   quando a extensão Imagick está disponível no servidor;
 * - thumb_archive: miniatura JPEG (lado maior <= 400px) usada no grid.
 *
 * O arquivo original em `archive` permanece intocado (fonte de verdade).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patient_exams', function (Blueprint $table): void {
            $table->string('display_archive')->nullable()->after('archive');
            $table->string('thumb_archive')->nullable()->after('display_archive');
        });
    }

    public function down(): void
    {
        Schema::table('patient_exams', function (Blueprint $table): void {
            $table->dropColumn(['display_archive', 'thumb_archive']);
        });
    }
};
