<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mesclar cadastro de paciente (dedup): o cadastro de ORIGEM é excluído (soft
 * delete) e aponta para o de DESTINO por `merged_into_id`. É o que impede
 * restaurar o duplicado e o que mostra "mesclado em PAC-…" na gaveta. Fica
 * nulo para todo cadastro que não foi mesclado.
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->foreignUuid('merged_into_id')->nullable()->after('active')
                ->constrained('patients')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->dropConstrainedForeignId('merged_into_id');
        });
    }
};
