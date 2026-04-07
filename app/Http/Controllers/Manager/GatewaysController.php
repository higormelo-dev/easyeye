<?php

namespace App\Http\Controllers\Manager;

use App\Enums\Billing\CredentialScope;
use App\Http\Controllers\Controller;
use App\Models\Billing\{EntityGatewayAccess, Gateway, GatewayCredential};
use App\Models\Entity;
use App\Services\Billing\GatewayDefaultService;
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Facades\Cache;

class GatewaysController extends Controller
{
    public function __construct(
        private readonly GatewayDefaultService $defaultService,
    ) {
    }

    public function index(): \Illuminate\View\View
    {
        $gateways = Gateway::query()
            ->withCount([
                'credentials as active_credentials_count' => fn ($q) => $q
                    ->whereNull('entity_id')
                    ->where('scope', 'global')
                    ->where('active', true)
                    ->whereNull('deleted_at'),
                'entityAccess as entities_with_access_count' => fn ($q) => $q
                    ->where('enabled', true),
            ])
            ->orderBy('priority')
            ->get();

        $defaultGateway = $this->defaultService->getDefault();

        $meta = [
            'title'       => __('gateways.title'),
            'breadcrumbs' => [
                ['label' => __('actions.sidemenu.dashboard'), 'url' => route('panel.dashboard'), 'active' => false],
                ['label' => __('gateways.breadcrumb'), 'url' => route('panel.manager.gateways.index'), 'active' => true],
            ],
        ];

        return view('system.manager.gateways.index', compact('gateways', 'defaultGateway', 'meta'));
    }

