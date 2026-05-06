<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Procedure;
use Illuminate\Http\{JsonResponse, Request};

/**
 * F6 — autocomplete de procedimentos clínicos (escopo entidade ativa + globais).
 *
 * Consumido pelo modal "Solicitação de Procedimentos" (Alpine).
 */
class ProcedureSearchController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $q = $request->string('q')->trim()->value();

        if (mb_strlen($q, 'UTF-8') < 2) {
            return response()->json([]);
        }

        $entityId = session('selected_entity_id');

        $results = Procedure::query()
            ->where(fn ($q2) => $q2->where('entity_id', $entityId)->orWhereNull('entity_id'))
            ->where('active', true)
            ->where(fn ($q2) => $q2->where('name', 'ILIKE', "%{$q}%")
                ->orWhere('code', 'ILIKE', "%{$q}%"))
            ->orderBy('name')
            ->limit(20)
            ->get(['id', 'code', 'name']);

        return response()->json(
            $results->map(fn (Procedure $p) => [
                'id'   => $p->id,
                'code' => $p->code,
                'name' => $p->name,
            ]),
        );
    }
}
