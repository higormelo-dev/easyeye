<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Canal de comando do backend pro desktop (Rust) — até aqui toda
 * comunicação da API de integradores era iniciada pelo cliente (signin,
 * uploads, queue-health); esta é a primeira vez que o SaaS pode pedir pro
 * desktop fazer algo (ex.: "resync agora", diagnóstico) sem esperar o
 * próximo ciclo natural do loop do integrador.
 *
 * Modelo de entrega: at-least-once, execute-então-ack. Esta tabela É o
 * estado da fila de comandos — o cliente Rust não mantém fila própria
 * disso, só um `Instant` em memória do último poll (ver
 * `service::maybe_poll_commands` no integrator). Cada poll busca os
 * `pending` deste integrador; o ack marca `completed`/`failed`. Sem
 * `CHECK` de `type`/`status`: validação fica no FormRequest/controller —
 * Postgres não permite `ALTER` de `CHECK` existente sem recriar a tabela,
 * e tipo de comando novo não deve exigir migration.
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::create('integrator_commands', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('integrator_id')
                ->constrained('entity_integrators')
                ->cascadeOnDelete();

            $table->string('type');
            $table->jsonb('payload')->default('{}');
            $table->string('status')->default('pending');
            $table->jsonb('result')->nullable();
            $table->timestampTz('acked_at')->nullable();

            $table->timestamps();

            // Consultado a cada poll do desktop: WHERE integrator_id = ? AND
            // status = 'pending' ORDER BY created_at — mesmo racional do
            // índice composto de `integrator_queue_health_history`.
            $table->index(['integrator_id', 'status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integrator_commands');
    }
};
