<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mesclar/Dividir exame (benchmark 09/09/2026): o agrupamento visual do
 * Gerenciador de Imagens hoje é 100% derivado (data|equipamento|tipo — ver
 * Index.vue::groupedExams) — não existe entidade "sessão de exame" no banco.
 * Mutar exam_performed_at/equipment_id/exam_id pra forçar merge/split seria
 * errado (mexe em dado factual/auditável de captura). `exam_session_id`,
 * quando presente, OVERRIDE a chave derivada — imagens com o mesmo valor
 * agrupam juntas independente de data/equipamento/tipo reais. Null (padrão)
 * = comportamento 100% inalterado (agrupamento derivado de sempre).
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::table('patient_exams', function (Blueprint $table): void {
            $table->uuid('exam_session_id')->nullable()->after('quality_rating')->index();
        });
    }

    public function down(): void
    {
        Schema::table('patient_exams', function (Blueprint $table): void {
            $table->dropColumn('exam_session_id');
        });
    }
};
