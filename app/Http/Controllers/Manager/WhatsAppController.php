<?php

declare(strict_types=1);

namespace App\Http\Controllers\Manager;

use App\Enums\EntityGate;
use App\Http\Controllers\Controller;
use App\Models\Entity;
use App\Models\WhatsApp\{WhatsAppMessage, WhatsAppOptOut, WhatsAppSetting};
use App\Services\Audit\AuditLogger;
use App\Services\WhatsApp\Contracts\WhatsAppProvider;
use App\Services\WhatsApp\Providers\GupshupAuth;
use App\Services\WhatsApp\{WhatsAppService, WhatsAppTemplates};
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\{Inertia, Response as InertiaResponse};

/**
 * Manager: WhatsApp oficial (Gupshup) — exclusivo do dono/admin do SaaS
 * (Gate SaasAdminPanel em TODA ação; Support/Financial não configuram canal).
 *
 * - Global: app Gupshup do número do EasyEye, padrão de todas as clínicas.
 * - Por clínica: toggles (confirmação, pesquisa) e, opcionalmente, o app
 *   próprio da clínica (app_id informado à mão quando o número dela estiver
 *   aprovado) — precedência: próprio, senão global.
 *
 * Os segredos (credenciais do parceiro, só no .env; segredo do webhook,
 * cifrado no banco) nunca voltam ao front nem vão para a auditoria. Ao
 * salvar um app, a assinatura do webhook é registrada sozinha na Gupshup.
 */
class WhatsAppController extends Controller
{
    /** Filtros da lista de clínicas (query string, padrão Manager → Medicamentos). */
    private const NUMBER_FILTERS = ['own', 'global', 'none'];

    private const AUTOMATION_FILTERS = ['confirmation', 'survey', 'none'];

    private const TABS = ['clinics', 'global', 'templates'];

    private const PER_PAGE = 20;

    public function __construct(
        private readonly WhatsAppProvider $provider,
        private readonly WhatsAppTemplates $templates,
        private readonly AuditLogger $audit,
    ) {
    }

    public function index(Request $request, GupshupAuth $auth): InertiaResponse
    {
        $this->authorizeSaasEntity();

        $filters = $this->filters($request);
        $global  = WhatsAppSetting::globalSetting();

        // Clínica sem número próprio só envia se o número do EasyEye estiver
        // operacional (app + ativo) — mesma regra de WhatsAppSetting::canSend().
        $globalOperational = (bool) $global?->isOperational();

        $driver = (string) config('whatsapp.driver', 'mock');

        // Validade do token universal (só a data — nunca o token): aviso de
        // rotação no painel (o UT vale até 60 dias).
        $utExpiresAt = $auth->mode() === GupshupAuth::MODE_UNIVERSAL ? $auth->universalTokenExpiresAt() : null;
        $warnDays    = max(1, (int) config('whatsapp.gupshup.universal_token_warn_days', 10));

        return Inertia::render('Panel/Manager/WhatsApp/Index', [
            // Paginado no servidor (20), com busca e filtros na URL.
            'clinics' => fn () => $this->clinicPage($filters, $globalOperational),
            'filters' => $filters,
            'kpis'    => fn () => $this->kpis($globalOperational),
            // Qualquer coisa diferente de "gupshup" é simulação (nada sai) —
            // o painel avisa em destaque, inclusive com o driver vazio.
            'driver'    => $driver === 'gupshup' ? 'gupshup' : 'mock',
            'simulated' => $driver !== 'gupshup',
            // Credenciais do parceiro no .env (nunca o valor, só se existem).
            'partner' => [
                'configured'    => $auth->configured(),
                'auth_mode'     => $auth->mode(),
                'ut_expires_at' => $utExpiresAt?->toDateString(),
                'ut_days_left'  => $utExpiresAt ? max(0, (int) floor(now()->diffInDays($utExpiresAt, false))) : null,
                'ut_expired'    => $utExpiresAt !== null && $utExpiresAt->isPast(),
                'ut_expiring'   => $utExpiresAt !== null && ! $utExpiresAt->isPast() && $utExpiresAt->lte(now()->addDays($warnDays)),
            ],
            'global' => fn () => $global ? [
                'active' => $global->active,
                ...$this->appProps($global),
            ] : null,
            'templates'         => fn () => $this->templateCatalog(),
            'templateLanguages' => array_values((array) config('whatsapp.template_languages', ['pt_BR'])),
            'routes'            => [
                'update'        => route('manager.whatsapp.update', ['entity' => '__ID__']),
                'test'          => route('manager.whatsapp.test', ['entity' => '__ID__']),
                'global_update' => route('manager.whatsapp.global.update'),
                'global_test'   => route('manager.whatsapp.global.test'),
            ],
            't' => trans('whatsapp'),
        ]);
    }

