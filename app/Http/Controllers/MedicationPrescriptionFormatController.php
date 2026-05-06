<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Medicine;
use App\Services\MedicationPrescriptionService;
use Illuminate\Http\{JsonResponse, Request};

/**
 * Devolve a linha formatada de prescrição p/ um medicamento, p/ append no
 * textarea do builder Alpine (F5).
 *
 * Backend assume regra de formatação. Frontend só faz `value += line`.
 */
class MedicationPrescriptionFormatController extends Controller
{
    public function __construct(
        private readonly MedicationPrescriptionService $service,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'medicine_id' => ['required', 'uuid', 'exists:medicines,id'],
        ]);

        $entityId = session('selected_entity_id');

        $medicine = Medicine::with('presentation')
            ->where(fn ($q) => $q->where('entity_id', $entityId)->orWhereNull('entity_id'))
            ->where('active', true)
            ->findOrFail($validated['medicine_id']);

        return response()->json([
            'line' => $this->service->formatLine($medicine),
        ]);
    }
}
