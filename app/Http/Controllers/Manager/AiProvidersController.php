<?php

declare(strict_types=1);

namespace App\Http\Controllers\Manager;

use App\Domains\AI\Models\{AiCatalogSync, AiModelPrice};
use App\Domains\AI\Services\{AiPricingService, AiProviderManager, AiProviderSettings};
use App\Domains\AI\Support\{ProviderDataPolicy, ProviderErrorSanitizer};
use App\DTOs\AI\AiRequestData;
use App\Enums\AI\{AiProvider, AiRiskLevel, AiRunMode};
use App\Enums\{EntityGate, ImportStatus};
use App\Http\Controllers\Controller;
use App\Models\{Entity, User};
use App\Services\Audit\AuditLogger;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Carbon;
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
    /** Ordenação aceita na tabela de modelos (whitelist — vai direto pro ORDER BY). */
    private const PRICE_SORTS = [
        'provider' => 'provider',
        'model'    => 'model',
        'input'    => 'input_usd_per_million',
        'output'   => 'output_usd_per_million',
        'source'   => 'source',
        'active'   => 'active',
    ];

    /** Filtro "Situação" da tabela de modelos. */
    private const PRICE_FLAGS = ['in_use', 'locked', 'unlisted'];

    public function __construct(
        private readonly AiProviderSettings $providerSettings,
        private readonly AiPricingService $pricingService,
        private readonly AuditLogger $audit,
    ) {
    }

    public function index(Request $request): InertiaResponse
    {
        $this->authorizeSaas();

        $filters = $this->priceFilters($request);

        $runningSync = AiCatalogSync::query()
            ->whereIn('status', [ImportStatus::Pending->value, ImportStatus::Processing->value])
            ->latest()
            ->first();

        return Inertia::render('Panel/Manager/AiProviders/Index', [
            'providers'    => fn () => $this->providerCards(),
            'modelOptions' => fn () => $this->modelOptions(),
            // Catálogo de modelos e preços: paginado, filtrado e ordenado no servidor.
            'prices'       => fn () => $this->pricePage($filters),
            'filters'      => $filters,
            'stats'        => fn () => $this->stats(),
            'roles'        => fn () => $this->providerSettings->roleAssignments(),
            'modes'        => fn () => $this->modeCards(),
            'enabledCount' => fn () => $this->providerSettings->count(),
            // Sincronização do catálogo: barra em tempo real (WebSocket no
            // canal da sincronização), histórico e o que mudou na última.
            'runningSync' => $runningSync?->progressPayload(),
            'syncs'       => fn () => $this->recentSyncs(),
            'syncDetails' => fn () => $this->lastSyncDetails(),
            // Aviso da verificação diária (routes/console.php).
            'autoSync' => (bool) config('ai.catalog_sync.enabled'),
            // LGPD: data da última conferência das fontes oficiais e mecanismos registráveis.
            'lgpd' => [
                'checked_at' => Carbon::parse(ProviderDataPolicy::CHECKED_AT)->isoFormat('L'),
                'mechanisms' => ProviderDataPolicy::MECHANISMS,
            ],
            't' => trans('manager_ai'),
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
            return response()->json([
                'message' => __('manager_ai.error_unconfigured', ['providers' => $this->labels($missing)]),
            ], 422);
        }

        // LGPD: provedor bloqueado para dados de pacientes (ProviderDataPolicy)
        // não entra em papel do assistente.
        $blocked = array_values(array_filter(
            $codes,
            static fn (string $code) => ! ProviderDataPolicy::allowsPatientData(AiProvider::from($code)),
        ));

        if ($blocked !== []) {
            return response()->json([
                'message' => __('manager_ai.error_blocked_for_patients', ['providers' => $this->labels($blocked)]),
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

        // LGPD art. 33: quem PASSA a levar dado de paciente para fora do Brasil
        // sem adequação precisa do mecanismo registrado antes.
        $pending = $this->transfersWithoutRecord($codes, $models, $old);

        if ($pending !== []) {
            return response()->json([
                'message' => __('manager_ai.error_transfer_record_required', ['providers' => $this->labels($pending)]),
            ], 422);
        }

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
     * Modelo de UM provedor (drawer do provedor). Só aceita modelo com preço
     * ativo do próprio provedor; vazio volta ao padrão do .env.
     */
    public function updateModel(Request $request, string $provider): JsonResponse
    {
        $this->authorizeSaas();

        $code      = AiProvider::from($provider)->value;
        $validated = $request->validate(['model' => ['nullable', 'string', 'max:120']]);
        $model     = trim((string) ($validated['model'] ?? ''));

        if ($model !== '' && ! in_array($model, $this->modelOptions()[$code] ?? [], true)) {
            return response()->json([
                'message' => __('manager_ai.error_model_without_price', ['provider' => AiProvider::from($code)->label(), 'model' => $model]),
            ], 422);
        }

        // LGPD: em uso no assistente, trocar para um modelo que leva dado de
        // paciente para fora do Brasil (ex.: Sabiá sem "-br-sp") exige o
        // mecanismo registrado — como ao pôr o provedor num papel.
        $roles = $this->providerSettings->roleAssignments();

        if (in_array($code, $roles, true)
            && $this->transfersWithoutRecord([$code], [$code => $model !== '' ? $model : null], $roles) !== []) {
            return response()->json([
                'message' => __('manager_ai.error_transfer_record_required', ['providers' => AiProvider::from($code)->label()]),
            ], 422);
        }

        $old = $this->providerSettings->panelModels();

        $this->providerSettings->setModels([$code => $model !== '' ? $model : null]);

        $this->audit->recordAdminAction(
            event: 'manager.ai_providers.model',
            targetEntityId: null,
            targetUserId: null,
            auditableType: 'system_setting',
            auditableId: Uuid::uuid5(Uuid::NAMESPACE_OID, AiProviderSettings::MODELS_SETTING_KEY)->toString(),
            reason: __('manager_ai.audit_reason'),
            newValues: ['models' => $this->providerSettings->panelModels()],
            request: $request,
            oldValues: ['models' => $old],
        );

        return response()->json(['message' => __('manager_ai.model_saved')]);
    }

    /**
     * Registra o mecanismo de transferência internacional (LGPD art. 33) com
     * o provedor — ex.: o contrato com as cláusulas-padrão da ANPD (Resolução
     * CD/ANPD nº 19/2024). Guarda só a REFERÊNCIA ao documento (o documento
     * fica com o jurídico) e audita quem registrou. Substitui o anterior.
     */
    public function updateTransfer(Request $request, string $provider): JsonResponse
    {
        $this->authorizeSaas();

        $p = AiProvider::from($provider);

        if (! ProviderDataPolicy::allowsPatientData($p)) {
            return response()->json(['message' => __('manager_ai.transfer_blocked_provider')], 422);
        }

        $validated = $request->validate([
            'mechanism' => ['required', 'string', Rule::in(ProviderDataPolicy::MECHANISMS)],
            'reference' => ['required', 'string', 'max:255'],
            'signed_at' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
        ], [], [
            'mechanism' => __('manager_ai.transfer_mechanism'),
            'reference' => __('manager_ai.transfer_reference'),
            'signed_at' => __('manager_ai.transfer_signed_at'),
        ]);

        $old    = $this->providerSettings->transferRecords()[$p->value] ?? null;
        $record = [
            'mechanism'     => $validated['mechanism'],
            'reference'     => trim($validated['reference']),
            'signed_at'     => $validated['signed_at'],
            'registered_by' => (string) $request->user()->id,
            'registered_at' => now()->toIso8601String(),
        ];

        $this->providerSettings->setTransferRecord($p->value, $record);

        $this->auditTransfer($request, 'manager.ai_providers.transfer', $p->value, $old, $record);

        return response()->json(['message' => __('manager_ai.transfer_saved')]);
    }

    /**
     * Remove o registro — só de provedor fora dos papéis do assistente (em uso,
     * o certo é registrar o novo mecanismo, que substitui o atual).
     */
    public function destroyTransfer(Request $request, string $provider): JsonResponse
    {
        $this->authorizeSaas();

        $code = AiProvider::from($provider)->value;
        $old  = $this->providerSettings->transferRecords()[$code] ?? null;

        if ($old === null) {
            return response()->json(['message' => __('manager_ai.transfer_removed')]);
        }

        if (in_array($code, $this->providerSettings->roleAssignments(), true)) {
            return response()->json(['message' => __('manager_ai.transfer_remove_in_use')], 422);
        }

        $this->providerSettings->setTransferRecord($code, null);

        $this->auditTransfer($request, 'manager.ai_providers.transfer_removed', $code, $old, null);

        return response()->json(['message' => __('manager_ai.transfer_removed')]);
    }

    /**
     * Provedores (dos papéis pedidos) que PASSARIAM a levar dado de paciente
     * para fora do Brasil sem adequação e sem mecanismo registrado. Quem já
     * estava num papel nessa mesma situação segue até o registro (o painel
     * avisa) — trocar só o modelo não cria transferência nova.
     *
     * @param list<string>                                                     $codes    provedores dos papéis pedidos
     * @param array<string, ?string>                                           $models   modelos pedidos (null = volta ao .env)
     * @param array{primary: ?string, reviewer: ?string, adjudicator: ?string} $oldRoles
     *
     * @return list<string>
     */
    private function transfersWithoutRecord(array $codes, array $models, array $oldRoles): array
    {
        $records   = $this->providerSettings->transferRecords();
        $wasInRole = array_flip(array_values(array_filter($oldRoles)));
        $pending   = [];

        foreach ($codes as $code) {
            $provider = AiProvider::from($code);
            $current  = $this->providerSettings->model($code);
            $next     = array_key_exists($code, $models)
                ? ($models[$code] ?? config("ai.providers.{$code}.model"))
                : $current;

            if (isset($records[$code]) || ! ProviderDataPolicy::requiresTransferRecord($provider, is_string($next) ? $next : null)) {
                continue;
            }

            if (isset($wasInRole[$code]) && ProviderDataPolicy::requiresTransferRecord($provider, $current)) {
                continue;
            }

            $pending[] = $code;
        }

        return $pending;
    }

    /**
     * @param array<string, mixed>|null $old
     * @param array<string, mixed>|null $new
     */
    private function auditTransfer(Request $request, string $event, string $code, ?array $old, ?array $new): void
    {
        $this->audit->recordAdminAction(
            event: $event,
            targetEntityId: null,
            targetUserId: null,
            auditableType: 'system_setting',
            auditableId: Uuid::uuid5(Uuid::NAMESPACE_OID, AiProviderSettings::TRANSFER_RECORDS_SETTING_KEY)->toString(),
            reason: __('manager_ai.transfer_audit_reason'),
            newValues: ['provider' => $code, 'record' => $new],
            request: $request,
            oldValues: ['provider' => $code, 'record' => $old],
        );
    }

    /** @param list<string> $codes */
    private function labels(array $codes): string
    {
        return implode(', ', array_map(static fn (string $c) => AiProvider::from($c)->label(), $codes));
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
                maxOutputTokens: 64, // >= 16 (piso OpenAI) e folga p/ modelos com reasoning (thoughts consomem budget)
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
        $enabled     = $this->providerSettings->enabledCodes();
        $order       = array_flip($enabled);
        $panelModels = $this->providerSettings->panelModels();

        // Modelos ativos que o provedor deixou de oferecer (marcados pela sincronização).
        $unlisted = AiModelPrice::query()
            ->where('active', true)
            ->whereNull('effective_until')
            ->whereNotNull('unlisted_at')
            ->get(['provider', 'model', 'unlisted_at'])
            ->keyBy(fn (AiModelPrice $p) => $p->provider->value . '|' . $p->model);

        $roles      = array_flip(array_filter($this->providerSettings->roleAssignments()));
        $records    = $this->providerSettings->transferRecords();
        $recordedBy = User::query()
            ->whereIn('id', array_values(array_filter(array_column($records, 'registered_by'))))
            ->pluck('name', 'id')
            ->all();

        $cards = array_map(function (AiProvider $p) use ($order, $panelModels, $unlisted, $roles, $records, $recordedBy): array {
            $code    = $p->value;
            $model   = $this->providerSettings->model($code);
            $key     = trim((string) config("services.{$code}.api_key", ''));
            $baseUrl = trim((string) config("ai.providers.{$code}.base_url", ''));

            return [
                'code'       => $code,
                'label'      => $p->label(),
                'enabled'    => isset($order[$code]),
                'order'      => $order[$code] ?? null,
                'configured' => $this->providerSettings->isConfigured($code),
                'model'      => $model,
                // Modelo sem preço cadastrado = run falharia após gastar no
                // provedor; o painel avisa ANTES de o admin atribuir o papel.
                'price_ok'   => $model !== null && $this->pricingService->hasPriceFor($p, $model),
                'compatible' => $p->isOpenAiCompatible(),
                // Papel no assistente (primary | reviewer | adjudicator) quando ativo.
                'role' => isset($order[$code]) ? ($roles[$code] ?? null) : null,
                // Chave SÓ no .env: a tela mostra a origem e os 4 últimos
                // caracteres (conferir rotação), nunca o valor.
                'has_key'           => $key !== '',
                'key_hint'          => $this->keyHint($key),
                'key_env'           => $p->apiKeyEnv(),
                'model_env'         => $p->envPrefix() . 'MODEL',
                'model_source'      => isset($panelModels[$code]) ? 'panel' : ($model !== null ? 'env' : null),
                'base_url'          => $this->displayUrl($baseUrl),
                'base_url_env'      => $p->envPrefix() . 'BASE_URL',
                'base_url_secure'   => str_starts_with(mb_strtolower($baseUrl), 'https://'),
                'keys_url'          => $p->keysUrl(),
                'model_unlisted_at' => $model !== null ? $unlisted->get($code . '|' . $model)?->unlisted_at?->isoFormat('L') : null,
                'lgpd'              => $this->lgpdCard($p, $model, $records[$code] ?? null, isset($roles[$code]), $recordedBy),
            ];
        }, AiProvider::cases());

        // Ativos primeiro (na ordem de prioridade), depois os configurados e,
        // por fim, os sem chave (na ordem da lista pronta).
        $rank = static fn (array $c): int => $c['enabled'] ? 0 : ($c['configured'] ? 1 : 2);

        usort($cards, static function (array $a, array $b) use ($rank): int {
            return [$rank($a), $a['order'] ?? PHP_INT_MAX] <=> [$rank($b), $b['order'] ?? PHP_INT_MAX];
        });

        return $cards;
    }

    /**
     * Proteção de dados (LGPD) do provedor para o painel: onde processa, base
     * da transferência, bloqueio para pacientes e o mecanismo registrado.
     *
     * @param array<string, mixed>|null $record
     * @param array<string, string>     $recordedBy id do usuário => nome
     *
     * @return array<string, mixed>
     */
    private function lgpdCard(AiProvider $provider, ?string $model, ?array $record, bool $inRole, array $recordedBy): array
    {
        $profile = ProviderDataPolicy::profile($provider, $model);
        $needs   = ProviderDataPolicy::requiresTransferRecord($provider, $model);

        return [
            'location'       => $profile['location'],
            'transfer'       => $profile['transfer'],
            'patients'       => $profile['patients'],
            'blocked_reason' => $profile['blocked_reason'],
            'needs_record'   => $needs,
            // Formulário de registro visível (também antes de trocar para um modelo que exige).
            'can_record' => $record !== null || ProviderDataPolicy::mayRequireTransferRecord($provider),
            'record'     => $record === null ? null : [
                'mechanism'         => $record['mechanism'],
                'reference'         => $record['reference'],
                'signed_at'         => $record['signed_at'],
                'signed_at_display' => Carbon::parse($record['signed_at'])->isoFormat('L'),
                'registered_by'     => $recordedBy[$record['registered_by'] ?? ''] ?? null,
                'registered_at'     => isset($record['registered_at']) ? Carbon::parse($record['registered_at'])->isoFormat('L LT') : null,
            ],
            // Salvo num papel do assistente (o registro não pode ser removido).
            'in_role' => $inRole,
            // Num papel sem o mecanismo registrado (só quem já estava antes da trava).
            'pending' => $inRole && $needs && $record === null,
            // Sobrou num papel salvo antes da trava — já fora do assistente em produção.
            'blocked_in_role' => $inRole && ! $profile['patients'],
            'sources'         => ProviderDataPolicy::sources($provider),
            // Azure: a região é declarada no .env conforme o deployment criado.
            'region_env'  => $provider === AiProvider::AzureOpenAI ? $provider->envPrefix() . 'DATA_REGION' : null,
            'data_region' => $provider === AiProvider::AzureOpenAI ? (string) config('ai.providers.azure_openai.data_region') : null,
        ];
    }

    /** @return array<string, string> */
    private function priceFilters(Request $request): array
    {
        $pick = static fn (mixed $value, array $allowed, string $default = '') => in_array($value, $allowed, true) ? $value : $default;

        return [
            'search'   => mb_substr($request->string('search')->trim()->value(), 0, 120),
            'provider' => $pick($request->input('provider'), array_column(AiProvider::cases(), 'value')),
            // Padrão: só os ativos (os que aparecem no seletor) — inativos podem ser centenas.
            'status'    => $pick($request->input('status'), ['inactive', 'all'], 'active'),
            'source'    => $pick($request->input('source'), [AiModelPrice::SOURCE_SEED, AiModelPrice::SOURCE_MANUAL, AiModelPrice::SOURCE_SYNC]),
            'flag'      => $pick($request->input('flag'), self::PRICE_FLAGS),
            'sort'      => $pick($request->input('sort'), array_keys(self::PRICE_SORTS)),
            'direction' => $request->input('direction') === 'desc' ? 'desc' : 'asc',
        ];
    }

    /** @param array<string, string> $filters */
    private function pricePage(array $filters): LengthAwarePaginator
    {
        $inUse = $this->inUsePairs();

        $query = AiModelPrice::query()
            ->when($filters['search'] !== '', fn (Builder $q) => $q->whereLikeUnaccent('model', $filters['search']))
            ->when($filters['provider'], fn (Builder $q, string $provider) => $q->where('provider', $provider))
            ->when($filters['status'] === 'active', fn (Builder $q) => $q->where('active', true))
            ->when($filters['status'] === 'inactive', fn (Builder $q) => $q->where('active', false))
            ->when($filters['source'], fn (Builder $q, string $source) => $q->where('source', $source))
            ->when($filters['flag'] === 'locked', fn (Builder $q) => $q->where('price_locked', true))
            ->when($filters['flag'] === 'unlisted', fn (Builder $q) => $q->whereNotNull('unlisted_at'))
            ->when($filters['flag'] === 'in_use', fn (Builder $q) => $q->where(function (Builder $w) use ($inUse) {
                $w->whereRaw('1 = 0');

                foreach ($inUse as [$provider, $model]) {
                    $w->orWhere(fn (Builder $pair) => $pair->where('provider', $provider)->where('model', $model));
                }
            }));

        if ($filters['sort'] !== '') {
            $query->orderBy(self::PRICE_SORTS[$filters['sort']], $filters['direction']);
        } elseif ($inUse !== []) {
            // Padrão: modelos em uso primeiro.
            $case     = implode(' OR ', array_fill(0, count($inUse), '(provider = ? AND model = ?)'));
            $bindings = array_merge(...$inUse);

            $query->orderByRaw("CASE WHEN {$case} THEN 0 ELSE 1 END", $bindings);
        }

        return $query->orderBy('provider')->orderBy('model')->orderBy('id')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (AiModelPrice $p) => [
                ...$p->toCatalogRow(),
                'in_use' => in_array([$p->provider->value, $p->model], $inUse, true),
            ]);
    }

    /**
     * Modelo em uso de cada provedor ATIVO no assistente (com papel).
     *
     * @return list<array{0: string, 1: string}>
     */
    private function inUsePairs(): array
    {
        $pairs = [];

        foreach ($this->providerSettings->enabledCodes() as $code) {
            $model = $this->providerSettings->model($code);

            if ($model !== null) {
                $pairs[] = [$code, $model];
            }
        }

        return $pairs;
    }

    /**
     * Números do topo da tela.
     *
     * @return array<string, mixed>
     */
    private function stats(): array
    {
        $configured = array_filter(AiProvider::cases(), fn (AiProvider $p) => $this->providerSettings->isConfigured($p->value));
        $modes      = $this->providerSettings->availableModes();
        $bestMode   = end($modes);
        $lastSync   = AiCatalogSync::query()->whereNotNull('finished_at')->latest('finished_at')->first();

        return [
            'providers_configured' => count($configured),
            'providers_total'      => count(AiProvider::cases()),
            'models_active'        => AiModelPrice::query()->where('active', true)->count(),
            'models_inactive'      => AiModelPrice::query()->where('active', false)->count(),
            'best_mode'            => $bestMode ? __('manager_ai.mode_' . $bestMode->value) : null,
            'last_sync_at'         => $lastSync?->finished_at?->isoFormat('L LT'),
            'last_sync_status'     => $lastSync?->status->label(),
            'last_sync_color'      => $lastSync?->status->color(),
        ];
    }

    /** Só os 4 últimos caracteres (como os painéis dos provedores); chave curta, nada. */
    private function keyHint(string $key): ?string
    {
        if ($key === '') {
            return null;
        }

        return mb_strlen($key) >= 16 ? '••••' . mb_substr($key, -4) : '••••';
    }

    /** Endereço da API sem credencial/consulta embutidas (ex.: https://api.x.ai/v1). */
    private function displayUrl(string $url): ?string
    {
        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['host'])) {
            return null;
        }

        return ($parts['scheme'] ?? 'https') . '://' . $parts['host']
            . (isset($parts['port']) ? ':' . $parts['port'] : '')
            . rtrim((string) ($parts['path'] ?? ''), '/');
    }

    /** @return list<array<string, mixed>> */
    private function recentSyncs(): array
    {
        return AiCatalogSync::query()
            ->with('user:id,name')
            ->latest()
            ->limit(10)
            ->get()
            // Mesmo formato da barra (progressPayload): a tela acha aqui o
            // resultado de uma sincronização que terminou antes do WebSocket conectar.
            ->map(fn (AiCatalogSync $s) => [
                ...$s->progressPayload(),
                'user'        => $s->user?->name,
                'created_at'  => $s->created_at?->isoFormat('L LT'),
                'finished_at' => $s->finished_at?->isoFormat('L LT'),
            ])
            ->all();
    }

    /**
     * O que mudou na última sincronização terminada (preços alterados, modelos
     * novos, travados/suspeitos para revisar, sem preço, não listados).
     *
     * @return array<string, mixed>|null
     */
    private function lastSyncDetails(): ?array
    {
        $sync = AiCatalogSync::query()
            ->whereIn('status', [ImportStatus::Done->value, ImportStatus::Failed->value])
            ->whereNotNull('details')
            ->latest('finished_at')
            ->first(['id', 'details', 'finished_at']);

        if ($sync === null) {
            return null;
        }

        $labels  = collect(AiProvider::cases())->mapWithKeys(fn (AiProvider $p) => [$p->value => $p->label()]);
        $details = [];

        foreach ((array) $sync->details as $list => $items) {
            $details[$list] = array_map(
                fn (array $item) => [...$item, 'provider_label' => $labels[$item['provider'] ?? ''] ?? ($item['provider'] ?? '')],
                (array) $items,
            );
        }

        return [
            'id'          => $sync->id,
            'finished_at' => $sync->finished_at?->isoFormat('L LT'),
            'lists'       => $details,
        ];
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