    /**
     * @return array{search: string, number: string, automation: string, tab: string}
     */
    private function filters(Request $request): array
    {
        $pick = static function (mixed $value, array $allowed): string {
            return is_string($value) && in_array($value, $allowed, true) ? $value : '';
        };

        $search = $request->query('search');

        return [
            'search'     => mb_substr(trim(is_scalar($search) ? (string) $search : ''), 0, 100),
            'number'     => $pick($request->query('number'), self::NUMBER_FILTERS),
            'automation' => $pick($request->query('automation'), self::AUTOMATION_FILTERS),
            'tab'        => $pick($request->query('tab'), self::TABS) ?: 'clinics',
        ];
    }

    /**
     * Clínicas ativas + a configuração delas (1:1, entity_id único). A
     * situação "número usado" segue WhatsAppSetting::canSend(): própria
     * (ativa + app), do EasyEye (ativa, sem app, global operacional) ou sem
     * envio (todo o resto).
     */
    private function clinicQuery(): Builder
    {
        return Entity::query()
            ->leftJoin('whatsapp_settings as ws', 'ws.entity_id', '=', 'entities.id')
            ->where('entities.is_client', true)
            ->where('entities.active', true);
    }

    /** Condição SQL "tem app próprio" (app_id preenchido). */
    private static function hasAppSql(Builder $q): void
    {
        $q->whereNotNull('ws.app_id')->where('ws.app_id', '<>', '');
    }

    /** Condição SQL "sem app próprio". */
    private static function noAppSql(Builder $q): void
    {
        $q->where(fn (Builder $inner) => $inner->whereNull('ws.app_id')->orWhere('ws.app_id', ''));
    }

    /** @param array{search: string, number: string, automation: string, tab: string} $filters */
    private function applyFilters(Builder $query, array $filters, bool $globalOperational): void
    {
        if ($filters['search'] !== '') {
            $search = $filters['search'];
            $query->where(fn (Builder $q) => $q
                ->whereLikeUnaccent('entities.name', $search)
                ->orWhereLikeUnaccent('entities.code', $search));
        }

        match ($filters['number']) {
            'own'    => $query->where('ws.active', true)->where(fn (Builder $q) => self::hasAppSql($q)),
            'global' => $globalOperational
                ? $query->where('ws.active', true)->where(fn (Builder $q) => self::noAppSql($q))
                : $query->whereRaw('1 = 0'),
            'none' => $query->where(fn (Builder $q) => $q
                ->whereNull('ws.id')
                ->orWhere('ws.active', false)
                ->when(! $globalOperational, fn (Builder $q) => $q->orWhere(fn (Builder $q) => self::noAppSql($q)))),
            default => null,
        };

        match ($filters['automation']) {
            'confirmation' => $query->where('ws.active', true)->where('ws.confirmation_enabled', true),
            'survey'       => $query->where('ws.active', true)->where('ws.survey_enabled', true),
            'none'         => $query->where(fn (Builder $q) => $q
                ->whereNull('ws.id')
                ->orWhere('ws.active', false)
                ->orWhere(fn (Builder $q) => $q->where('ws.confirmation_enabled', false)->where('ws.survey_enabled', false))),
            default => null,
        };
    }

