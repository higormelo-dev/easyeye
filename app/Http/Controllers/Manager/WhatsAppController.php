<?php

declare(strict_types=1);

namespace App\Http\Controllers\Manager;

use App\Enums\EntityGate;
use App\Http\Controllers\Controller;
use App\Models\Entity;
use App\Models\WhatsApp\{WhatsAppMessage, WhatsAppSetting};
use App\Services\Audit\AuditLogger;
use App\Services\WhatsApp\ZApiClient;
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Facades\Gate;
use Inertia\{Inertia, Response as InertiaResponse};

/**
 * Manager: configuração do WhatsApp (Z-API) POR CLÍNICA — exclusiva do dono/
 * admin do SaaS (requisito de produto: "só parte se cadastrado e configurado
 * pelos donos do SaaS na área da empresa dona").
 *
 * Racional: a conta Z-API (e o Client-Token dela) pertence à empresa dona do
 * SaaS — cada clínica ganha uma INSTÂNCIA (número próprio, dinâmico), mas as
 * credenciais nunca passam pela mão da clínica. A clínica só usufrui: as
 * mensagens saem pelo número dela sem ela ver token nenhum.
 *
 * Gate: SaasAdminPanel (Admin do SaaS) em TODA ação — credencial da conta
 * Z-API é segredo da empresa; Support/Financial não configuram canal.
 */
class WhatsAppController extends Controller
{
    public function __construct(
        private readonly ZApiClient $client,
        private readonly AuditLogger $audit,
    ) {
    }

    public function index(): InertiaResponse
    {
        $this->authorizeSaasEntity();

        $settings = WhatsAppSetting::query()->get()->keyBy('entity_id');

        $clinics = Entity::query()
            ->where('is_client', true)
            ->where('active', true)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(function (Entity $entity) use ($settings) {
                /** @var WhatsAppSetting|null $setting */
                $setting = $settings->get((string) $entity->id);

                return [
                    'id'      => (string) $entity->id,
                    'name'    => $entity->name,
                    'setting' => $setting ? [
                        'active'                    => $setting->active,
                        'confirmation_enabled'      => $setting->confirmation_enabled,
                        'confirmation_hours_before' => $setting->confirmation_hours_before,
                        'survey_enabled'            => $setting->survey_enabled,
                        'survey_delay_hours'        => $setting->survey_delay_hours,
                        'has_credentials'           => $setting->hasCredentials(),
                        'instance_id'               => $setting->instance_id,
                        'webhook_url'               => route('whatsapp.webhooks', $setting->webhook_token),
                    ] : null,
                    'stats' => $setting ? $this->stats((string) $entity->id) : null,
                ];
            })
            ->values();

        return Inertia::render('Panel/Manager/WhatsApp/Index', [
            'clinics' => $clinics,
            'routes'  => [
                'update' => route('manager.whatsapp.update', ['entity' => '__ID__']),
                'test'   => route('manager.whatsapp.test', ['entity' => '__ID__']),
            ],
            't' => trans('whatsapp'),
        ]);
    }

