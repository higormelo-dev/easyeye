<?php

namespace App\Services;

use App\DTOs\FeatureStatus;
use App\Enums\FeatureKey;
use App\Exceptions\FeatureDeniedException;
use App\Models\Subscription;

/**
 * Porta de entrada para verificação de features por plano.
 *
 * IMPORTANTE: registrar como singleton no AppServiceProvider para que
 * o cache por request funcione corretamente:
 *
 *   $this->app->singleton(FeatureGateService::class);
 *
 * ──────────────────────────────────────────────────────────────────────────
 * Tipos de feature:
 *
 *   Booleana  → has_ai_exam_assistant, has_ai_report_drafting
 *     can()  retorna true se o plano tem '1' para a feature.
 *
 *   Numérica  → max_users, max_patients, max_doctors
 *     can()  retorna true se a contagem atual < limite (0 = ilimitado).
 *     O uso é contado diretamente nas tabelas (users, patients, doctors).
 *
 *   Mensal    → ai_monthly_credits
 *     can()  retorna true se créditos usados no mês < limite (0 = ilimitado).
 *     Uso registrado em feature_usages; deve ser incrementado via increment().
 *
 * ──────────────────────────────────────────────────────────────────────────
 * Exemplos de uso:
 *
 *   // Verificação simples
 *   if (! $gate->can($entityId, FeatureKey::MaxUsers)) { abort(403); }
 *
 *   // Verificação com exception automática (recomendado em controllers)
 *   $gate->canOrFail($entityId, FeatureKey::HasAiExamAssistant);
 *
 *   // Status detalhado (para exibir na UI)
 *   $status = $gate->status($entityId, FeatureKey::MaxPatients);
 *   // $status->used, $status->limit, $status->remaining, $status->allowed
 *
 *   // Consumir crédito IA após usar a feature
 *   $gate->increment($entityId, FeatureKey::AiMonthlyCredits);
 */
class FeatureGateService
{
    /**
     * Cache de assinaturas por entityId para a duração do request.
     * Evita N+1 queries quando múltiplas features são verificadas.
     * Só funciona corretamente se o serviço for singleton.
     */
    private array $subscriptionCache = [];

    public function __construct(
        private readonly UsageMeterService $usageMeter,
    ) {}

    // ── Verificação de acesso ────────────────────────────────────────────────

    /**
     * Verifica se a empresa pode usar a feature.
     * Retorna false silenciosamente, sem exception.
     */
    public function can(string $entityId, FeatureKey $feature): bool
    {
        return $this->status($entityId, $feature)->allowed;
    }

    /**
     * Verifica o acesso e lança FeatureDeniedException se negado.
     * Recomendado em controllers — a exception se auto-renderiza como JSON ou redirect.
     *
     * @throws FeatureDeniedException
     */
    public function canOrFail(string $entityId, FeatureKey $feature): void
    {
        $status = $this->status($entityId, $feature);

        if (! $status->allowed) {
            throw new FeatureDeniedException($feature, $status);
        }
    }

