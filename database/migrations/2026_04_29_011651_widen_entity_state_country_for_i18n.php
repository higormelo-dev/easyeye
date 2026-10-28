<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Alarga as colunas state/country da tabela entities para suportar
 * internacionalização — sistema é multi-idioma e clínicas estrangeiras
 * possuem nomes de estado/província/região mais longos que a sigla UF brasileira
 * (ex: "California", "Buenos Aires", "Madrid").
 *
 * country mantém ISO 3166-1 alpha-2 como padrão (BR/US/PT) mas a coluna
 * suporta nome completo p/ flexibilidade (ex: "Brazil" / "United States").
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::table('entities', function (Blueprint $table) {
            $table->string('state', 50)->nullable()->change();
            $table->string('country', 50)->nullable()->default('BR')->change();
        });
    }

    public function down(): void
    {
        Schema::table('entities', function (Blueprint $table) {
            $table->string('state', 5)->nullable()->change();
            $table->string('country', 5)->nullable()->default('BR')->change();
        });
    }
};
