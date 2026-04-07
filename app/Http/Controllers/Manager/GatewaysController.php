<?php

namespace App\Http\Controllers\Manager;

use App\Enums\Billing\CredentialScope;
use App\Http\Controllers\Controller;
use App\Models\Billing\{Gateway, GatewayCredential};
use App\Services\Billing\GatewayCredentialResolver;
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;

class GatewaysController extends Controller
{
    public function index(): \Illuminate\View\View
    {
        $gateways = Gateway::query()
            ->withCount(['credentials' => fn ($q) => $q->whereNull('entity_id')->where('scope', 'global')])
            ->orderBy('priority')
            ->get();

        // Verifica quais gateways estão configurados via .env (fonte primária)
        $envConfigured = collect($gateways)->mapWithKeys(fn ($g) => [
            $g->code => env(match ($g->code) {
                'asaas'       => 'ASAAS_SECRET',
                'infinitepay' => 'INFINITEPAY_SECRET',
                'mercadopago' => 'MERCADOPAGO_SECRET',
                'pagarme'     => 'PAGARME_SECRET',
                'stripe_br'   => 'STRIPE_BR_SECRET',
                'pagbank'     => 'PAGBANK_SECRET',
                default       => '',
            }) !== null,
        ]);

        return view('system.manager.gateways.index', compact('gateways', 'envConfigured'));
    }

    /**
     * Lista as credenciais de BILLING (globais, scope=global, sem entity_id).
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
            ]);

        return response()->json(['data' => $credentials]);
    }

    /**
     * Ativa ou desativa um gateway.
     */
    public function toggleActive(Gateway $gateway): JsonResponse
    {
        $gateway->update(['active' => ! $gateway->active]);

        return response()->json([
            'message' => $gateway->active ? 'Gateway ativado.' : 'Gateway desativado.',
            'active'  => $gateway->active,
        ]);
    }

    /**
     * Atualiza a prioridade de um gateway.
     */
    public function updatePriority(Request $request, Gateway $gateway): JsonResponse
    {
        $request->validate([
            'priority' => ['required', 'integer', 'min:1', 'max:999'],
        ]);

        $gateway->update(['priority' => $request->priority]);

        return response()->json(['message' => 'Prioridade atualizada.']);
    }

    /**
     * Salva credenciais globais de um gateway.
     * Cria uma nova entrada — nunca atualiza a existente (imutabilidade de credenciais).
     * A credencial antiga é desativada automaticamente.
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

        // Desativa credenciais globais anteriores do mesmo gateway
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

        // Invalida cache de credenciais deste gateway
        Cache::forget("gateway_credential:{$gateway->code}:global");

        return response()->json([
            'message' => 'Credencial salva com sucesso. A credencial anterior foi desativada.',
        ]);
    }

    /**
     * Revoga (desativa) uma credencial específica.
     */
    public function revokeCredential(Gateway $gateway, GatewayCredential $credential): JsonResponse
    {
        if ($credential->gateway_id !== $gateway->id) {
            abort(404);
        }

        $credential->update(['active' => false]);

        Cache::forget("gateway_credential:{$gateway->code}:global");

        return response()->json(['message' => 'Credencial revogada.']);
    }
}
