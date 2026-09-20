<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::table('entities', function (Blueprint $table) {
            $table->string('cnes', 7)->nullable()->after('national_registration')
                ->comment('CNES (Cadastro Nacional de Estabelecimentos de Saúde) — exigido pelo XML TISS 4.03 (contratadoExecutante).');
        });
    }

    public function down(): void
    {
        Schema::table('entities', function (Blueprint $table) {
            $table->dropColumn('cnes');
        });
    }
};
