<?php

declare(strict_types=1);

namespace App\Http\Controllers\Manager;

use App\Enums\EntityGate;
use App\Enums\Fiscal\{NfseEnvironment, NfseStandard};
use App\Exceptions\Fiscal\FocusNfeException;
use App\Http\Controllers\Controller;
use App\Models\Entity;
use App\Models\Fiscal\{NfseCredential, NfseDocument};
use App\Rules\CpfCnpj;
use App\Services\Audit\AuditLogger;
use App\Services\Fiscal\{NfseCredentialResolver, NfseMunicipalityService};
use App\Support\BrazilianFormat;
use App\Support\Fiscal\{NfseManagerPresenter, NfsePendingInvoices, NfseSettings};
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\{Inertia, Response};

/**
 * Manager → Nota fiscal: configuração global da NFS-e do SaaS (a dona do SaaS é a única prestadora). Dados do prestador,
 * padrão e ambiente, emissão automática, credenciais da Focus (tokens cifrados — NUNCA devolvidos: só has_token), gatilho
 * (webhook), consulta de município e IBGE das clínicas. Leitura da página para admin e financeiro (as seções de
 * configuração só aparecem a quem tem `can_settings`); toda gravação é do admin do SaaS, com `reason` (≥ 20) nas
 * credenciais e nos gatilhos, e trilha em audit_logs sem segredo.
 */
class NfseSettingsController extends Controller
{
    private const REASON_RULES = ['required', 'string', 'min:20', 'max:1000'];

    public function __construct(
        private readonly NfseSettings $settings,
        private readonly NfseCredentialResolver $credentials,
        private readonly NfseMunicipalityService $municipalities,
        private readonly NfseManagerPresenter $presenter,
        private readonly AuditLogger $audit,
    ) {
    }

