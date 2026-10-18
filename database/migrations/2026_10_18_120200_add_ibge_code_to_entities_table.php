<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Código IBGE (7 dígitos) do município da clínica. A NFS-e do SaaS precisa dele
 * no endereço do tomador (tomador.endereco.codigo_municipio / codigo_municipio_tomador);
 * o cadastro só tinha `city`/`state` em texto. Preenchido pelo Manager em
 * Nota fiscal → "Clínicas sem IBGE", validado na Focus; sem backfill automático.
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::table('entities', function (Blueprint $table) {
            $table->string('ibge_code', 7)->nullable()->after('state');
        });
    }

    public function down(): void
    {
        Schema::table('entities', function (Blueprint $table) {
            $table->dropColumn('ibge_code');
        });
    }
};