    /**
     * Página de clínicas: 1 consulta para a página, 1 para as configurações
     * (com a contagem de descadastros) e 1 para as estatísticas de 30 dias —
     * sem N+1, qualquer que seja o tamanho da página.
     *
     * @param array{search: string, number: string, automation: string, tab: string} $filters
     */
    private function clinicPage(array $filters, bool $globalOperational): LengthAwarePaginator
    {
        $query = $this->clinicQuery()->select(['entities.id', 'entities.name', 'entities.code']);
        $this->applyFilters($query, $filters, $globalOperational);

        $page = $query->orderBy('entities.name')->orderBy('entities.id')->paginate(self::PER_PAGE)->withQueryString();
        $ids  = $page->getCollection()->map(fn (Entity $e) => (string) $e->id)->all();

        $settings = $ids === [] ? collect() : WhatsAppSetting::query()
            ->whereIn('entity_id', $ids)
            ->withCount('optOuts')
            ->get()
            ->keyBy(fn (WhatsAppSetting $s) => (string) $s->entity_id);

        $stats = $this->statsFor($settings->keys()->all());

        return $page->through(function (Entity $entity) use ($settings, $stats, $globalOperational) {
            $id = (string) $entity->id;
            /** @var WhatsAppSetting|null $setting */
            $setting = $settings->get($id);

            return [
                'id'      => $id,
                'name'    => $entity->name,
                'code'    => $entity->code,
                'sending' => $this->sending($setting, $globalOperational),
                'setting' => $setting ? [
                    'active'                    => $setting->active,
                    'confirmation_enabled'      => $setting->confirmation_enabled,
                    'confirmation_hours_before' => $setting->confirmation_hours_before,
                    'survey_enabled'            => $setting->survey_enabled,
                    'survey_delay_hours'        => $setting->survey_delay_hours,
                    ...$this->appProps($setting),
                ] : null,
                'stats' => $setting ? ($stats[$id] ?? $this->emptyStats()) : null,
            ];
        });
    }

    /** Número pelo qual a clínica envia: own | global | none. */
    private function sending(?WhatsAppSetting $setting, bool $globalOperational): string
    {
        return match (true) {
            ! $setting || ! $setting->active => 'none',
            $setting->hasApp()               => 'own',
            $globalOperational               => 'global',
            default                          => 'none',
        };
    }

    /**
     * Números do topo (todas as clínicas ativas, sem filtro): 1 consulta
     * agregada nas configurações + 1 nas mensagens + 1 nos descadastros.
     *
     * @return array<string, int>
     */
    private function kpis(bool $globalOperational): array
    {
        $hasApp = "ws.app_id IS NOT NULL AND ws.app_id <> ''";

        $row = $this->clinicQuery()
            ->toBase()
            ->selectRaw('COUNT(*) AS clinics')
            ->selectRaw("SUM(CASE WHEN ws.active = true AND {$hasApp} THEN 1 ELSE 0 END) AS own")
            ->selectRaw("SUM(CASE WHEN ws.active = true AND NOT ({$hasApp}) THEN 1 ELSE 0 END) AS without_app")
            ->selectRaw('SUM(CASE WHEN ws.active = true AND ws.confirmation_enabled = true THEN 1 ELSE 0 END) AS confirmations')
            ->selectRaw('SUM(CASE WHEN ws.active = true AND ws.survey_enabled = true THEN 1 ELSE 0 END) AS surveys')
            ->first();

        $clinics = (int) ($row->clinics ?? 0);
        $own     = (int) ($row->own ?? 0);
        $global  = $globalOperational ? (int) ($row->without_app ?? 0) : 0;

        return [
            'clinics'       => $clinics,
            'own'           => $own,
            'global'        => $global,
            'none'          => max(0, $clinics - $own - $global),
            'confirmations' => (int) ($row->confirmations ?? 0),
            'surveys'       => (int) ($row->surveys ?? 0),
            // Confirmações + pesquisas que saíram (mesma conta da coluna da lista).
            'messages_sent' => WhatsAppMessage::query()
                ->where('created_at', '>=', now()->subDays(30))
                ->whereIn('kind', [WhatsAppMessage::KIND_CONFIRMATION, WhatsAppMessage::KIND_SURVEY])
                ->whereIn('status', [WhatsAppMessage::STATUS_SENT, WhatsAppMessage::STATUS_ANSWERED])
                ->count(),
            // Todos os números que enviam (próprios e o do EasyEye).
            'opt_outs' => WhatsAppOptOut::query()->count(),
        ];
    }

