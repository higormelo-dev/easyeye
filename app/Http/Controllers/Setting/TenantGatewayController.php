<?php

declare(strict_types=1);

namespace App\Http\Controllers\Setting;

use App\Enums\Billing\CredentialScope;
use App\Http\Controllers\Controller;
use App\Models\Billing\{Gateway, GatewayCredential};
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Facades\Cache;
use Inertia\{Inertia, Response as InertiaResponse};

class TenantGatewayController extends Controller
{
    public function index(): InertiaResponse
    {
        $entityId = (string) session('selected_entity_id');

        $gateways = Gateway::query()
            ->where('active', true)
            ->whereHas(
                'entityAccess',
                fn ($q) => $q
                    ->where('entity_id', $entityId)
                    ->where('enabled', true),
            )
            ->withCount([
                'credentials' => fn ($q) => $q
                    ->where('entity_id', $entityId)
                    ->where('scope', 'tenant')
                    ->whereNull('deleted_at'),
            ])
            ->orderBy('priority')
            ->get();

        return Inertia::render('Panel/Settings/Gateways/Index', [
            'breadcrumbs' => [
                ['label' => __('actions.sidemenu.dashboard'), 'url' => route('panel.dashboard'), 'active' => false],
                ['label' => __('actions.sidemenu.payment_gateways'), 'url' => '#', 'active' => true],
            ],
            'gateways' => $gateways->map(fn (Gateway $g) => [
                'id'                    => (string) $g->id,
                'code'                  => $g->code,
                'name'                  => $g->name,
                'priority'              => $g->priority,
                'credentials_count'     => (int) $g->credentials_count,
                'has_active_credential' => $g->credentials_count > 0,
                'credentials_url'       => route('panel.setting.gateways.credentials', $g->id),
                'credentials_store_url' => route('panel.setting.gateways.credentials.store', $g->id),
            ]),
        ]);
    }

    /**
     * Lista as credenciais do tenant para um gateway (apenas metadados, sem valores das chaves).
     */
    public function credentials(Gateway $gateway): JsonResponse
    {
        $entityId = (string) session('selected_entity_id');

        $this->abortUnlessAllowed($gateway, $entityId);

        $credentials = $gateway->credentials()
            ->where('entity_id', $entityId)
            ->where('scope', 'tenant')
            ->whereNull('deleted_at')
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
            ]);

        return response()->json(['data' => $credentials]);
    }

    /**
     * Salva credenciais do tenant para um gateway.
     * Cria nova entrada — imutabilidade de credenciais.
     * A credencial anterior do mesmo gateway/tenant é desativada.
     */
    public function storeCredential(Request $request, Gateway $gateway): JsonResponse
    {
        $entityId = (string) session('selected_entity_id');

        $this->abortUnlessAllowed($gateway, $entityId);

        $request->validate([
            'label'          => ['nullable', 'string', 'max:120'],
            'secret'         => ['required', 'string', 'min:8'],
            'webhook_secret' => ['nullable', 'string'],
            'valid_from'     => ['nullable', 'date'],
            'valid_to'       => ['nullable', 'date', 'after:valid_from'],
        ]);

        GatewayCredential::query()
            ->where('gateway_id', $gateway->id)
            ->where('entity_id', $entityId)
            ->where('scope', 'tenant')
            ->where('active', true)
            ->update(['active' => false]);

        GatewayCredential::query()->create([
            'gateway_id'     => $gateway->id,
            'entity_id'      => $entityId,
            'scope'          => CredentialScope::Tenant->value,
            'label'          => $request->label ?? 'Credencial ' . now()->format('d/m/Y H:i'),
            'credentials'    => ['secret' => $request->secret],
            'webhook_secret' => $request->webhook_secret,
            'active'         => true,
            'valid_from'     => $request->valid_from,
            'valid_to'       => $request->valid_to,
        ]);

        Cache::forget("gateway_credential:{$gateway->code}:{$entityId}");

        return response()->json([
            'message' => 'Credencial salva com sucesso. A credencial anterior foi desativada.',
        ]);
    }

    /**
     * Revoga (desativa) uma credencial do tenant.
     */
    public function revokeCredential(Gateway $gateway, GatewayCredential $credential): JsonResponse
    {
        $entityId = (string) session('selected_entity_id');

        if ($credential->gateway_id !== $gateway->id || (string) $credential->entity_id !== $entityId) {
            abort(404);
        }

        $credential->update(['active' => false]);

        Cache::forget("gateway_credential:{$gateway->code}:{$entityId}");

        return response()->json(['message' => 'Credencial revogada.']);
    }

    /**
     * Garante que a clínica tem acesso habilitado ao gateway pelo SaaS owner.
     */
    private function abortUnlessAllowed(Gateway $gateway, string $entityId): void
    {
        $allowed = $gateway->entityAccess()
            ->where('entity_id', $entityId)
            ->where('enabled', true)
            ->exists();

        if (! $allowed) {
            abort(403, 'Sua clínica não tem acesso a este gateway.');
        }
    }
}
