<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Barra de progresso da importação do catálogo de medicamentos (mesmo
 * padrão das importações de pacientes/médicos: processed_rows de
 * total_rows, consultado a cada 2 s pela tela) + fase legível.
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::table('medicine_imports', function (Blueprint $table) {
            $table->unsignedInteger('processed_rows')->default(0)->after('total_rows');
            // reading | processing | deactivating (null quando terminou)
            $table->string('phase', 20)->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('medicine_imports', function (Blueprint $table) {
            $table->dropColumn(['processed_rows', 'phase']);
        });
    }
};
