<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contador de revisão do rascunho (Fase 5 / revisão adversarial Codex):
 * updated_at não serve de token de concorrência aqui porque esta tabela usa
 * timestamps(), e o precision padrão do Schema Builder do Laravel é 0 (sem
 * fração de segundo) — duas gravações dentro do mesmo segundo gravam o
 * MESMO updated_at, deixando uma correção salva nesse intervalo passar pela
 * checagem de revisão obsoleta sem ser detectada. Contador incrementado SOB
 * o lock em EyeImageReportDraftController::update() nunca tem esse problema.
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::table('eye_image_report_drafts', function (Blueprint $table) {
            $table->unsignedInteger('revision')->default(1)->after('image_report_payload');
        });
    }

    public function down(): void
    {
        Schema::table('eye_image_report_drafts', function (Blueprint $table) {
            $table->dropColumn('revision');
        });
    }
};
