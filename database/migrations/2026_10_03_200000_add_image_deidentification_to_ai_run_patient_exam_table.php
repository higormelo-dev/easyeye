<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Auditoria (LGPD) do que saiu de cada imagem para a IA: chave do layout cuja
 * tarja de dados do paciente foi aplicada, ou "unrecognized_layout" quando a
 * imagem ficou fora (layout não reconhecido). Null: execução anterior a esta
 * regra ou ainda não executada.
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::table('ai_run_patient_exam', function (Blueprint $table) {
            $table->string('image_deidentification', 60)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('ai_run_patient_exam', function (Blueprint $table) {
            $table->dropColumn('image_deidentification');
        });
    }
};
