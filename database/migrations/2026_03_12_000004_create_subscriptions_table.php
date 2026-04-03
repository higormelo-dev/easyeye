<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Regra de negócio: uma empresa só pode ter UMA assinatura ativa (trial|active)
 * por vez. MySQL não suporta partial unique indexes, portanto a regra é
 * garantida pela camada de aplicação (RegisterAction, SubscriptionService).
 * O índice (entity_id, status) torna a verificação O(log n).
 *
 * plan_id usa restrictOnDelete: planos com assinatura não podem ser hard-deleted.
 * Use Plan::softDelete() e o relacionamento ->withTrashed() no model.
 *
 * Status — App\Enums\SubscriptionStatus:
 *   trial     : avaliação gratuita vigente
 *   active    : assinatura paga vigente
 *   past_due  : pagamento atrasado; acesso mantido brevemente
 *   expired   : vencida; acesso bloqueado (ou em grace period)
 *   cancelled : cancelada pelo cliente ou admin
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('entity_id')
                ->constrained('entities')
                ->cascadeOnDelete();

            $table->foreignUuid('plan_id')
                ->constrained('plans')
                ->restrictOnDelete();

            $table->string('status', 20)
                ->comment('trial | active | past_due | expired | cancelled');

            // ── Período de trial ──────────────────────────────────────────────
            $table->timestamp('trial_starts_at')->nullable()
                ->comment('Início do trial — auditoria e cálculo de duração efetiva.');
            $table->timestamp('trial_ends_at')->nullable()
                ->comment('Fim do trial. Job monitora e altera status para expired.');

            // ── Período de billing ────────────────────────────────────────────
            $table->timestamp('starts_at')
                ->comment('Início da assinatura (pode coincidir com trial_starts_at).');
            $table->timestamp('ends_at')->nullable()
                ->comment('Vencimento. NULL = lifetime. Job monitora expiração.');

            // ── Período de graça ──────────────────────────────────────────────
            $table->timestamp('grace_period_ends_at')->nullable()
                ->comment('Acesso mantido até esta data após expiração.');

            // ── Ciclo de vida ─────────────────────────────────────────────────
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancelled_reason', 255)->nullable()
                ->comment('Motivo do cancelamento — análise de churn.');
            $table->timestamp('renewed_at')->nullable()
                ->comment('Timestamp da última renovação — LTV e relatórios.');

            // ── Gateway de pagamento (futura integração) ──────────────────────
            $table->string('gateway', 50)->nullable()
                ->comment('stripe | pagseguro | asaas | mercadopago');
            $table->string('gateway_subscription_id', 255)->nullable()
                ->comment('ID da assinatura no gateway. Lookup em webhooks.');
            $table->string('gateway_customer_id', 255)->nullable()
                ->comment('ID do cliente no gateway — reutilizável em renovações.');
            $table->json('gateway_payload')->nullable()
                ->comment('Payload bruto do último evento do gateway.');

            $table->softDeletes();
            $table->timestamps();

            // ── Índices ───────────────────────────────────────────────────────

            // Padrão principal: "qual é a assinatura ativa desta empresa?"
            $table->index(['entity_id', 'status']);

            // Verificação completa de acesso: entity + status + vencimento
            $table->index(['entity_id', 'status', 'ends_at'], 'sub_entity_status_ends');

            // Job: encontrar trials prestes a expirar
            $table->index(['status', 'trial_ends_at'], 'sub_status_trial_ends');

            // Job: encontrar assinaturas prestes a expirar
            $table->index(['status', 'ends_at'], 'sub_status_ends');

            // Lookup em webhooks do gateway
            $table->index('gateway_subscription_id', 'sub_gateway_sub_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
