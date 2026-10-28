<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Auditoria (LGPD) do que saiu para o provedor em cada execução de IA:
 * impressão digital (SHA-256) das instruções enviadas, categorias de dados,
 * chaves do contexto e nº de imagens — nunca o conteúdo. Null: execução
 * anterior a esta regra ou que não chegou a enviar nada.
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::table('ai_runs', function (Blueprint $table) {
            $table->json('dispatch_audit')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('ai_runs', function (Blueprint $table) {
            $table->dropColumn('dispatch_audit');
        });
    }
};
