<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\{DoctorMedicationPreset, Medicine};
use Illuminate\Http\{JsonResponse, Request};

/**
 * Presets de prescrição do médico (receituário de medicamentos):
 * Recentes | Favoritos + "minha posologia" por medicamento.
 *
 * Rotas com middleware entity.role:doctor (prescrição é ato médico).
 * Escopo sempre pelo entity_user da sessão — um médico nunca lê/escreve
 * preset de outro, nem de outra clínica.
 */
class MedicationPresetsController extends Controller
{
    /** Abas do modal: mais recentes + favoritos, já com o medicamento. */
    public function index(): JsonResponse
    {
        $base = DoctorMedicationPreset::query()
            ->where('entity_user_id', session('selected_entity_user_id'))
            ->with('medicine.presentation:id,name');

        $recents = (clone $base)
            ->whereNotNull('last_used_at')
            ->orderByDesc('last_used_at')
            ->limit(10)
            ->get();

        $favorites = (clone $base)
            ->where('is_favorite', true)
            ->orderByDesc('usage_count')
            ->limit(20)
            ->get();

        return response()->json([
            'recents'   => $recents->map(fn ($p) => $this->serialize($p))->filter()->values(),
            'favorites' => $favorites->map(fn ($p) => $this->serialize($p))->filter()->values(),
        ]);
    }

    /** Registra uso (contadores das abas). Chamado ao adicionar à receita. */
    public function recordUse(Request $request): JsonResponse
    {
        $preset = $this->upsert($request);

        $preset->increment('usage_count');
        $preset->forceFill(['last_used_at' => now()])->save();

        return response()->json(['ok' => true]);
    }

    /** "Salvar como minha posologia" (null/vazio remove a personalizada). */
    public function savePosology(Request $request): JsonResponse
    {
        $data = $request->validate([
            'posology' => ['nullable', 'string', 'max:2000'],
        ]);

        $preset           = $this->upsert($request);
        $preset->posology = trim((string) ($data['posology'] ?? '')) ?: null;
        $preset->save();

        return response()->json([
            'ok'       => true,
            'message'  => __('actions.medication_presets.posology_saved'),
            'posology' => $preset->posology,
        ]);
    }

    public function toggleFavorite(Request $request): JsonResponse
    {
        $data = $request->validate([
            'favorite' => ['required', 'boolean'],
        ]);

        $preset              = $this->upsert($request);
        $preset->is_favorite = $data['favorite'];
        $preset->save();

        return response()->json(['ok' => true, 'favorite' => $preset->is_favorite]);
    }

    // ──────────────────────────────────────────────────────────────────────

    /** Valida o medicamento (visível pra esta clínica) e upserta o preset. */
    private function upsert(Request $request): DoctorMedicationPreset
    {
        $validated = $request->validate([
            'medicine_id' => ['required', 'uuid'],
        ]);

        $entityId = (string) session('selected_entity_id');

        // Mesmo escopo do autocomplete: catálogo global (null) + da clínica.
        $medicine = Medicine::query()
            ->where(fn ($q) => $q->where('entity_id', $entityId)->orWhereNull('entity_id'))
            ->where('active', true)
            ->findOrFail($validated['medicine_id']);

        return DoctorMedicationPreset::firstOrCreate([
            'entity_user_id' => (string) session('selected_entity_user_id'),
            'medicine_id'    => $medicine->id,
        ], [
            'entity_id' => $entityId,
        ]);
    }

    private function serialize(DoctorMedicationPreset $preset): ?array
    {
        $m = $preset->medicine;

        // Medicamento removido/desativado do catálogo: some das listas.
        if (! $m || ! $m->active) {
            return null;
        }

        return [
            'id'           => $m->id,
            'name'         => $m->name,
            'dosage'       => $m->dosage,
            'frequency'    => $m->frequency,
            'duration'     => $m->duration,
            'instructions' => $m->instructions,
            'presentation' => $m->presentation?->name,
            'my_posology'  => $preset->posology,
            'is_favorite'  => $preset->is_favorite,
            'usage_count'  => $preset->usage_count,
        ];
    }
}
