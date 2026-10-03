<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Linhas importadas com aviso (ex.: plano não encontrado no convênio — o
 * paciente entra sem plano). Vão para o mesmo CSV baixável dos erros.
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::table('patient_imports', function (Blueprint $table) {
            $table->unsignedInteger('warning_rows')->default(0)->after('error_rows');
        });
    }

    public function down(): void
    {
        Schema::table('patient_imports', function (Blueprint $table) {
            $table->dropColumn('warning_rows');
        });
    }
};
