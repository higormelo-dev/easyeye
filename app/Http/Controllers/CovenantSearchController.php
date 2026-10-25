<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Covenant;
use Illuminate\Http\{JsonResponse, Request};

/**
 * Busca de convênios para o de-para da importação de pacientes: convênios ativos, não excluídos, da clínica da sessão
 * + os globais. Só dados do catálogo (sem dado de paciente). Sem termo: os primeiros por nome; termo com 3+ dígitos
 * também casa o código do convênio e o registro na ANS.
 */
class CovenantSearchController extends Controller
{
    private const LIMIT = 30;

    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate(['q' => ['nullable', 'string', 'max:100']]);

        $entityId = (string) session('selected_entity_id');
        $term     = trim((string) ($data['q'] ?? ''));
        $digits   = preg_replace('/\D/', '', $term) ?? '';

        $covenants = Covenant::withoutGlobalScopes()
            ->whereNull('covenants.deleted_at')
            ->where('covenants.active', true)
            ->where(fn ($query) => $query->where('covenants.entity_id', $entityId)->orWhereNull('covenants.entity_id'))
            ->when($term !== '', fn ($query) => $query->where(function ($where) use ($term, $digits): void {
                $where->whereLikeUnaccent('covenants.name', addcslashes($term, '\\%_'));

                if (strlen($digits) >= 3) {
                    $where->orWhere('covenants.code', 'like', '%' . $digits . '%')
                        ->orWhere('covenants.ans_registry', 'like', $digits . '%');
                }
            }))
            // Convênios da clínica primeiro, depois por nome.
            ->orderByRaw('covenants.entity_id IS NULL')
            ->orderBy('covenants.name')
            ->orderBy('covenants.id')
            ->limit(self::LIMIT)
            ->get(['covenants.id', 'covenants.name', 'covenants.code', 'covenants.entity_id', 'covenants.active']);

        return response()->json(['data' => $covenants->map(fn (Covenant $covenant): array => [
            'id'        => (string) $covenant->id,
            'label'     => (string) $covenant->name,
            'sub_label' => $covenant->code,
            'name'      => (string) $covenant->name,
            'code'      => $covenant->code,
            'is_own'    => $covenant->entity_id !== null,
            'active'    => (bool) $covenant->active,
        ])->values()]);
    }
}
