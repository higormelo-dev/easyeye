<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contador de revisão do conteúdo clínico do prontuário (achado via
 * revisão adversarial Codex): o endpoint de assinatura não exigia a
 * revisão que o médico de fato revisou — outra aba/usuário podia editar o
 * conteúdo entre o médico abrir a tela e clicar em "Assinar", e a
 * assinatura seguia em cima do que estava no banco na hora do clique, não
 * do que o médico viu. updated_at não serve de token aqui pelo mesmo
 * motivo de eye_image_report_drafts.revision (migration
 * 2026_10_21_000100): medical_records usa timestamps() com precision 0
 * (padrão do Schema Builder, sem fração de segundo) — duas gravações no
 * mesmo segundo gravam o MESMO updated_at.
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::table('medical_records', function (Blueprint $table) {
            $table->unsignedInteger('clinical_revision')->default(1)->after('follow_up_days');
        });
    }

    public function down(): void
    {
        Schema::table('medical_records', function (Blueprint $table) {
            $table->dropColumn('clinical_revision');
        });
    }
};