    public function update(Request $request, Entity $entity): JsonResponse
    {
        $this->authorizeSaasEntity();
        abort_unless($entity->isClient(), 404);

        $setting = WhatsAppSetting::query()->firstOrNew(['entity_id' => (string) $entity->id]);

        $data = $request->validate([
            'active'                    => ['required', 'boolean'],
            'confirmation_enabled'      => ['required', 'boolean'],
            'confirmation_hours_before' => ['required', 'integer', 'min:1', 'max:168'],
            'survey_enabled'            => ['required', 'boolean'],
            'survey_delay_hours'        => ['required', 'integer', 'min:0', 'max:168'],
            ...$this->appRules($setting),
        ]);

        if (! $setting->exists) {
            $setting->webhook_token = WhatsAppSetting::generateWebhookToken();
        }

        $setting->fill([
            'active'                    => $data['active'],
            'confirmation_enabled'      => $data['confirmation_enabled'],
            'confirmation_hours_before' => $data['confirmation_hours_before'],
            'survey_enabled'            => $data['survey_enabled'],
            'survey_delay_hours'        => $data['survey_delay_hours'],
        ]);

        $changes = $this->applyApp($setting, $data);

        $this->audit->recordAdminAction(
            event: 'manager.whatsapp_settings.updated',
            targetEntityId: (string) $entity->id,
            targetUserId: null,
            auditableType: WhatsAppSetting::class,
            auditableId: (string) $setting->id,
            reason: 'Configuração do WhatsApp (Gupshup) da clínica pelo SaaS',
            // Nunca segredos — só o app (não é segredo) e o que mudou.
            newValues: [
                'active'               => $data['active'],
                'confirmation_enabled' => $data['confirmation_enabled'],
                'survey_enabled'       => $data['survey_enabled'],
                'app_id'               => $setting->app_id,
                ...$changes,
            ],
            request: $request,
        );

        return response()->json([
            'message'    => __('whatsapp.saved'),
            'webhook_ok' => $changes['webhook_ok'],
            ...$this->appProps($setting->fresh()),
        ]);
    }

    /**
     * Saúde do app pela API da Gupshup (app digitado, o salvo da clínica ou,
     * sem app próprio, o global — por onde ela envia). Com "phone", envia o
     * template de teste para esse número.
     */
    public function test(Request $request, Entity $entity): JsonResponse
    {
        $this->authorizeSaasEntity();
        abort_unless($entity->isClient(), 404);

        $stored = WhatsAppSetting::query()->where('entity_id', (string) $entity->id)->first();

        return $this->runTest($request, $stored?->hasApp() ? $stored : WhatsAppSetting::globalSetting());
    }

    // ──────────────────────────────────────────────────────────────────────
    // App GLOBAL do EasyEye (padrão para clínicas sem app próprio)
    // ──────────────────────────────────────────────────────────────────────

    public function updateGlobal(Request $request): JsonResponse
    {
        $this->authorizeSaasEntity();

        $setting = WhatsAppSetting::query()->whereNull('entity_id')->first()
            ?? new WhatsAppSetting(['entity_id' => null]);

        $data = $request->validate([
            'active' => ['required', 'boolean'],
            ...$this->appRules($setting),
        ]);

        if (! $setting->exists) {
            $setting->webhook_token = WhatsAppSetting::generateWebhookToken();
            // Toggles de fluxo não se aplicam à linha global (são por clínica).
            $setting->confirmation_enabled      = false;
            $setting->survey_enabled            = false;
            $setting->confirmation_hours_before = 24;
            $setting->survey_delay_hours        = 2;
        }

        $setting->active = $data['active'];

        $changes = $this->applyApp($setting, $data);

        $this->audit->recordAdminAction(
            event: 'manager.whatsapp_global.updated',
            targetEntityId: null,
            targetUserId: null,
            auditableType: WhatsAppSetting::class,
            auditableId: (string) $setting->id,
            reason: 'Configuração do app WhatsApp (Gupshup) GLOBAL do EasyEye',
            newValues: ['active' => $data['active'], 'app_id' => $setting->app_id, ...$changes],
            request: $request,
        );

        return response()->json([
            'message'    => __('whatsapp.saved'),
            'webhook_ok' => $changes['webhook_ok'],
            ...$this->appProps($setting->fresh()),
        ]);
    }

