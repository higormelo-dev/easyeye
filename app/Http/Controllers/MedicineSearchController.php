<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\{DoctorMedicationPreset, Medicine};
use App\Services\Medicines\MedicineCatalogSearch;
use Illuminate\Http\{JsonResponse, Request};

/**
 * Autocomplete de medicamentos do receituário (F5): catálogo global
 * (manager → Medicamentos) + itens da clínica, por nome comercial ou
 * genérico. Ordem e regra de busca em MedicineCatalogSearch; até 20 itens
 * p/ não estourar o dropdown.
 */
class MedicineSearchController extends Controller
{
    public function __construct(
        private readonly MedicineCatalogSearch $catalogSearch,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $q = $request->string('q')->trim()->value();

        if (mb_strlen($q, 'UTF-8') < 2) {
            return response()->json([]);
        }

        $entityId = session('selected_entity_id');

        // Catálogo global (manager → Medicamentos: curados + CMED/Anvisa) +
        // itens da própria clínica. Busca por nome comercial OU genérico,
        // palavra a palavra (MedicineCatalogSearch).
        $query = Medicine::query()
            ->with('presentation:id,name')
            ->where(function ($q2) use ($entityId) {
                $q2->where('entity_id', $entityId)->orWhereNull('entity_id');
            })
            ->where('active', true);

        $this->catalogSearch->apply($query, $q);
        $this->catalogSearch->rank($query, $q);

        $results = $query->limit(20)->get([
            'id', 'name', 'dosage', 'frequency', 'duration', 'instructions', 'medicine_presentation_id',
            'active_ingredient', 'concentration', 'pharmaceutical_form', 'presentation_detail',
            'laboratory', 'regulatory_category', 'source', 'is_ophthalmic',
        ]);

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
                'presentation' => $m->presentation?->name ?? $m->formLabel(),
                'my_posology'  => $presets->get($m->id)?->posology,
                'is_favorite'  => (bool) ($presets->get($m->id)?->is_favorite ?? false),
                // Diferenciar apresentações na lista (genérico, concentração,
                // embalagem, laboratório, genérico/similar/novo).
                'active_ingredient'   => $m->active_ingredient,
                'concentration'       => $m->concentration,
                'presentation_detail' => $m->presentation_detail,
                'laboratory'          => $m->laboratory,
                'category'            => $m->regulatory_category,
                'is_ophthalmic'       => (bool) $m->is_ophthalmic,
            ]),
        );
    }
}
