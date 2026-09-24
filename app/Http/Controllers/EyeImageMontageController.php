<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesEyeImageExams;
use App\Models\PatientExam;
use App\Services\EyeImages\MontageService;
use Illuminate\Http\{JsonResponse, Request, Response};
use RuntimeException;

/**
 * Montage: colagem de 2+ imagens selecionadas numa única imagem, pra
 * exportar/imprimir — benchmark contra concorrente (Ger Exames/iWayBrasil,
 * aba "Montage"). Gerada sob demanda, nunca persistida — mesmo espírito do
 * PDF de laudo, que também é gerado on-the-fly a cada request.
 *
 * NÃO é ato clínico (é conveniência de visualização/impressão): admin,
 * doctor E secretary têm acesso — diferente do laudo manual
 * (EyeImageReportController), que é doctor-only via Gate::IssueReport.
 */
class EyeImageMontageController extends Controller
{
    use AuthorizesEyeImageExams;

    public function __construct(
        private readonly MontageService $montageService,
    ) {
    }

    public function store(Request $request): Response|JsonResponse
    {
        $entityId = (string) session('selected_entity_id');

        $validated = $request->validate([
            'exam_ids'   => ['required', 'array', 'min:2', 'max:50'],
            'exam_ids.*' => ['uuid'],
            'columns'    => ['nullable', 'integer', 'min:1', 'max:' . MontageService::MAX_COLUMNS],
        ]);

        $examIds = $this->ownedExamIds($validated['exam_ids'], $entityId);

        // Ordem da seleção do médico, não a ordem que o whereIn() do banco
        // devolveria (Postgres não garante ordem sem ORDER BY explícito).
        $exams   = PatientExam::query()->whereIn('id', $examIds)->get()->keyBy(fn (PatientExam $e) => (string) $e->id);
        $ordered = collect($examIds)->map(fn (string $id) => $exams->get($id))->filter()->values()->all();

        try {
            $png = $this->montageService->build($ordered, (int) ($validated['columns'] ?? 2));
        } catch (RuntimeException $e) {
            $message = match ($e->getMessage()) {
                'imagick_unavailable' => __('eye_images.montage_unavailable'),
                'no_readable_images'  => __('eye_images.montage_no_images'),
                default               => __('eye_images.montage_failed'),
            };

            return response()->json(['message' => $message], 422);
        }

        return response($png, 200, [
            'Content-Type'        => 'image/png',
            'Content-Disposition' => 'attachment; filename="montage.png"',
        ]);
    }
}
