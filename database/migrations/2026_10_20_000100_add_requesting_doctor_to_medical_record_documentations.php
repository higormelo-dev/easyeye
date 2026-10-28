<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Médico solicitante do laudo de imagem — Fase 5 ("Laudos de exames").
 *
 * Texto livre (nome + e-mail, não necessariamente médico cadastrado no
 * EasyEye — pode ser de fora da clínica). `requesting_doctor_sent_at`
 * controla se já foi enviado (e quando), pro botão de reenviar.
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::table('medical_record_documentations', function (Blueprint $table) {
            $table->string('requesting_doctor_name')->nullable();
            $table->string('requesting_doctor_email')->nullable();
            $table->timestamp('requesting_doctor_sent_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('medical_record_documentations', function (Blueprint $table) {
            $table->dropColumn(['requesting_doctor_name', 'requesting_doctor_email', 'requesting_doctor_sent_at']);
        });
    }
};
