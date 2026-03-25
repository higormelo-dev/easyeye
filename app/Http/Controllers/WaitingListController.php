<?php

namespace App\Http\Controllers;

use App\Http\Requests\WaitingListRequest;
use App\Models\WaitingList;
use Illuminate\Http\{JsonResponse, Request};

class WaitingListController extends Controller
{
    /**
     * Returns active waiting list entries for the current entity,
     * optionally filtered by doctor.
     */
    public function index(Request $request): JsonResponse
    {
        $entityId = session('selected_entity_id');
        $doctorId = $request->input('doctor_id');

        $query = WaitingList::where('entity_id', $entityId)
            ->where('active', true)
            ->with(['patient', 'doctor', 'covenant', 'visitType'])
            ->orderBy('position')
            ->orderBy('created_at');

        if ($doctorId && $doctorId !== 'tudo') {
            $query->where('doctor_id', $doctorId);
        }

        $entries = $query->get();

        return response()->json([
            'data'  => $entries->map(fn ($wl) => $this->format($wl)),
            'count' => $entries->count(),
        ]);
    }

    /**
     * Adds a patient to the waiting list.
     * Position is assigned as max + 1 for the given doctor queue.
     */
    public function store(WaitingListRequest $request): JsonResponse
    {
        $entityId = session('selected_entity_id');

        $maxPosition = WaitingList::where('entity_id', $entityId)
            ->where('doctor_id', $request->input('doctor_id'))
            ->where('active', true)
            ->max('position') ?? 0;

        $entry = WaitingList::create(array_merge(
            $request->validated(),
            ['entity_id' => $entityId, 'position' => $maxPosition + 1],
        ));

        $entry->load(['patient', 'doctor', 'covenant', 'visitType']);

        return response()->json([
            'message' => 'Paciente adicionado à lista de espera.',
            'data'    => $this->format($entry),
        ], 201);
    }

    /**
     * Removes an entry from the waiting list (soft delete).
     */
    public function destroy(WaitingList $waitingList): JsonResponse
    {
        abort_if($waitingList->entity_id !== session('selected_entity_id'), 403);

        $waitingList->delete();

        return response()->json(['message' => 'Removido da lista de espera.']);
    }

    /**
     * Updates the display order of waiting list entries.
     * Expects `ids` as an ordered array of UUIDs.
     */
    public function reorder(Request $request): JsonResponse
    {
        $request->validate([
            'ids'   => ['required', 'array'],
            'ids.*' => ['uuid'],
        ]);

        $entityId = session('selected_entity_id');

        foreach ($request->input('ids') as $position => $id) {
            WaitingList::where('id', $id)
                ->where('entity_id', $entityId)
                ->where('active', true)
                ->update(['position' => $position + 1]);
        }

        return response()->json(['message' => 'Ordem atualizada.']);
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private function format(WaitingList $wl): array
    {
        return [
            'id'                   => $wl->id,
            'position'             => $wl->position,
            'full_name'            => $wl->full_name,
            'patient_id'           => $wl->patient_id,
            'doctor_id'            => $wl->doctor_id,
            'doctor_name'          => $wl->doctor?->abbreviation ?? $wl->doctor?->user_name ?? '',
            'covenant_id'          => $wl->covenant_id,
            'covenant_name'        => $wl->covenant?->name ?? '',
            'visit_id'             => $wl->visit_id,
            'visit_name'           => $wl->visitType?->name ?? '',
            'telephone'            => $wl->telephone,
            'cellphone'            => $wl->cellphone,
            'cellphone_whatsapp'   => $wl->cellphone_whatsapp,
            'notes'                => $wl->notes,
            'preferred_date_from'  => $wl->preferred_date_from?->format('d/m/Y'),
            'preferred_date_until' => $wl->preferred_date_until?->format('d/m/Y'),
            'created_at'           => $wl->created_at->format('d/m/Y'),
        ];
    }
}
