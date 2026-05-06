<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Indication;
use Illuminate\Http\{JsonResponse, Request};

/**
 * F6 — autocomplete de indicações clínicas (entidade + globais).
 */
class IndicationSearchController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $q = $request->string('q')->trim()->value();

        if (mb_strlen($q, 'UTF-8') < 2) {
            return response()->json([]);
        }

        $entityId = session('selected_entity_id');

        $results = Indication::query()
            ->where(fn ($q2) => $q2->where('entity_id', $entityId)->orWhereNull('entity_id'))
            ->where('active', true)
            ->where('description', 'ILIKE', "%{$q}%")
            ->orderBy('description')
            ->limit(20)
            ->get(['id', 'description']);

        return response()->json(
            $results->map(fn (Indication $i) => [
                'id'          => $i->id,
                'description' => $i->description,
            ]),
        );
    }
}
