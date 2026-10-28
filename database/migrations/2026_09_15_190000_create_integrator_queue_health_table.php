<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Retrato do estado ATUAL da fila local do integrador (Rust, roda na
 * clínica) — não um histórico. O próprio integrador sincroniza a cada poucos
 * minutos e cada sincronização SUBSTITUI o retrato anterior (upsert por
 * integrator_id, nunca INSERT de nova linha) — dono do SaaS/suporte
 * conferem "o que tá acontecendo agora" antes de precisar acessar a máquina
 * remotamente, sem crescer sem limite conforme o volume de exames sobe: uma
 * linha por integrador, para sempre, com uma lista de problemas já limitada
 * no próprio cliente antes de sincronizar (ver
 * IntegratorUpdatesController-equivalente no lado Rust).
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::create('integrator_queue_health', function (Blueprint $table) {
            // PK = FK: física e semanticamente só existe UM retrato por
            // integrador — upsert nunca cria uma segunda linha.
            $table->foreignUuid('integrator_id')
                ->primary()
                ->constrained('entity_integrators')
                ->cascadeOnDelete();

            $table->unsignedInteger('pending_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->unsignedInteger('blocked_count')->default(0);
            $table->unsignedInteger('sent_last_24h_count')->default(0);

            // Itens bloqueados/com falha mais recentes — já truncado no
            // cliente (ver contrato da API) antes de chegar aqui. Nome de
            // arquivo vem redigido (hash + extensão, mesmo formato que
            // security::safe_path_summary já usa no lado Rust) — nunca o
            // nome cru do arquivo do paciente.
            $table->jsonb('problems')->default('[]');

            $table->timestampTz('synced_at');
            $table->timestamps();

            $table->index('synced_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integrator_queue_health');
    }
};
