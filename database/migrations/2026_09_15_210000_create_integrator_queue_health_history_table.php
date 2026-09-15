<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Histórico (log de verdade) das sincronizações de `integrator_queue_health`
 * — uma linha POR SINCRONIZAÇÃO (INSERT, não upsert), ao contrário da tabela
 * de retrato atual. Pedido original do usuário era literalmente "como log a
 * fila do integrador"; a tabela `integrator_queue_health` sozinha (retrato
 * único, sempre substituído) não guarda tendência nenhuma — só "agora".
 *
 * Sem `problems` aqui de propósito: é só a tendência dos contadores ao longo
 * do tempo (pending/failed/blocked/sent subindo ou descendo), não um replay
 * de cada arquivo travado em cada instante — isso a tabela de retrato atual
 * já cobre para "agora". Guardar a lista completa a cada sync duplicaria o
 * volume de JSON por ~2000 linhas/integrador/semana (ver cálculo abaixo)
 * para um ganho que o suporte não pediu.
 *
 * Prazo de 7 dias (pedido do usuário): expurgo diário via
 * `queue-health:prune-history` (routes/console.php), não `ON DELETE`/TTL do
 * Postgres. Cálculo de volume que motivou aceitar isso como seguro: sync a
 * cada 5min (`QUEUE_HEALTH_SYNC_INTERVAL` no integrador) × 288/dia × 7 dias
 * = 2016 linhas/integrador em regime permanente. Para uma frota de, digamos,
 * 500 integradores instalados, isso é ~1M linhas em regime permanente
 * (cresce até o 7º dia, depois o expurgo diário mantém estável) — tamanho
 * trivial para Postgres com o índice composto abaixo, bem longe de
 * "inchar o banco".
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::create('integrator_queue_health_history', function (Blueprint $table) {
            $table->id();

            $table->foreignUuid('integrator_id')
                ->constrained('entity_integrators')
                ->cascadeOnDelete();

            $table->unsignedInteger('pending_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->unsignedInteger('blocked_count')->default(0);
            $table->unsignedInteger('sent_last_24h_count')->default(0);

            $table->timestampTz('synced_at');

            // Consulta de tendência é sempre "este integrador, últimos N
            // dias/pontos" — composto cobre tanto o expurgo diário
            // (WHERE synced_at < cutoff) quanto a leitura da tela do Manager
            // (WHERE integrator_id = ? ORDER BY synced_at DESC).
            $table->index(['integrator_id', 'synced_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integrator_queue_health_history');
    }
};