    public function index(): Response
    {
        $saas        = $this->saas();
        $canSettings = Gate::allows(EntityGate::SaasAdminPanel->value, $saas);
        $environment = $this->settings->environment();
        $ibge        = (string) $this->settings->prestador()['codigo_municipio'];
        $cached      = preg_match('/^\d{7}$/', $ibge) === 1 ? $this->municipalities->cached($ibge, $environment) : null;

        return Inertia::render('Panel/Manager/Nfse/Index', [
            'settings'    => $this->settings->all(),
            'credentials' => collect(NfseEnvironment::cases())->map(fn (NfseEnvironment $env) => $this->credentialRow($env, $canSettings))->values(),
            // A URL do gatilho leva o token que o roteia: só quem configura (admin) a recebe.
            'hook_urls' => collect(NfseEnvironment::cases())->mapWithKeys(fn (NfseEnvironment $env) => [
                $env->value => $canSettings ? $this->credentials->find($env)?->webhookUrl() : null,
            ]),
            'municipality' => $cached?->toArray(),
            'documents'    => NfseDocument::query()->with(['invoice:id,reference', 'entity:id,name'])->latest('created_at')->limit(20)->get()
                ->map(fn (NfseDocument $document) => $this->presenter->row($document))->values(),
            'pending_invoices' => NfsePendingInvoices::forList()->with('entity:id,name')->orderByDesc('invoices.paid_at')->limit(20)->get()
                ->map(fn ($invoice) => [
                    'id'             => $invoice->id,
                    'reference'      => $invoice->reference,
                    'entity'         => ['id' => $invoice->entity_id, 'name' => $invoice->entity?->name],
                    'amount'         => (float) $invoice->amount,
                    'currency'       => $invoice->currency,
                    'paid_at'        => $invoice->paid_at?->toIso8601String(),
                    'billing_reason' => $invoice->billing_reason,
                    'issue_url'      => Gate::allows(EntityGate::SaasFinancial->value, $saas) ? route('manager.nfse.invoices.issue', $invoice) : null,
                ])->values(),
            'entities_missing_ibge' => Entity::query()->where('is_client', true)->where('active', true)->whereNull('ibge_code')
                ->orderBy('name')->limit(20)->get(['id', 'name', 'city', 'state'])
                ->map(fn (Entity $entity) => ['id' => $entity->id, 'name' => $entity->name, 'city' => $entity->city, 'state' => $entity->state])->values(),
            'permissions' => [
                'can_settings' => $canSettings,
                'can_issue'    => Gate::allows(EntityGate::SaasFinancial->value, $saas),
                'can_cancel'   => Gate::allows(EntityGate::SaasAdminPanel->value, $saas),
            ],
            't' => trans('fiscal'),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $this->authorizeAdmin();

        $data = $request->validate([
            'prestador'      => ['sometimes', 'array'],
            'prestador.cnpj' => ['nullable', 'string', 'max:20', function ($attribute, $value, $fail) {
                if (filled($value) && (strlen((string) BrazilianFormat::documentChars($value)) !== 14 || ! CpfCnpj::isValid($value))) {
                    $fail(__('validation.cpf_cnpj_invalid'));
                }
            }],
            'prestador.inscricao_municipal'            => ['nullable', 'string', 'max:20'],
            'prestador.codigo_municipio'               => ['nullable', 'regex:/^\d{7}$/'],
            'prestador.cnae'                           => ['nullable', 'regex:/^\d{7}$/'],
            'prestador.item_lista_servico'             => ['nullable', 'string', 'max:10'],
            'prestador.codigo_tributario_municipio'    => ['nullable', 'string', 'max:20'],
            'prestador.codigo_tributacao_nacional_iss' => ['nullable', 'regex:/^\d{6}$/'],
            'prestador.tributacao_iss'                 => ['nullable', 'integer', 'between:1,4'],
            'prestador.aliquota_iss'                   => ['nullable', 'numeric', 'between:0,1'],
            'prestador.iss_retido'                     => ['nullable', 'boolean'],
            'prestador.natureza_operacao'              => ['nullable', 'in:1,2,3,4,5,6'],
            'prestador.optante_simples_nacional'       => ['nullable', 'boolean'],
            'prestador.regime_especial_tributacao'     => ['nullable', 'regex:/^[0-9]$/'],
            'prestador.serie_dps'                      => ['nullable', 'integer', 'between:1,49999'],
            'prestador.proximo_numero_dps'             => ['nullable', 'integer', 'min:1', 'max:999999999999999'],
            'standard'                                 => ['sometimes', Rule::enum(NfseStandard::class)],
            'environment'                              => ['sometimes', Rule::enum(NfseEnvironment::class)],
            'auto_issue'                               => ['sometimes', 'boolean'],
            'copy_email'                               => ['nullable', 'email', 'max:80'],
            'discriminacao'                            => ['sometimes', 'array'],
            'discriminacao.plano'                      => ['nullable', 'string', 'max:1000'],
            'discriminacao.ia'                         => ['nullable', 'string', 'max:1000'],
        ]);

        // Contador da DPS: nunca volta para um número já usado na série (a Focus/RFB recusam repetido).
        if (isset($data['prestador']['proximo_numero_dps'])) {
            $series = (int) ($data['prestador']['serie_dps'] ?? $this->settings->serieDps());
            $max    = (int) NfseDocument::query()->where('dps_series', $series)->max('dps_number');

            if ((int) $data['prestador']['proximo_numero_dps'] <= $max) {
                return $this->validationError('prestador.proximo_numero_dps', __('fiscal.errors.dps_number_used', ['last' => $max]));
            }
        }

        // Ligar a emissão automática exige tudo pronto: prestador completo e token do ambiente.
        if (($data['auto_issue'] ?? false) === true) {
            $standard    = NfseStandard::tryFrom((string) ($data['standard'] ?? $this->settings->standard()->value)) ?? $this->settings->standard();
            $environment = NfseEnvironment::tryFrom((string) ($data['environment'] ?? $this->settings->environment()->value)) ?? $this->settings->environment();
            $preview     = $this->previewMissing($data, $standard);

            if ($preview !== [] || ! $this->credentials->hasToken($environment)) {
                return $this->validationError('auto_issue', $preview !== []
                    ? __('fiscal.errors.prestador_incompleto', ['fields' => implode(', ', $preview)])
                    : __('fiscal.errors.credencial_ausente'));
            }
        }

        $changes = $this->settings->save($this->normalize($data));

        $this->audit->recordAdminAction(
            event: 'manager.nfse.settings',
            targetEntityId: null,
            targetUserId: null,
            auditableType: 'nfse_settings',
            auditableId: (string) $this->saas()->id,
            reason: 'Atualização da configuração de NFS-e (Manager → Nota fiscal)',
            newValues: ['changed' => array_map(fn ($c) => $c['new'], $changes)],
            request: $request,
            oldValues: ['changed' => array_map(fn ($c) => $c['old'], $changes)],
        );

        return response()->json(['message' => __('fiscal.settings_saved'), 'settings' => $this->settings->all()]);
    }

    public function storeCredential(Request $request, string $environment): JsonResponse
    {
        $this->authorizeAdmin();

        $env = NfseEnvironment::tryFrom($environment) ?? abort(404);

        $data = $request->validate([
            'token'        => ['nullable', 'string', 'min:8', 'max:255', 'required_without:master_token'],
            'master_token' => ['nullable', 'string', 'min:8', 'max:255'],
            'reason'       => self::REASON_RULES,
        ], [
            'reason.required' => __('manager_hardening.reason_required'),
            'reason.min'      => __('manager_hardening.reason_min', ['min' => 20]),
            'reason.max'      => __('manager_hardening.reason_max', ['max' => 1000]),
        ]);

        $credential = $this->credentials->find($env) ?? new NfseCredential([
            'environment'    => $env->value,
            'webhook_token'  => NfseCredential::generateWebhookToken(),
            'webhook_secret' => NfseCredential::generateWebhookSecret(),
        ]);

        // Campo em branco mantém o que já está gravado ("substituir" só quando o admin digita um valor novo).
        foreach (['token', 'master_token'] as $field) {
            if (filled($data[$field] ?? null)) {
                $credential->{$field} = trim((string) $data[$field]);
            }
        }

        $credential->last_tested_at    = null;
        $credential->last_test_ok      = null;
        $credential->last_test_message = null;
        $credential->save();

        $this->audit->recordAdminAction(
            event: 'manager.nfse.credentials',
            targetEntityId: null,
            targetUserId: null,
            auditableType: 'nfse_credential',
            auditableId: (string) $credential->id,
            reason: trim($data['reason']),
            newValues: ['environment' => $env->value, 'has_token' => $credential->hasToken(), 'has_master_token' => $credential->hasMasterToken()],
            request: $request,
        );

        return response()->json(['message' => __('fiscal.credential_saved'), 'credential' => $this->credentialRow($env)]);
    }

    /** "Testar credencial": chamada barata e NÃO fiscal (lista de gatilhos; e o município do prestador, se houver token master). */
    public function testCredential(Request $request, string $environment): JsonResponse
    {
        $this->authorizeAdmin();

        $env        = NfseEnvironment::tryFrom($environment) ?? abort(404);
        $credential = $this->credentials->find($env);
        $ok         = false;

        try {
            $this->credentials->client($env)->listHooks();

            $ibge = (string) $this->settings->prestador()['codigo_municipio'];

            if ($credential?->hasMasterToken() && preg_match('/^\d{7}$/', $ibge) === 1) {
                $this->municipalities->obligations($ibge, $env, refresh: true);
            }

            $ok      = true;
            $message = __('fiscal.credential_test_ok');
        } catch (FocusNfeException $e) {
            $message = $e->userMessage();
        }

        $credential?->forceFill(['last_tested_at' => now(), 'last_test_ok' => $ok, 'last_test_message' => mb_substr($message, 0, 255)])->save();

        return response()->json(['ok' => $ok, 'message' => $message, 'credential' => $this->credentialRow($env)], $ok ? 200 : 422);
    }

    public function storeHook(Request $request): JsonResponse
    {
        $this->authorizeAdmin();

        $data = $request->validate([
            'environment' => ['required', Rule::enum(NfseEnvironment::class)],
            'reason'      => self::REASON_RULES,
        ], [
            'reason.required' => __('manager_hardening.reason_required'),
            'reason.min'      => __('manager_hardening.reason_min', ['min' => 20]),
        ]);

        $env        = NfseEnvironment::from($data['environment']);
        $credential = $this->credentials->for($env);
        $cnpj       = (string) BrazilianFormat::documentChars((string) $this->settings->prestador()['cnpj']);
        $url        = $credential->webhookUrl();
        $host       = (string) parse_url($url, PHP_URL_HOST);

        if (! $credential->hasToken()) {
            return $this->validationError('environment', __('fiscal.errors.credencial_ausente'));
        }

        if (strlen($cnpj) !== 14) {
            return $this->validationError('environment', __('fiscal.errors.prestador_incompleto', ['fields' => 'prestador.cnpj']));
        }

        // A Focus precisa alcançar a URL: https e fora de localhost/IP local.
        if (! str_starts_with($url, 'https://') || in_array($host, ['localhost', '127.0.0.1', '::1'], true) || str_ends_with($host, '.test') || str_ends_with($host, '.local')) {
            return $this->validationError('environment', __('fiscal.errors.hook_url_invalid'));
        }

        $standard = $this->settings->standard();

        try {
            $client = $this->credentials->client($env);

            // Idempotente: registrar de novo remove o gatilho antigo primeiro.
            if ($credential->hasHook()) {
                $client->deleteHook((string) $credential->hook_id);
            }

            $hook = $client->registerHook($cnpj, $standard, $url, (string) $credential->webhook_secret, (string) config('fiscal.webhook.header'));
        } catch (FocusNfeException $e) {
            return $this->focusError($e);
        }

        $credential->forceFill(['hook_id' => (string) ($hook['id'] ?? ''), 'hook_event' => $standard->hookEvent()])->save();

        $this->audit->recordAdminAction(
            event: 'manager.nfse.hooks.store',
            targetEntityId: null,
            targetUserId: null,
            auditableType: 'nfse_credential',
            auditableId: (string) $credential->id,
            reason: trim($data['reason']),
            newValues: ['environment' => $env->value, 'hook_id' => $credential->hook_id, 'event' => $credential->hook_event],
            request: $request,
        );

        return response()->json(['message' => __('fiscal.hook_registered'), 'credential' => $this->credentialRow($env)]);
    }

    public function destroyHook(Request $request, string $environment): JsonResponse
    {
        $this->authorizeAdmin();

        $env = NfseEnvironment::tryFrom($environment) ?? abort(404);

        $request->validate(['reason' => self::REASON_RULES], [
            'reason.required' => __('manager_hardening.reason_required'),
            'reason.min'      => __('manager_hardening.reason_min', ['min' => 20]),
        ]);

        $credential = $this->credentials->for($env);

        if ($credential->hasHook()) {
            try {
                $this->credentials->client($env)->deleteHook((string) $credential->hook_id);
            } catch (FocusNfeException $e) {
                return $this->focusError($e);
            }
        }

        $hookId = $credential->hook_id;
        $credential->forceFill(['hook_id' => null, 'hook_event' => null])->save();

        $this->audit->recordAdminAction(
            event: 'manager.nfse.hooks.destroy',
            targetEntityId: null,
            targetUserId: null,
            auditableType: 'nfse_credential',
            auditableId: (string) $credential->id,
            reason: trim((string) $request->input('reason')),
            newValues: ['environment' => $env->value],
            request: $request,
            oldValues: ['hook_id' => $hookId],
        );

        return response()->json(['message' => __('fiscal.hook_removed'), 'credential' => $this->credentialRow($env)]);
    }

    /** Obrigatoriedades do município (cache de 24 h; `refresh=1` consulta de novo). Sem `ibge`, o do prestador. */
    public function municipality(Request $request): JsonResponse
    {
        $this->authorizeFinancial();

        $request->validate(['ibge' => ['nullable', 'regex:/^\d{7}$/']]);

        $ibge = (string) ($request->query('ibge') ?: $this->settings->prestador()['codigo_municipio']);

        if (preg_match('/^\d{7}$/', $ibge) !== 1) {
            return $this->validationError('ibge', __('fiscal.errors.prestador_incompleto', ['fields' => 'prestador.codigo_municipio']));
        }

        try {
            $municipality = $this->municipalities->obligations($ibge, $this->settings->environment(), $request->boolean('refresh'));
        } catch (FocusNfeException $e) {
            return $this->focusError($e);
        }

        return response()->json(['municipality' => $municipality->toArray()]);
    }

    /** Busca de município por nome/UF (Focus, token master). */
    public function searchMunicipalities(Request $request): JsonResponse
    {
        $this->authorizeFinancial();

        $data = $request->validate(['q' => ['required', 'string', 'min:2', 'max:80'], 'uf' => ['nullable', 'alpha', 'size:2']]);

        try {
            $rows = $this->municipalities->search($this->settings->environment(), $data['q'], $data['uf'] ?? null);
        } catch (FocusNfeException $e) {
            return $this->focusError($e);
        }

        return response()->json(['data' => $rows]);
    }

    /** IBGE da clínica (tomador): digitado ou escolhido na busca, validado na Focus antes de gravar. */
    public function updateEntityIbgeCode(Request $request, Entity $entity): JsonResponse
    {
        $this->authorizeAdmin();

        abort_unless($entity->is_client, 404);

        $data = $request->validate(['ibge_code' => ['required', 'regex:/^\d{7}$/']]);

        try {
            $municipality = $this->municipalities->obligations($data['ibge_code'], $this->settings->environment(), refresh: true);
        } catch (FocusNfeException $e) {
            return $e->errorCode === FocusNfeException::NOT_FOUND
                ? $this->validationError('ibge_code', __('fiscal.errors.ibge_not_found'))
                : $this->focusError($e);
        }

        $old = $entity->ibge_code;
        $entity->update(['ibge_code' => $data['ibge_code']]);

        $this->audit->recordAdminAction(
            event: 'manager.nfse.entities.ibge-code',
            targetEntityId: (string) $entity->id,
            targetUserId: null,
            auditableType: 'entity',
            auditableId: (string) $entity->id,
            reason: 'IBGE da clínica para a NFS-e',
            newValues: ['ibge_code' => $entity->ibge_code, 'municipio' => $municipality->name, 'uf' => $municipality->uf],
            request: $request,
            oldValues: ['ibge_code' => $old],
        );

        return response()->json([
            'message'      => __('fiscal.ibge_saved'),
            'entity'       => ['id' => $entity->id, 'ibge_code' => $entity->ibge_code],
            'municipality' => ['name' => $municipality->name, 'uf' => $municipality->uf, 'matches_state' => $municipality->uf !== null && strtoupper((string) $entity->state) === $municipality->uf],
        ]);
    }

    /** @return array<string, mixed> */
    private function credentialRow(NfseEnvironment $environment, bool $withHookUrl = true): array
    {
        $credential = $this->credentials->find($environment);

        return [
            'environment'      => $environment->value,
            'configured'       => $credential !== null && ($credential->hasToken() || $credential->hasMasterToken()),
            'has_token'        => $credential?->hasToken() ?? false,
            'has_master_token' => $credential?->hasMasterToken() ?? false,
            'hook'             => [
                'registered' => $credential?->hasHook() ?? false,
                'hook_id'    => $credential?->hook_id,
                'event'      => $credential?->hook_event,
                'url'        => $withHookUrl ? $credential?->webhookUrl() : null,
            ],
            'last_test' => [
                'at'      => $credential?->last_tested_at?->toIso8601String(),
                'ok'      => $credential?->last_test_ok,
                'message' => $credential?->last_test_message,
            ],
        ];
    }

    /**
     * Campos do prestador que ainda faltariam se o formulário fosse salvo agora (valor novo, ou o já gravado).
     *
     * @param array<string, mixed> $data
     *
     * @return list<string>
     */
    private function previewMissing(array $data, NfseStandard $standard): array
    {
        $current = $this->settings->prestador();
        $merged  = array_replace($current, array_filter((array) ($data['prestador'] ?? []), fn ($value) => $value !== null));

        $required = ['cnpj', 'codigo_municipio', ...($standard === NfseStandard::National ? ['codigo_tributacao_nacional_iss'] : ['inscricao_municipal', 'item_lista_servico'])];

        return array_values(array_map(fn (string $field) => "prestador.{$field}", array_filter($required, fn (string $field) => blank($merged[$field] ?? null))));
    }

    /**
     * Só os campos conhecidos, normalizados (CNPJ canônico, CNAE/IBGE só dígitos, "" → vazio).
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function normalize(array $data): array
    {
        if (isset($data['prestador']['cnpj'])) {
            $data['prestador']['cnpj'] = (string) BrazilianFormat::documentChars((string) $data['prestador']['cnpj']);
        }

        return $data;
    }

    private function authorizeAdmin(): void
    {
        Gate::authorize(EntityGate::SaasAdminPanel->value, $this->saas());
    }

    private function authorizeFinancial(): void
    {
        Gate::authorize(EntityGate::SaasFinancial->value, $this->saas());
    }

    private function saas(): Entity
    {
        return Entity::findOrFail(session('selected_entity_id'));
    }

    private function validationError(string $field, string $message): JsonResponse
    {
        return response()->json(['message' => $message, 'errors' => [$field => [$message]]], 422);
    }

    private function focusError(FocusNfeException $e): JsonResponse
    {
        $status = match ($e->errorCode) {
            FocusNfeException::RATE_LIMITED => 429,
            FocusNfeException::UNAVAILABLE  => 502,
            default                         => 422,
        };

        return response()->json(['message' => $e->userMessage(), 'errors' => $e->errors], $status);
    }
}
