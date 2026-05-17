<?php

namespace App\Http\Controllers\Manager;

use App\Enums\Billing\CredentialScope;
use App\Http\Controllers\Controller;
use App\Models\Billing\{EntityGatewayAccess, Gateway, GatewayCredential};
use App\Models\Entity;
use App\Services\Billing\GatewayDefaultService;
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Facades\Cache;
use Inertia\{Inertia, Response};

class GatewaysController extends Controller
{
    public function __construct(
        private readonly GatewayDefaultService $defaultService,
    ) {
    }

    public function index(): Response
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

        return Inertia::render('Panel/Manager/Gateways/Index', [
            'gateways'       => $gateways->map(fn ($g) => $this->toRow($g))->values(),
            'defaultGateway' => $defaultGateway ? $this->toRow($defaultGateway) : null,
            't'              => trans('gateways'),
        ]);
    }

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
                'revoke_url' => route('manager.gateways.credentials.revoke', [$gateway, $c]),
            ]);

        return response()->json(['data' => $credentials]);
    }

    public function toggleActive(Gateway $gateway): JsonResponse
    {
        $gateway->update(['active' => ! $gateway->active]);

        if (! $gateway->active && $gateway->is_default) {
            $gateway->update(['is_default' => false]);
            $this->defaultService->forgetCache();
        }

        return response()->json([
            'message' => $gateway->active ? __('gateways.gateway_activated') : __('gateways.gateway_deactivated'),
            'active'  => $gateway->active,
        ]);
    }

    public function updatePriority(Request $request, Gateway $gateway): JsonResponse
    {
        $request->validate([
            'priority' => ['required', 'integer', 'min:1', 'max:999'],
        ]);

        $gateway->update(['priority' => $request->priority]);
        $this->defaultService->forgetCache();

        return response()->json(['message' => __('gateways.priority_updated')]);
    }

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
        $this->defaultService->forgetCache();

        return response()->json(['message' => __('gateways.credential_saved')]);
    }

    public function revokeCredential(Gateway $gateway, GatewayCredential $credential): JsonResponse
    {
        if ($credential->gateway_id !== $gateway->id) {
            abort(404);
        }

        $credential->update(['active' => false]);

        Cache::forget("gateway_credential:{$gateway->code}:global");
        $this->defaultService->forgetCache();

        return response()->json(['message' => __('gateways.credential_revoked')]);
    }

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
            'toggle_url' => route('manager.gateways.entity-access.toggle', [$gateway, $e]),
        ]);

        return response()->json(['data' => $data]);
    }

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

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function toRow(Gateway $g): array
    {
        $credCount   = $g->active_credentials_count  ?? 0;
        $clinicCount = $g->entities_with_access_count ?? 0;

        return [
            'id'                        => $g->id,
            'code'                      => $g->code,
            'name'                      => $g->name,
            'active'                    => (bool) $g->active,
            'is_default'                => (bool) $g->is_default,
            'priority'                  => $g->priority,
            'supports_subscriptions'    => (bool) $g->supports_subscriptions,
            'supports_one_time_charges' => (bool) $g->supports_one_time_charges,
            'supports_refunds'          => (bool) $g->supports_refunds,
            'supports_webhooks'         => (bool) $g->supports_webhooks,
            'active_credentials_count'  => $credCount,
            'credentials_label'         => $credCount > 0
                ? trans_choice('gateways.credentials_active', $credCount, ['count' => $credCount])
                : null,
            'entities_with_access_count' => $clinicCount,
            'clinics_label'              => $clinicCount > 0
                ? trans_choice('gateways.clinics_count', $clinicCount, ['count' => $clinicCount])
                : null,
            'can_be_default'             => (bool) $g->active && $credCount > 0,
            // Route URLs
            'set_default_url'       => route('manager.gateways.set-default',    $g),
            'toggle_active_url'     => route('manager.gateways.toggle-active',  $g),
            'priority_url'          => route('manager.gateways.priority',        $g),
            'credentials_url'       => route('manager.gateways.credentials',     $g),
            'credentials_store_url' => route('manager.gateways.credentials.store', $g),
            'entity_access_url'     => route('manager.gateways.entity-access',   $g),
        ];
    }
}
