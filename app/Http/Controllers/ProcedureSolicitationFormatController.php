<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\{Indication, Procedure};
use App\Services\ProcedureSolicitationService;
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Validation\Rule;

/**
 * F6 — devolve linha formatada para append no textarea da solicitação.
 *
 * Aceita dois `kind`s: `procedure` (com tipo opcional) ou `indication`.
 * Frontend só concatena `line` no textarea.
 */
class ProcedureSolicitationFormatController extends Controller
{
    public function __construct(
        private readonly ProcedureSolicitationService $service,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'kind' => ['required', Rule::in(['procedure', 'indication'])],
            'id'   => ['required', 'uuid'],
            'type' => ['nullable', 'string', Rule::in(ProcedureSolicitationService::typeKeys())],
        ]);

        $entityId = session('selected_entity_id');

        if ($validated['kind'] === 'procedure') {
            $procedure = Procedure::query()
                ->where(fn ($q) => $q->where('entity_id', $entityId)->orWhereNull('entity_id'))
                ->where('active', true)
                ->findOrFail($validated['id']);

            return response()->json([
                'line' => $this->service->formatProcedureLine($procedure, $validated['type'] ?? null),
            ]);
        }

        $indication = Indication::query()
            ->where(fn ($q) => $q->where('entity_id', $entityId)->orWhereNull('entity_id'))
            ->where('active', true)
            ->findOrFail($validated['id']);

        return response()->json([
            'line' => $this->service->formatIndicationLine($indication),
        ]);
    }
}
