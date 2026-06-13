<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        // Flag opcional por entidade: exigir lançamento no caixa para concluir
        // o atendimento. Default false -> concluir consulta NÃO depende do caixa
        // (nem de permissão financeira). Clínicas podem optar por habilitar.
        Schema::table('entities', function (Blueprint $table) {
            $table->boolean('requires_cash_to_complete')->default(false)->after('schedule_interval');
        });

        // Natureza do lançamento de caixa: distingue recebimento de balcão
        // (particular/co-participação) de recebimento de guia de convênio,
        // evitando dupla contagem entre caixa e faturamento.
        Schema::table('financial_cash_entries', function (Blueprint $table) {
            $table->string('nature', 20)->default('general')->after('payment_method');
            $table->index(['entity_id', 'nature'], 'financial_cash_entity_nature_idx');
        });
    }

    public function down(): void
    {
        Schema::table('entities', function (Blueprint $table) {
            $table->dropColumn('requires_cash_to_complete');
        });

        Schema::table('financial_cash_entries', function (Blueprint $table) {
            $table->dropIndex('financial_cash_entity_nature_idx');
            $table->dropColumn('nature');
        });
    }
};
