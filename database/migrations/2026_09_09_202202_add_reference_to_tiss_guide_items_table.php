<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vínculo de AUDITORIA (não-XML) entre uma linha de guia TISS e o evento
 * clínico/estoque que a originou — ex.: `reference_type =
 * App\Models\MedicalRecordProcedure` aponta pro procedimento executado que
 * consumiu o material sendo faturado nesta linha.
 *
 * Escopo DELIBERADAMENTE limitado (decisão da Fase 3): só reconciliação
 * interna ("esta linha faturada corresponde a este consumo real"). NÃO
 * adiciona os campos de OPM exigidos pela ANS (registro ANVISA, fabricante,
 * `opmeUtilizada` no XML) — isso exige a especificação/XSD oficial da ANS
 * em mãos; implementar de palpite arrisca gerar XML que a operadora rejeita/
 * glosa. Nenhuma mudança nos builders de XML (V202601/V202603) — ver
 * App\Domains\Tiss\Xml\Builders.
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::table('tiss_guide_items', function (Blueprint $table) {
            $table->string('reference_type')->nullable()->after('metadata');
            $table->uuid('reference_id')->nullable()->after('reference_type');

            $table->index(['reference_type', 'reference_id']);
        });
    }

    public function down(): void
    {
        Schema::table('tiss_guide_items', function (Blueprint $table) {
            $table->dropIndex(['reference_type', 'reference_id']);
            $table->dropColumn(['reference_type', 'reference_id']);
        });
    }
};