    public function update(Request $request, Entity $entity): JsonResponse
    {
        $this->authorizeSaasEntity();
        abort_unless($entity->isClient(), 404);

        $data = $request->validate([
            'active'                    => ['required', 'boolean'],
            'confirmation_enabled'      => ['required', 'boolean'],
            'confirmation_hours_before' => ['required', 'integer', 'min:1', 'max:168'],
            'survey_enabled'            => ['required', 'boolean'],
            'survey_delay_hours'        => ['required', 'integer', 'min:0', 'max:168'],
            // Credenciais da instância Z-API da clínica: opcionais no update
            // (só toggles) — obrigatórias em conjunto quando qualquer uma vier.
            'instance_id'    => ['nullable', 'string', 'max:100', 'required_with:instance_token,client_token'],
            'instance_token' => ['nullable', 'string', 'max:200', 'required_with:instance_id,client_token'],
            'client_token'   => ['nullable', 'string', 'max:200', 'required_with:instance_id,instance_token'],
        ]);

        $setting = WhatsAppSetting::query()->firstOrNew(['entity_id' => (string) $entity->id]);

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

        $credentialsChanged = false;

        if (! empty($data['instance_id'])) {
            $setting->credentials = [
                'instance_id'    => $data['instance_id'],
                'instance_token' => $data['instance_token'],
                'client_token'   => $data['client_token'],
            ];
            $setting->instance_id = $data['instance_id'];
            $credentialsChanged   = true;
        }

        $setting->save();

        // Auto-configura o webhook "ao receber" na instância Z-API — a URL é
        // única por clínica (webhook_token). Falha não bloqueia o save
        // (instância pode estar desconectada); o teste de conexão acusa.
        $webhookResult = ['ok' => true];

        if ($credentialsChanged && $setting->hasCredentials()) {
            $webhookResult = $this->client->updateReceivedWebhook(
                $setting,
                route('whatsapp.webhooks', $setting->webhook_token),
            );
        }

        $this->audit->recordAdminAction(
            event: 'manager.whatsapp_settings.updated',
            targetEntityId: (string) $entity->id,
            targetUserId: null,
            auditableType: WhatsAppSetting::class,
            auditableId: (string) $setting->id,
            reason: 'Configuração do WhatsApp (Z-API) da clínica pelo SaaS',
            // NUNCA logar os tokens — só o fato de terem sido trocados.
            newValues: [
                'active'               => $data['active'],
                'confirmation_enabled' => $data['confirmation_enabled'],
                'survey_enabled'       => $data['survey_enabled'],
                'credentials_changed'  => $credentialsChanged,
            ],
            request: $request,
        );

        return response()->json([
            'message'         => __('whatsapp.saved'),
            'webhook_ok'      => $webhookResult['ok'],
            'webhook_url'     => route('whatsapp.webhooks', $setting->webhook_token),
            'has_credentials' => $setting->hasCredentials(),
        ]);
    }

    /**
     * Testa a conexão da instância Z-API da clínica (GET /status).
     *
     * Aceita credenciais no corpo da requisição para testar ANTES de salvar
     * (usadas de forma transiente, nunca persistidas nem logadas). Sem corpo,
     * testa as credenciais já armazenadas da clínica.
     */
    public function test(Request $request, Entity $entity): JsonResponse
    {
        $this->authorizeSaasEntity();

        $adHoc = $request->validate([
            'instance_id'    => ['nullable', 'string', 'max:255', 'required_with:instance_token,client_token'],
            'instance_token' => ['nullable', 'string', 'max:255', 'required_with:instance_id,client_token'],
            'client_token'   => ['nullable', 'string', 'max:255', 'required_with:instance_id,instance_token'],
        ]);

        if (! empty($adHoc['instance_id'])) {
            $setting              = new WhatsAppSetting();
            $setting->credentials = [
                'instance_id'    => $adHoc['instance_id'],
                'instance_token' => $adHoc['instance_token'],
                'client_token'   => $adHoc['client_token'],
            ];
        } else {
            $setting = WhatsAppSetting::query()
                ->where('entity_id', (string) $entity->id)
                ->first();
        }

        if (! $setting || ! $setting->hasCredentials()) {
            return response()->json(['ok' => false, 'error' => __('whatsapp.no_credentials')], 422);
        }

        $result = $this->client->status($setting);

        if (! $result['ok']) {
            return response()->json(['ok' => false, 'error' => $result['error'] ?? 'Erro na Z-API.'], 422);
        }

        return response()->json([
            'ok'        => true,
            'connected' => (bool) ($result['connected'] ?? false),
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────────

    private function authorizeSaasEntity(): void
    {
        Gate::authorize(EntityGate::SaasAdminPanel->value, $this->currentSaasEntity());
    }

    private function currentSaasEntity(): Entity
    {
        return Entity::findOrFail(session('selected_entity_id'));
    }

    /**
     * @return array<string, mixed>
     */
    private function stats(string $entityId): array
    {
        $base = WhatsAppMessage::query()
            ->where('entity_id', $entityId)
            ->where('created_at', '>=', now()->subDays(30));

        return [
            'confirmations_sent'     => (clone $base)->where('kind', 'confirmation')->whereIn('status', ['sent', 'answered'])->count(),
            'confirmations_answered' => (clone $base)->where('kind', 'confirmation')->where('status', 'answered')->count(),
            'surveys_sent'           => (clone $base)->where('kind', 'survey')->whereIn('status', ['sent', 'answered'])->count(),
            'surveys_answered'       => (clone $base)->where('kind', 'survey')->where('status', 'answered')->count(),
            'survey_average'         => round((float) ((clone $base)->where('kind', 'survey')->whereNotNull('survey_score')->avg('survey_score') ?? 0), 1),
            'failed'                 => (clone $base)->where('status', 'failed')->count(),
        ];
    }
}
