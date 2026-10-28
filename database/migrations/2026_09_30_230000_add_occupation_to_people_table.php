<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Profissão do paciente (aba Pessoal do cadastro — Pacientes e Agenda).
 * Opcional; fica no cadastro de pessoa da clínica, como os demais dados
 * pessoais. Coluna nullable: nenhuma linha existente muda.
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->string('occupation', 120)->nullable()->after('father_name');
        });
    }

    public function down(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->dropColumn('occupation');
        });
    }
};
