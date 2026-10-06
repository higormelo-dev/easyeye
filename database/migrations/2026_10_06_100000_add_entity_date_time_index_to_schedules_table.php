<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dashboard v2: as consultas do painel (agenda de hoje, confirmações de hoje e
 * amanhã, atendimentos por médico, série dos últimos 30 dias, comparativo do
 * mês) filtram por clínica + intervalo de data/hora. A tabela só tinha o
 * índice (doctor_id, date_time) da trava de horário — a visão da clínica
 * inteira varria a tabela (e o painel faz polling a cada 30 s).
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::table('schedules', function (Blueprint $table) {
            $table->index(['entity_id', 'date_time'], 'schedules_entity_date_time_idx');
        });
    }

    public function down(): void
    {
        Schema::table('schedules', function (Blueprint $table) {
            $table->dropIndex('schedules_entity_date_time_idx');
        });
    }
};