    public function testGlobal(Request $request): JsonResponse
    {
        $this->authorizeSaasEntity();

        return $this->runTest($request, WhatsAppSetting::globalSetting());
    }

    // ──────────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function appRules(WhatsAppSetting $setting): array
    {
        return [
            // App Gupshup (UUID da Gupshup); um app atende uma configuração só.
            'app_id' => [
                'nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9-]+$/',
                Rule::unique('whatsapp_settings', 'app_id')->ignore($setting->id),
            ],
            // Remove o app (clínica volta para o número do EasyEye).
            'clear_app' => ['sometimes', 'boolean'],
            // Refaz a assinatura do webhook do app salvo.
            'register_webhook' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Aplica app novo/removido, salva e (re)registra o webhook quando o app
     * mudou ou foi pedido. Segredo do webhook novo a cada app novo.
     *
     * @param array<string, mixed> $data
     *
     * @return array{app_changed: bool, app_cleared: bool, webhook_ok: bool}
     */
    private function applyApp(WhatsAppSetting $setting, array $data): array
    {
        $appChanged = false;
        $appCleared = false;

        if (! empty($data['clear_app'])) {
            $appCleared = $setting->app_id !== null;
            $setting->fill(['app_id' => null, 'webhook_secret' => null, 'subscription_id' => null, 'webhook_subscribed_at' => null]);
        } elseif (filled($data['app_id'] ?? null) && $data['app_id'] !== $setting->app_id) {
            $appChanged = true;
            $setting->fill([
                'provider'              => 'gupshup',
                'app_id'                => $data['app_id'],
                'webhook_secret'        => WhatsAppSetting::generateWebhookSecret(),
                'subscription_id'       => null,
                'webhook_subscribed_at' => null,
            ]);
        }

        $setting->save();

        $webhookOk = true;

        if ($setting->hasApp() && ($appChanged || ! empty($data['register_webhook']))) {
            $webhookOk = $this->registerWebhook($setting);
        }

        return ['app_changed' => $appChanged, 'app_cleared' => $appCleared, 'webhook_ok' => $webhookOk];
    }

    /**
     * Assinatura v3 do webhook na Gupshup, com o segredo no header (meta).
     * Falha não bloqueia o save — o painel avisa e o botão refaz.
     */
    private function registerWebhook(WhatsAppSetting $setting): bool
    {
        if (blank($setting->webhook_secret)) {
            $setting->webhook_secret = WhatsAppSetting::generateWebhookSecret();
        }

        $result = $this->provider->subscribeWebhook(
            $setting,
            route('whatsapp.gupshup.webhook', $setting->webhook_token),
            (string) $setting->webhook_secret,
        );

        $setting->forceFill([
            'subscription_id'       => $result['ok'] ? ($result['subscription_id'] ?? null) : $setting->subscription_id,
            'webhook_subscribed_at' => $result['ok'] ? now() : null,
        ])->save();

        return $result['ok'];
    }

    private function runTest(Request $request, ?WhatsAppSetting $stored): JsonResponse
    {
        $data = $request->validate([
            'app_id' => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9-]+$/'],
            'phone'  => ['nullable', 'string', 'max:30'],
        ]);

        // App digitado e ainda não salvo: teste transitório, nada persiste.
        $setting = filled($data['app_id'] ?? null)
            ? new WhatsAppSetting(['app_id' => $data['app_id']])
            : $stored;

        if (! $setting || ! $setting->hasApp()) {
            return response()->json(['ok' => false, 'error' => __('whatsapp.no_app')], 422);
        }

        if (filled($data['phone'] ?? null)) {
            $phone = WhatsAppService::normalizePhone($data['phone']);

            if ($phone === null) {
                return response()->json(['ok' => false, 'error' => __('whatsapp.connection.invalid_phone')], 422);
            }

            $template = $this->templates->build('connection_test', ['app' => (string) config('app.name')], app()->getLocale());
            $result   = $this->provider->sendTemplate($setting, $phone, $template);

            return $result['ok']
                ? response()->json(['ok' => true, 'sent' => true])
                : response()->json(['ok' => false, 'error' => $result['error'] ?? __('whatsapp.connection.failed')], 422);
        }

        $result = $this->provider->health($setting);

        if (! $result['ok']) {
            return response()->json(['ok' => false, 'error' => $result['error'] ?? __('whatsapp.connection.failed')], 422);
        }

        return response()->json(['ok' => true, 'healthy' => (bool) ($result['healthy'] ?? false)]);
    }

    /** @return array<string, mixed> o que o painel pode ver do app (nada de segredo) */
    private function appProps(WhatsAppSetting $setting): array
    {
        return [
            'has_app'       => $setting->hasApp(),
            'app_id'        => $setting->app_id,
            'webhook_url'   => route('whatsapp.gupshup.webhook', $setting->webhook_token),
            'webhook_ok'    => $setting->webhook_subscribed_at !== null,
            'webhook_since' => $setting->webhook_subscribed_at?->toIso8601String(),
            // withCount('optOuts') na lista (sem N+1); conta na hora no resto.
            'opt_outs' => $setting->exists ? (int) ($setting->opt_outs_count ?? $setting->optOuts()->count()) : 0,
        ];
    }

    private function authorizeSaasEntity(): void
    {
        Gate::authorize(EntityGate::SaasAdminPanel->value, $this->currentSaasEntity());
    }

    private function currentSaasEntity(): Entity
    {
        return Entity::findOrFail(session('selected_entity_id'));
    }

    /**
     * Estatísticas de 30 dias de várias clínicas numa consulta só (agregação
     * condicional por entity_id) — os mesmos números de antes, por clínica.
     *
     * @param list<string> $entityIds
     *
     * @return array<string, array<string, int|float>>
     */
    private function statsFor(array $entityIds): array
    {
        if ($entityIds === []) {
            return [];
        }

        $sentOrAnswered = "status IN ('sent', 'answered')";

        return WhatsAppMessage::query()
            ->toBase()
            ->whereIn('entity_id', $entityIds)
            ->where('created_at', '>=', now()->subDays(30))
            ->groupBy('entity_id')
            ->select('entity_id')
            ->selectRaw("SUM(CASE WHEN kind = 'confirmation' AND {$sentOrAnswered} THEN 1 ELSE 0 END) AS confirmations_sent")
            ->selectRaw("SUM(CASE WHEN kind = 'confirmation' AND status = 'answered' THEN 1 ELSE 0 END) AS confirmations_answered")
            ->selectRaw("SUM(CASE WHEN kind = 'survey' AND {$sentOrAnswered} THEN 1 ELSE 0 END) AS surveys_sent")
            ->selectRaw("SUM(CASE WHEN kind = 'survey' AND status = 'answered' THEN 1 ELSE 0 END) AS surveys_answered")
            ->selectRaw("AVG(CASE WHEN kind = 'survey' THEN survey_score END) AS survey_average")
            ->selectRaw("SUM(CASE WHEN direction = 'out' AND delivered_at IS NOT NULL THEN 1 ELSE 0 END) AS delivered")
            ->selectRaw("SUM(CASE WHEN direction = 'out' AND read_at IS NOT NULL THEN 1 ELSE 0 END) AS \"read\"")
            ->selectRaw("SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed")
            ->get()
            ->mapWithKeys(fn (object $row) => [(string) $row->entity_id => [
                'confirmations_sent'     => (int) $row->confirmations_sent,
                'confirmations_answered' => (int) $row->confirmations_answered,
                'surveys_sent'           => (int) $row->surveys_sent,
                'surveys_answered'       => (int) $row->surveys_answered,
                'survey_average'         => round((float) ($row->survey_average ?? 0), 1),
                'delivered'              => (int) $row->delivered,
                'read'                   => (int) $row->read,
                'failed'                 => (int) $row->failed,
            ]])
            ->all();
    }

    /** @return array<string, int|float> */
    private function emptyStats(): array
    {
        return [
            'confirmations_sent' => 0, 'confirmations_answered' => 0, 'surveys_sent' => 0, 'surveys_answered' => 0,
            'survey_average'     => 0.0, 'delivered' => 0, 'read' => 0, 'failed' => 0,
        ];
    }

    /**
     * Modelos do WhatsApp para a tela (prévia, texto para a Meta, variáveis e
     * botões) em pt_BR e en. Fonte única: config/whatsapp.php (nome, ordem
     * das variáveis, botões, rodapé) + lang/{pt_BR,en}/whatsapp.php
     * (templates.* — o mesmo texto do histórico das mensagens).
     *
     * @return list<array<string, mixed>>
     */
    private function templateCatalog(): array
    {
        $locales = ['pt_BR', 'en'];

        return collect((array) config('whatsapp.templates'))
            ->map(function (array $template, string $key) use ($locales) {
                $params  = array_values((array) ($template['body'] ?? []));
                $buttons = array_values((array) ($template['buttons'] ?? []));
                $texts   = [];

                foreach ($locales as $locale) {
                    [$body, $footer, $labels] = $this->splitTemplateText((string) __("whatsapp.templates.{$key}", [], $locale), (bool) ($template['footer'] ?? false));
                    $examples                 = (array) __('whatsapp.manager.template_examples', [], $locale);

                    $meta    = $body;
                    $preview = $body;

                    foreach ($params as $i => $param) {
                        $meta    = str_replace(":{$param}", '{{' . ($i + 1) . '}}', $meta);
                        $preview = str_replace(":{$param}", (string) ($examples[$param] ?? $param), $preview);
                    }

                    $texts[$locale] = [
                        'meta'    => $meta,
                        'preview' => $preview,
                        'footer'  => $footer,
                        'buttons' => collect($buttons)->values()->map(fn (array $button, int $i) => [
                            'type'  => $button['type'],
                            'label' => match ($button['type']) {
                                'url'   => (string) __('whatsapp.manager.template_buttons.url', [], $locale),
                                'otp'   => (string) __('whatsapp.manager.template_buttons.otp', [], $locale),
                                default => (string) ($labels[$i] ?? $button['action'] ?? ''),
                            },
                        ])->all(),
                        'params' => collect($params)->values()->map(fn (string $param, int $i) => [
                            'placeholder' => '{{' . ($i + 1) . '}}',
                            'key'         => $param,
                            'label'       => (string) __("whatsapp.manager.template_params.{$param}", [], $locale),
                            'example'     => (string) ($examples[$param] ?? ''),
                        ])->all(),
                    ];
                }

                return [
                    'key'      => $key,
                    'name'     => $template['name'],
                    'category' => $template['category'] ?? 'UTILITY',
                    'group'    => match (true) {
                        str_starts_with($key, 'saas_')                                 => 'saas',
                        in_array($key, ['verification_code', 'connection_test'], true) => 'registration',
                        default                                                        => 'patients',
                    },
                    'texts' => $texts,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Texto do histórico → [corpo, rodapé, rótulos dos botões]. Linha final
     * "[Confirmar] [Cancelar]" = botões; com rodapé, a linha antes deles é o
     * rodapé ("responda SAIR").
     *
     * @return array{0: string, 1: ?string, 2: list<string>}
     */
    private function splitTemplateText(string $text, bool $hasFooter): array
    {
        $lines  = explode("\n", trim($text));
        $labels = [];

        if ($lines !== [] && str_starts_with(trim((string) end($lines)), '[')) {
            preg_match_all('/\[([^\]]+)\]/u', (string) array_pop($lines), $matches);
            $labels = $matches[1];
        }

        $footer = $hasFooter && count($lines) > 1 ? trim((string) array_pop($lines)) : null;

        return [trim(implode("\n", $lines)), $footer, $labels];
    }
}
