<?php

declare(strict_types=1);

namespace App\Http\Controllers\Manager;

use App\Domains\AI\Models\AiModelPrice;
use App\Enums\AI\AiProvider;
use App\Enums\EntityGate;
use App\Http\Controllers\Controller;
use App\Models\Entity;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Catálogo de modelos e preços de IA (ai_model_prices), gerenciado pelo dono
 * do SaaS SEM deploy: cadastrar o preço aqui faz o modelo aparecer no select
 * de "Modelos" da página Provedores de IA. O preço é a garantia de cobrança —
 * modelo sem preço nunca é elegível (a execução falharia após gastar).
 *
 * Sem delete físico: desativar preserva o histórico de liquidação de créditos.
 */
class AiModelPricesController extends Controller
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    /** Listagem completa do catálogo (consumida pela página Provedores de IA). */
    public function index(): JsonResponse
    {
        $this->authorizeSaas();

        return response()->json(['prices' => $this->catalog()]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeSaas();

        $validated = $request->validate($this->rules());

        $exists = AiModelPrice::query()
            ->where('provider', $validated['provider'])
            ->where('model', $validated['model'])
            ->exists();

        if ($exists) {
            return response()->json(['message' => __('manager_ai.price_duplicate')], 422);
        }

        $price = AiModelPrice::query()->create([
            ...$validated,
            'effective_from' => now(),
            'active'         => true,
        ]);

        $this->auditChange($request, 'store', $price, null);

        return response()->json([
            'message' => __('manager_ai.price_saved'),
            'prices'  => $this->catalog(),
        ]);
    }

    public function update(Request $request, AiModelPrice $aiModelPrice): JsonResponse
    {
        $this->authorizeSaas();

        // Provider/modelo são a identidade da linha (referenciada por nome nas
        // chamadas já liquidadas) — só os PREÇOS e o status são editáveis.
        $validated = $request->validate([
            'input_usd_per_million'     => ['required', 'numeric', 'min:0', 'max:100000'],
            'output_usd_per_million'    => ['required', 'numeric', 'min:0', 'max:100000'],
            'reasoning_usd_per_million' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'tool_call_usd'             => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'active'                    => ['required', 'boolean'],
        ]);

        $old = $aiModelPrice->only([
            'input_usd_per_million', 'output_usd_per_million',
            'reasoning_usd_per_million', 'tool_call_usd', 'active',
        ]);

        $aiModelPrice->update($validated);

        $this->auditChange($request, 'update', $aiModelPrice, $old);

        return response()->json([
            'message' => __('manager_ai.price_saved'),
            'prices'  => $this->catalog(),
        ]);
    }

    /** @return array<string, mixed> */
    private function rules(): array
    {
        $allCodes = array_map(static fn (AiProvider $p) => $p->value, AiProvider::cases());

        return [
            'provider'                  => ['required', 'string', Rule::in($allCodes)],
            'model'                     => ['required', 'string', 'max:120', 'regex:/^[a-z0-9][a-z0-9._-]*$/i'],
            'input_usd_per_million'     => ['required', 'numeric', 'min:0', 'max:100000'],
            'output_usd_per_million'    => ['required', 'numeric', 'min:0', 'max:100000'],
            'reasoning_usd_per_million' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'tool_call_usd'             => ['nullable', 'numeric', 'min:0', 'max:1000'],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function catalog(): array
    {
        return AiModelPrice::query()
            ->orderBy('provider')
            ->orderBy('model')
            ->get()
            ->map(fn (AiModelPrice $p) => [
                'id'                        => (string) $p->id,
                'provider'                  => $p->provider->value,
                'provider_label'            => $p->provider->label(),
                'model'                     => $p->model,
                'input_usd_per_million'     => (float) $p->input_usd_per_million,
                'output_usd_per_million'    => (float) $p->output_usd_per_million,
                'reasoning_usd_per_million' => $p->reasoning_usd_per_million !== null ? (float) $p->reasoning_usd_per_million : null,
                'tool_call_usd'             => $p->tool_call_usd !== null ? (float) $p->tool_call_usd : null,
                'active'                    => (bool) $p->active,
                'effective_from'            => $p->effective_from?->format('d/m/Y'),
            ])->all();
    }

    private function auditChange(Request $request, string $action, AiModelPrice $price, ?array $old): void
    {
        $this->audit->recordAdminAction(
            event: "manager.ai_model_prices.{$action}",
            targetEntityId: null,
            targetUserId: null,
            auditableType: 'ai_model_price',
            auditableId: (string) $price->id,
            reason: __('manager_ai.price_audit_reason'),
            newValues: $price->only([
                'provider', 'model', 'input_usd_per_million', 'output_usd_per_million',
                'reasoning_usd_per_million', 'tool_call_usd', 'active',
            ]),
            request: $request,
            oldValues: $old,
        );
    }

    private function authorizeSaas(): void
    {
        $entity = Entity::query()->findOrFail(session('selected_entity_id'));
        Gate::authorize(EntityGate::SaasAdminPanel->value, $entity);
    }
}
