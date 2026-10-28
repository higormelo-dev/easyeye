<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Estado do circuit breaker por provider LLM, opcionalmente escopado por entity.
 *
 * Quando um provider falha N vezes (failure_threshold), o circuito abre por
 * `open_until`. Durante esse período o AiOrchestrator pula para o fallback
 * sem tentar o provider quebrado. Após o cooldown, o estado volta a 'closed'
 * automaticamente (verificação por timestamp).
 *
 * Espelha o padrão usado em gateway_circuit_breakers para os gateways de billing.
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::create('ai_circuit_breakers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('provider_code', 20)->comment('openai|anthropic|gemini');
            $table->foreignUuid('entity_id')
                ->nullable()
                ->constrained('entities')
                ->cascadeOnDelete()
                ->comment('NULL = breaker global; preenchido = breaker por tenant.');
            $table->string('state', 16)->default('closed')->comment('closed|open|half_open');
            $table->unsignedInteger('failure_count')->default(0);
            $table->unsignedInteger('failure_threshold')->default(5);
            $table->string('last_trigger_type', 60)->nullable();
            $table->timestamp('last_failure_at')->nullable();
            $table->timestamp('open_until')->nullable();
            $table->timestamps();

            $table->unique(['provider_code', 'entity_id'], 'ai_breakers_provider_entity_unique');
            $table->index(['state', 'open_until'], 'ai_breakers_state_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_circuit_breakers');
    }
};