    /**
     * Define um gateway como padrão do sistema para billing de assinaturas.
     * Valida: gateway ativo + credencial ativa.
     * A troca é atômica e invalida o cache automaticamente.
     */
    public function setDefault(Gateway $gateway): JsonResponse
    {
        try {
            $this->defaultService->setDefault($gateway);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message'    => __('gateways.set_default_success', ['name' => $gateway->name]),
            'gateway_id' => $gateway->id,
        ]);
    }

    /**
     * Lista as credenciais globais de billing (scope=global, sem entity_id).
     * Nunca retorna os valores das chaves — apenas metadados.
     */
    public function credentials(Gateway $gateway): JsonResponse
    {
        $credentials = $gateway->credentials()
            ->whereNull('entity_id')
            ->where('scope', 'global')
            ->orderByDesc('active')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (GatewayCredential $c) => [
                'id'         => $c->id,
                'label'      => $c->label,
                'active'     => $c->active,
                'valid_from' => $c->valid_from?->format('d/m/Y'),
                'valid_to'   => $c->valid_to?->format('d/m/Y'),
                'created_at' => $c->created_at->format('d/m/Y H:i'),
                'has_secret' => ! empty($c->credentials),
                'revoke_url' => route('panel.manager.gateways.credentials.revoke', [$gateway, $c]),
            ]);

        return response()->json(['data' => $credentials]);
    }

    /**
     * Ativa ou desativa um gateway globalmente.
     * Se o gateway desativado for o padrão, limpa o flag is_default.
     */
    public function toggleActive(Gateway $gateway): JsonResponse
    {
        $gateway->update(['active' => ! $gateway->active]);

        // Se desativou o gateway padrão, remove o flag e invalida cache
        if (! $gateway->active && $gateway->is_default) {
            $gateway->update(['is_default' => false]);
            $this->defaultService->forgetCache();
        }

        return response()->json([
            'message' => $gateway->active ? __('gateways.gateway_activated') : __('gateways.gateway_deactivated'),
            'active'  => $gateway->active,
        ]);
    }

    /**
     * Atualiza a prioridade de um gateway (menor = maior prioridade / gateway primário).
     */
    public function updatePriority(Request $request, Gateway $gateway): JsonResponse
    {
        $request->validate([
            'priority' => ['required', 'integer', 'min:1', 'max:999'],
        ]);

        $gateway->update(['priority' => $request->priority]);

        // Invalida cache de fallback (que usa priority como desempate)
        $this->defaultService->forgetCache();

        return response()->json(['message' => __('gateways.priority_updated')]);
    }

    /**
     * Salva credenciais globais de billing para um gateway.
     * Cria nova entrada — nunca atualiza a existente (imutabilidade de credenciais).
     * A credencial anterior é desativada automaticamente.
     */
    public function storeCredential(Request $request, Gateway $gateway): JsonResponse
    {
        $request->validate([
            'label'          => ['nullable', 'string', 'max:120'],
            'secret'         => ['required', 'string', 'min:8'],
            'webhook_secret' => ['nullable', 'string'],
            'valid_from'     => ['nullable', 'date'],
            'valid_to'       => ['nullable', 'date', 'after:valid_from'],
        ]);

        GatewayCredential::query()
            ->where('gateway_id', $gateway->id)
            ->whereNull('entity_id')
            ->where('active', true)
            ->update(['active' => false]);

        GatewayCredential::query()->create([
            'gateway_id'     => $gateway->id,
            'entity_id'      => null,
            'scope'          => CredentialScope::Global->value,
            'label'          => $request->label ?? 'Credencial ' . now()->format('d/m/Y H:i'),
            'credentials'    => ['secret' => $request->secret],
            'webhook_secret' => $request->webhook_secret,
            'active'         => true,
            'valid_from'     => $request->valid_from,
            'valid_to'       => $request->valid_to,
        ]);

        Cache::forget("gateway_credential:{$gateway->code}:global");

        // Nova credencial pode habilitar este gateway como candidato a padrão no fallback
        $this->defaultService->forgetCache();

        return response()->json([
            'message' => __('gateways.credential_saved'),
        ]);
    }

    /**
     * Revoga (desativa) uma credencial global específica.
     */
    public function revokeCredential(Gateway $gateway, GatewayCredential $credential): JsonResponse
    {
        if ($credential->gateway_id !== $gateway->id) {
            abort(404);
        }

        $credential->update(['active' => false]);

        Cache::forget("gateway_credential:{$gateway->code}:global");

        // Revogar credencial pode invalidar o padrão atual
        $this->defaultService->forgetCache();

        return response()->json(['message' => __('gateways.credential_revoked')]);
    }

    /**
     * Lista todas as clínicas (entities clientes) com seu status de acesso ao gateway.
     */
    public function entityAccess(Gateway $gateway): JsonResponse
    {
        $entities = Entity::query()
            ->where('is_client', true)
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->get(['id', 'name', 'code']);

        $accessMap = EntityGatewayAccess::query()
            ->where('gateway_id', $gateway->id)
            ->pluck('enabled', 'entity_id')
            ->map(fn ($v) => (bool) $v);

        $data = $entities->map(fn (Entity $e) => [
            'entity_id'  => $e->id,
            'code'       => $e->code,
            'name'       => $e->name,
            'enabled'    => $accessMap->get((string) $e->id, false),
            'toggle_url' => route('panel.manager.gateways.entity-access.toggle', [$gateway, $e]),
        ]);

        return response()->json(['data' => $data]);
    }

    /**
     * Habilita ou desabilita o acesso de uma clínica a um gateway (toggle).
     * Cria o registro se não existir; atualiza se já existir.
     */
    public function toggleEntityAccess(Gateway $gateway, Entity $entity): JsonResponse
    {
        if ($entity->isSaas()) {
            return response()->json(['message' => __('gateways.saas_entity_forbidden')], 422);
        }

        $access = EntityGatewayAccess::query()->firstOrNew([
            'gateway_id' => $gateway->id,
            'entity_id'  => $entity->id,
        ]);

        $access->enabled = ! $access->enabled;
        $access->save();

        return response()->json([
            'enabled' => $access->enabled,
            'message' => $access->enabled
                ? __('gateways.gateway_enabled_for', ['gateway' => $gateway->name, 'entity' => $entity->name])
                : __('gateways.gateway_disabled_for', ['gateway' => $gateway->name, 'entity' => $entity->name]),
        ]);
    }
}
