<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\{DoctorMedicationPreset, Medicine};
use Illuminate\Http\{JsonResponse, Request};

/**
 * Autocomplete de medicamentos da entidade ativa.
 *
 * Consumido pelo modal "Receituário de Medicamentos" (F5) — Alpine
 * dispara a busca enquanto o médico digita. Resultados ordenados por nome,
 * limitados a 20 itens p/ não estourar o dropdown.
 */
class MedicineSearchController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $q = $request->string('q')->trim()->value();

        if (mb_strlen($q, 'UTF-8') < 2) {
            return response()->json([]);
        }

        $entityId = session('selected_entity_id');

        $results = Medicine::query()
            ->with('presentation:id,name')
            ->where(function ($q2) use ($entityId) {
                $q2->where('entity_id', $entityId)->orWhereNull('entity_id');
            })
            ->where('active', true)
            ->where(function ($q2) use ($q) {
                $q2->where('name', 'ILIKE', "%{$q}%")
                    ->orWhere('dosage', 'ILIKE', "%{$q}%");
            })
            ->orderBy('name')
            ->limit(20)
            ->get(['id', 'name', 'dosage', 'frequency', 'duration', 'instructions', 'medicine_presentation_id']);

        // Preset do médico logado (minha posologia/favorito) por medicamento —
        // prefill da sugestão de posologia no modal do receituário.
        $presets = DoctorMedicationPreset::query()
            ->where('entity_user_id', session('selected_entity_user_id'))
            ->whereIn('medicine_id', $results->pluck('id'))
            ->get()
            ->keyBy('medicine_id');

        return response()->json(
            $results->map(fn (Medicine $m) => [
                'id'           => $m->id,
                'name'         => $m->name,
                'dosage'       => $m->dosage,
                'frequency'    => $m->frequency,
                'duration'     => $m->duration,
                'instructions' => $m->instructions,
                'presentation' => $m->presentation?->name,
                'my_posology'  => $presets->get($m->id)?->posology,
                'is_favorite'  => (bool) ($presets->get($m->id)?->is_favorite ?? false),
            ]),
        );
    }
}
