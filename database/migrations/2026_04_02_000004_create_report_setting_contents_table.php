<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Tipos de documento suportados:
 *   prescription  — Receituário médico
 *   procedure     — Solicitação de procedimento / exame
 *   certificate   — Atestado médico
 *   referral      — Encaminhamento
 *   report        — Laudo / relatório geral
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::create('report_setting_contents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('report_setting_id')
                ->constrained('report_settings')
                ->cascadeOnDelete();
            $table->string('type', 30)->default('prescription');  // enum via app layer
            $table->string('label')->nullable();                   // ex: "Receituário Simples"
            $table->longText('content');                           // HTML com {{VARIÁVEIS}}
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_setting_contents');
    }
};