    /**
     * Retorna o estado completo da feature para a empresa:
     * limite, uso atual, disponível, se está permitida.
     *
     * Seguro chamar múltiplas vezes — a assinatura é cacheada por request.
     */
    public function status(string $entityId, FeatureKey $feature): FeatureStatus
    {
        $subscription = $this->getSubscription($entityId);

        if (! $subscription) {
            return new FeatureStatus(
                feature:     $feature,
                allowed:     false,
                isBoolean:   $feature->isBoolean(),
                isUnlimited: false,
                limit:       0,
                used:        0,
                remaining:   0,
            );
        }

        $rawValue = $subscription->plan->featureValue($feature);

        // ApiMonthlyExamSends depende de HasApiIntegrator estar ativo.
        // Se o integrador estiver desativado no plano, bloqueia independente do valor configurado.
        if ($feature === FeatureKey::ApiMonthlyExamSends) {
            $apiEnabled = $subscription->plan->featureValue(FeatureKey::HasApiIntegrator) === '1';

            if (! $apiEnabled) {
                return new FeatureStatus(
                    feature:     $feature,
                    allowed:     false,
                    isBoolean:   false,
                    isUnlimited: false,
                    limit:       0,
                    used:        0,
                    remaining:   0,
                );
            }

            // API habilitada e limite não configurado = ilimitado
            if ($rawValue === null) {
                return new FeatureStatus(
                    feature:     $feature,
                    allowed:     true,
                    isBoolean:   false,
                    isUnlimited: true,
                    limit:       0,
                    used:        0,
                    remaining:   PHP_INT_MAX,
                );
            }
        }

        // Feature não configurada no plano = bloqueada
        if ($rawValue === null) {
            return new FeatureStatus(
                feature:     $feature,
                allowed:     false,
                isBoolean:   $feature->isBoolean(),
                isUnlimited: false,
                limit:       0,
                used:        0,
                remaining:   0,
            );
        }

        // ── Feature booleana ─────────────────────────────────────────────────
        if ($feature->isBoolean()) {
            return new FeatureStatus(
                feature:     $feature,
                allowed:     $rawValue === '1',
                isBoolean:   true,
                isUnlimited: false,
                limit:       0,
                used:        0,
                remaining:   -1,
            );
        }

        // ── Feature numérica / mensal ─────────────────────────────────────────
        $limit       = (int) $rawValue;
        $used        = $this->usageMeter->getCurrentUsage($entityId, $feature, $subscription->id);
        $isUnlimited = $limit === 0;
        $remaining   = $isUnlimited ? PHP_INT_MAX : max(0, $limit - $used);
        $allowed     = $isUnlimited || $used < $limit;

        return new FeatureStatus(
            feature:     $feature,
            allowed:     $allowed,
            isBoolean:   false,
            isUnlimited: $isUnlimited,
            limit:       $limit,
            used:        $used,
            remaining:   $remaining,
        );
    }

    // ── Consumo de créditos mensais ──────────────────────────────────────────

    /**
     * Incrementa o uso de uma feature mensal (ex.: ai_monthly_credits).
     * Para features não-mensais (counters de DB) a operação é ignorada.
     *
     * Chamar APÓS executar com sucesso a operação que consome o crédito.
     */
    public function increment(string $entityId, FeatureKey $feature, int $amount = 1): void
    {
        if (! $feature->isMonthlyReset()) {
            return;
        }

        $subscription = $this->getSubscription($entityId);

        if (! $subscription) {
            return;
        }

        $this->usageMeter->increment($entityId, $feature, $subscription->id, $amount);

        // Invalida cache: próxima verificação reflete o uso atualizado
        unset($this->subscriptionCache[$entityId]);
    }

    /**
     * Decrementa o uso de uma feature mensal.
     * Uso típico: desfazer um incremento em caso de erro ou rollback.
     */
    public function decrement(string $entityId, FeatureKey $feature, int $amount = 1): void
    {
        if (! $feature->isMonthlyReset()) {
            return;
        }

        $subscription = $this->getSubscription($entityId);

        if (! $subscription) {
            return;
        }

        $this->usageMeter->decrement($entityId, $feature, $subscription->id, $amount);

        unset($this->subscriptionCache[$entityId]);
    }

    /**
     * Verifica acesso E incrementa atomicamente.
     * Útil para operações de IA: uma só chamada faz o check + o consume.
     *
     * Retorna false se a feature estiver esgotada (sem exception).
     */
    public function canAndIncrement(string $entityId, FeatureKey $feature, int $amount = 1): bool
    {
        if (! $this->can($entityId, $feature)) {
            return false;
        }

        $this->increment($entityId, $feature, $amount);

        return true;
    }

    // ── Utilitários ──────────────────────────────────────────────────────────

    /**
     * Retorna o status de múltiplas features de uma vez.
     * Útil para popular dashboards, menus e páginas de configuração.
     *
     * @param  FeatureKey[]  $features
     * @return array<string, FeatureStatus>  chaveado por FeatureKey::value
     */
    public function statusBatch(string $entityId, array $features): array
    {
        $result = [];

        foreach ($features as $feature) {
            $result[$feature->value] = $this->status($entityId, $feature);
        }

        return $result;
    }

    /**
     * Invalida o cache da assinatura para a empresa.
     * Chamar após mudança de plano, renovação ou activação.
     */
    public function forgetCache(string $entityId): void
    {
        unset($this->subscriptionCache[$entityId]);
    }

    // ── Helpers privados ──────────────────────────────────────────────────────

    private function getSubscription(string $entityId): ?Subscription
    {
        if (! array_key_exists($entityId, $this->subscriptionCache)) {
            $this->subscriptionCache[$entityId] = Subscription::forEntity($entityId)
                ->accessible()
                ->with('plan.features')
                ->first();
        }

        return $this->subscriptionCache[$entityId];
    }
}
