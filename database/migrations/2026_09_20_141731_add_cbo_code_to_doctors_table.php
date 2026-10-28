<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::table('doctors', function (Blueprint $table) {
            $table->string('cbo_code', 10)->nullable()->after('record_specialty')
                ->comment('CBO (Classificação Brasileira de Ocupações) — exigido pelo XML TISS 4.03 (profissionalExecutante/Solicitante). Ex.: 225265 = Médico oftalmologista.');
        });
    }

    public function down(): void
    {
        Schema::table('doctors', function (Blueprint $table) {
            $table->dropColumn('cbo_code');
        });
    }
};
