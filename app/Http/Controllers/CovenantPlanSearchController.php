<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\{Covenant, CovenantPlan};
use Illuminate\Http\{JsonResponse, Request};

/**
 * Busca de planos para o cadastro do paciente: planos ativos do convênio
 * escolhido — catálogo global (ANS/manager) + os próprios da clínica. Só
 * dados do produto (sem dado de paciente).
 *
 * Sem termo: os primeiros por nome (operadora pequena cabe inteira; a maior
 * tem ~4,5 mil planos e precisa da busca).
 */
class CovenantPlanSearchController extends Controller
{
    private const LIMIT = 30;

    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'covenant_id' => ['required', 'uuid'],
            'q'           => ['nullable', 'string', 'max:100'],
        ]);

        $entityId = (string) session('selected_entity_id');

        // EntityScope (painel): só convênio global ou da própria clínica.
        $covenant = Covenant::query()->whereKey($data['covenant_id'])->first();

        if (! $covenant) {
            return response()->json(['data' => []]);
        }

        $term   = trim((string) ($data['q'] ?? ''));
        $digits = preg_replace('/\D/', '', $term) ?? '';

        $plans = CovenantPlan::query()
            ->selectableFor((string) $covenant->id, $entityId)
            ->when($term !== '', fn ($q) => $q->where(function ($w) use ($term, $digits) {
                $w->whereLikeUnaccent('covenant_plans.name', addcslashes($term, '\\%_'));

                if (strlen($digits) >= 3) {
                    $w->orWhere('covenant_plans.ans_code', 'like', $digits . '%');
                }
            }))
            // Planos da clínica primeiro (cadastrados por ela por algum motivo).
            ->orderByRaw('covenant_plans.entity_id IS NULL')
            ->orderBy('covenant_plans.name')
            ->orderBy('covenant_plans.id')
            ->limit(self::LIMIT)
            ->get();

        return response()->json(['data' => $plans->map(fn (CovenantPlan $p) => $p->toOption())->values()]);
    }
}
