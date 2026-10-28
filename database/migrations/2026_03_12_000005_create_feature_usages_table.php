<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Correção crítica no UNIQUE vs schema anterior:
 *
 * ANTES:  UNIQUE(entity_id, subscription_id, feature, period)
 * BUG:    Ao renovar a assinatura (novo subscription_id), o mesmo
 *         entity+feature+period poderia gerar linha duplicada,
 *         forçando reset indevido do uso mensal.
 *
 * DEPOIS: UNIQUE(entity_id, feature, period)
 * OK:     Uso registrado por empresa+feature+mês, independente do ciclo
 *         de renovação. subscription_id é FK de referência (qual assinatura
 *         registrou o incremento mais recente), não parte da chave.
 *
 * period: 'YYYY-MM' para features com reset mensal (ex: ai_monthly_credits).
 *         'lifetime' para contadores sem reset (ex: total de pacientes).
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::create('feature_usages', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('entity_id')
                ->constrained('entities')
                ->cascadeOnDelete();

            // Referência à assinatura mais recente que registrou uso.
            $table->foreignUuid('subscription_id')
                ->constrained('subscriptions')
                ->cascadeOnDelete();

            $table->string('feature', 60)
                ->comment('App\Enums\FeatureKey value: max_users, ai_monthly_credits, ...');

            $table->string('period', 7)
                ->comment("'YYYY-MM' para reset mensal. 'lifetime' para contadores permanentes.");

            $table->unsignedInteger('used')->default(0)
                ->comment('Quantidade consumida no período.');

            $table->unsignedInteger('limit_snapshot')->nullable()
                ->comment(
                    'Limite vigente no momento do registro. '
                    . '0 = ilimitado. Garante auditoria correta mesmo se o plano mudar.',
                );

            $table->timestamp('last_used_at')->nullable()
                ->comment('Timestamp do último incremento de used.');

            $table->timestamps();

            // ── Constraints ───────────────────────────────────────────────────
            $table->unique(['entity_id', 'feature', 'period'], 'fu_entity_feature_period_unique');

            // ── Índices ───────────────────────────────────────────────────────
            // Padrão principal: consumo da empresa em um período
            $table->index(['entity_id', 'feature', 'period'], 'fu_entity_feature_period');

            // Relatórios por assinatura (usage billing)
            $table->index(['subscription_id', 'feature'], 'fu_subscription_feature');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feature_usages');
    }
};
