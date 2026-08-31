<?php

declare(strict_types=1);

namespace App\Http\Controllers\Manager;

use App\Domains\AI\Models\AiModelPrice;
use App\Domains\AI\Services\{AiPricingService, AiProviderManager, AiProviderSettings};
use App\Domains\AI\Support\ProviderErrorSanitizer;
use App\DTOs\AI\AiRequestData;
use App\Enums\AI\{AiProvider, AiRiskLevel, AiRunMode};
use App\Enums\EntityGate;
use App\Http\Controllers\Controller;
use App\Models\Entity;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\{Inertia, Response as InertiaResponse};
use Ramsey\Uuid\Uuid;
use Throwable;

/**
 * Configuração global (dono do SaaS) de QUAIS provedores de IA o sistema pode
 * usar e em que ORDEM de prioridade. A partir do conjunto ativo derivam-se os
 * papéis (gerador/revisor/adjudicador) e os modos disponíveis (economia/
 * validado/consenso). Persistido em system_settings via AiProviderSettings.
 */
class AiProvidersController extends Controller
{
    public function __construct(
        private readonly AiProviderSettings $providerSettings,
        private readonly AiPricingService $pricingService,
        private readonly AuditLogger $audit,
    ) {
    }

    public function index(): InertiaResponse
    {
        $this->authorizeSaas();

        return Inertia::render('Panel/Manager/AiProviders/Index', [
            'providers'    => $this->providerCards(),
            'modelOptions' => $this->modelOptions(),
            'modelPrices'  => AiModelPrice::query()->orderBy('provider')->orderBy('model')->get()
                ->map(fn (AiModelPrice $p) => [
                    'id'                        => (string) $p->id,
                    'provider'                  => $p->provider->value,
                    'provider_label'            => $p->provider->label(),
                    'model'                     => $p->model,
                    'input_usd_per_million'     => (float) $p->input_usd_per_million,
                    'output_usd_per_million'    => (float) $p->output_usd_per_million,
                    'reasoning_usd_per_million' => $p->reasoning_usd_per_million !== null ? (float) $p->reasoning_usd_per_million : null,
                    'active'                    => (bool) $p->active,
                    'effective_from'            => $p->effective_from?->format('d/m/Y'),
                ])->all(),
            'roles'        => $this->providerSettings->roleAssignments(),
            'modes'        => $this->modeCards(),
            'enabledCount' => $this->providerSettings->count(),
            't'            => trans('manager_ai'),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $this->authorizeSaas();

        $allCodes = array_map(static fn (AiProvider $p) => $p->value, AiProvider::cases());

        // Payload novo: papéis explícitos {primary, reviewer, adjudicator}.
        // Retrocompat: payload legado {providers: [...]} vira papéis por índice.
        if ($request->has('providers') && ! $request->has('primary')) {
            $legacy = $request->validate([
                'providers'   => ['present', 'array'],
                'providers.*' => ['string', 'distinct', Rule::in($allCodes)],
            ]);
            $list  = array_values($legacy['providers']);
            $roles = [
                'primary'     => $list[0] ?? null,
                'reviewer'    => $list[1] ?? null,
                'adjudicator' => $list[2] ?? null,
            ];
        } else {
            $validated = $request->validate([
                'primary'     => ['required', 'string', Rule::in($allCodes)],
                'reviewer'    => ['nullable', 'string', 'different:primary', Rule::in($allCodes)],
                'adjudicator' => ['nullable', 'string', 'different:primary', 'different:reviewer', Rule::in($allCodes)],
                'models'      => ['sometimes', 'array'],
                'models.*'    => ['nullable', 'string', 'max:120'],
            ]);
            $roles = [
                'primary'     => $validated['primary'],
                'reviewer'    => $validated['reviewer'] ?? null,
                'adjudicator' => $validated['adjudicator'] ?? null,
            ];
        }

        if (($roles['primary'] ?? null) === null) {
            return response()->json(['message' => __('manager_ai.error_empty')], 422);
        }

        // Árbitro sem revisor não faz sentido (consenso exige os 3 papéis).
        if ($roles['adjudicator'] !== null && $roles['reviewer'] === null) {
            return response()->json(['message' => __('manager_ai.error_adjudicator_without_reviewer')], 422);
        }

        // Não permite atribuir provedor sem credencial/modelo configurados.
        $codes   = array_values(array_filter($roles));
        $missing = array_values(array_filter(
            $codes,
            fn (string $code) => ! $this->providerSettings->isConfigured($code),
        ));

        if ($missing !== []) {
            $labels = implode(', ', array_map(
                static fn (string $c) => AiProvider::from($c)->label(),
                $missing,
            ));

            return response()->json([
                'message' => __('manager_ai.error_unconfigured', ['providers' => $labels]),
            ], 422);
        }

        // Modelos escolhidos no painel: só aceita modelo COM PREÇO ativo do
        // próprio provedor — impede o admin de apontar para um modelo cuja
        // execução falharia depois de gastar no provedor.
        $models = [];

        foreach ((array) $request->input('models', []) as $providerCode => $model) {
            $providerCode = is_string($providerCode) ? mb_strtolower(trim($providerCode)) : '';

            if (! in_array($providerCode, $allCodes, true)) {
                continue;
            }

            $model = is_string($model) ? trim($model) : '';

            if ($model === '') {
                $models[$providerCode] = null; // volta ao fallback do env

                continue;
            }

            $valid = in_array($model, $this->modelOptions()[$providerCode] ?? [], true);

            if (! $valid) {
                return response()->json([
                    'message' => __('manager_ai.error_model_without_price', [
                        'provider' => AiProvider::from($providerCode)->label(),
                        'model'    => $model,
                    ]),
                ], 422);
            }

            $models[$providerCode] = $model;
        }

        $old       = $this->providerSettings->roleAssignments();
        $oldModels = $this->providerSettings->panelModels();

        $this->providerSettings->setRoleAssignments($roles);

        if ($models !== []) {
            $this->providerSettings->setModels($models);
        }

        $this->audit->recordAdminAction(
            event: 'manager.ai_providers.update',
            targetEntityId: null,
            targetUserId: null,
            auditableType: 'system_setting',
            // auditable_id é uuid; deriva um UUID estável da chave do setting.
            auditableId: Uuid::uuid5(Uuid::NAMESPACE_OID, AiProviderSettings::SETTING_KEY)->toString(),
            reason: __('manager_ai.audit_reason'),
            newValues: ['roles' => $roles, 'models' => $this->providerSettings->panelModels()],
            request: $request,
            oldValues: ['roles' => $old, 'models' => $oldModels],
        );

        return response()->json([
            'message'      => __('manager_ai.saved'),
            'providers'    => $this->providerCards(),
            'roles'        => $this->providerSettings->roleAssignments(),
            'modelOptions' => $this->modelOptions(),
            'modes'        => $this->modeCards(),
            'enabledCount' => $this->providerSettings->count(),
        ]);
    }

    /**
     * Modelos elegíveis por provedor = os que têm preço ATIVO cadastrado em
     * ai_model_prices (garantia de liquidação de créditos).
     *
     * @return array<string, list<string>>
     */
    private function modelOptions(): array
    {
        $now = now();

        return AiModelPrice::query()
            ->where('active', true)
            ->where('effective_from', '<=', $now)
            ->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>', $now))
            ->orderBy('model')
            ->get(['provider', 'model'])
            ->groupBy('provider')
            ->map(fn ($rows) => $rows->pluck('model')->unique()->values()->all())
            ->all();
    }

    /**
     * Teste de conexão REAL com um provedor (chamada mínima, ~centavos).
     * Dá ao administrador leigo a prova de que a credencial funciona ANTES de
     * colocar o provedor em produção. Erro volta sanitizado (sem vazar chave).
     */
    public function test(Request $request, AiProviderManager $providerManager): JsonResponse
    {
        $this->authorizeSaas();

        $allCodes = array_map(static fn (AiProvider $p) => $p->value, AiProvider::cases());

        $validated = $request->validate([
            'provider' => ['required', 'string', Rule::in($allCodes)],
        ]);

        $code = $validated['provider'];

        if (! $this->providerSettings->isConfigured($code)) {
            return response()->json([
                'ok'      => false,
                'message' => __('manager_ai.test_unconfigured'),
            ], 422);
        }

        $startedAt = microtime(true);

        try {
            $provider = $providerManager->get($code);

            $provider->generate(new AiRequestData(
                workflow: 'connection_test',
                mode: AiRunMode::Economy,
                userPrompt: 'Responda somente: ok',
                systemPrompt: 'Você é um verificador de conectividade. Responda somente "ok".',
                riskLevel: AiRiskLevel::Low,
                maxOutputTokens: 16, // mínimo aceito pela OpenAI (>= 16); ok p/ Anthropic/Gemini
            ));

            return response()->json([
                'ok'         => true,
                'message'    => __('manager_ai.test_ok'),
                'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'ok'      => false,
                'message' => ProviderErrorSanitizer::sanitize($e->getMessage(), __('manager_ai.test_failed')),
            ], 422);
        }
    }

    /**
     * Lista de TODOS os provedores conhecidos com estado atual (ativo, ordem,
     * configurado, modelo). A ordem reflete a prioridade salva; provedores
     * inativos vão para o fim.
     *
     * @return list<array<string, mixed>>
     */
    private function providerCards(): array
    {
        $enabled = $this->providerSettings->enabledCodes();
        $order   = array_flip($enabled);

        $cards = array_map(function (AiProvider $p) use ($order): array {
            $code = $p->value;

            $model = $this->providerSettings->model($code);

            return [
                'code'       => $code,
                'label'      => $p->label(),
                'enabled'    => isset($order[$code]),
                'order'      => $order[$code] ?? null,
                'configured' => $this->providerSettings->isConfigured($code),
                'model'      => $model,
                // Modelo sem preço cadastrado = run falharia após gastar no
                // provedor; o painel avisa ANTES de o admin atribuir o papel.
                'price_ok' => $model !== null && $this->pricingService->hasPriceFor($p, $model),
            ];
        }, AiProvider::cases());

        // Ativos primeiro (na ordem de prioridade), depois os inativos.
        usort($cards, static function (array $a, array $b): int {
            if ($a['enabled'] !== $b['enabled']) {
                return $a['enabled'] ? -1 : 1;
            }

            return ($a['order'] ?? PHP_INT_MAX) <=> ($b['order'] ?? PHP_INT_MAX);
        });

        return $cards;
    }

    /**
     * Modos com indicação de disponibilidade conforme o nº de provedores ativos.
     *
     * @return list<array<string, mixed>>
     */
    private function modeCards(): array
    {
        $labels = [
            AiRunMode::Economy->value   => __('manager_ai.mode_economy'),
            AiRunMode::Validated->value => __('manager_ai.mode_validated'),
            AiRunMode::Consensus->value => __('manager_ai.mode_consensus'),
        ];

        return array_map(fn (AiRunMode $mode) => [
            'value'     => $mode->value,
            'label'     => $labels[$mode->value],
            'available' => $this->providerSettings->isModeAvailable($mode),
            'needs'     => match ($mode) {
                AiRunMode::Economy   => 1,
                AiRunMode::Validated => 2,
                AiRunMode::Consensus => 3,
            },
        ], AiRunMode::cases());
    }

    private function authorizeSaas(): void
    {
        $entity = Entity::query()->findOrFail(session('selected_entity_id'));
        Gate::authorize(EntityGate::SaasAdminPanel->value, $entity);
    }
}
