<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesExamManagerExams;
use App\Models\PatientExam;
use App\Services\EyeImages\{BoundedVisionProcess, ExamAlignmentService};
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Auto-alinhar (fase 1, só translação) das duas imagens do modo Sobrepor do
 * Comparar — benchmark contra concorrente (Ger Exames/iWayBrasil, aba "Align").
 * Devolve `dx`/`dy` em pixels do tamanho natural da imagem A (a B é
 * redimensionada para A no script); quem converte para pixels de tela é o
 * front. Nada é persistido.
 *
 * NÃO é ato clínico (conveniência de visualização): admin, doctor E secretary
 * têm acesso, igual ao Montage.
 */
class ExamManagerAlignController extends Controller
{
    use AuthorizesExamManagerExams;

    public function __construct(
        private readonly ExamAlignmentService $alignmentService,
    ) {
    }

    public function store(Request $request): JsonResponse
    {
        $entityId = (string) session('selected_entity_id');

        $validated = $request->validate([
            'exam_id_a' => ['required', 'uuid', 'different:exam_id_b'],
            'exam_id_b' => ['required', 'uuid'],
        ]);

        $this->ownedExamIds([$validated['exam_id_a'], $validated['exam_id_b']], $entityId);

        $exams = PatientExam::query()
            ->whereIn('id', [$validated['exam_id_a'], $validated['exam_id_b']])
            ->get()
            ->keyBy(fn (PatientExam $e) => (string) $e->id);

        $examA = $exams->get($validated['exam_id_a']);
        $examB = $exams->get($validated['exam_id_b']);

        // Vídeo nunca é decodificável como imagem: recusa antes de baixar nada.
        abort_if($examA->isVideo() || $examB->isVideo(), 422, __('eye_images.align_video_not_allowed'));

        try {
            $result = $this->alignmentService->detect($examA, $examB);
        } catch (RuntimeException $e) {
            // O reason cru pode citar caminho de arquivo temporário: nunca vai
            // ao cliente, e ao log só o código sem o detalhe.
            Log::warning('eye_images.align_failed', ['reason' => BoundedVisionProcess::reasonCode($e->getMessage())]);

            return response()->json(['message' => $this->message($e->getMessage())], 422);
        }

        return response()->json([
            'dx'         => $result['dx'],
            'dy'         => $result['dy'],
            'confidence' => $result['confidence'],
        ]);
    }

    private function message(string $reason): string
    {
        return match (true) {
            in_array($reason, ['cv2_unavailable', 'vision_process_isolation_unavailable'], true) => __('eye_images.align_unavailable'),
            str_starts_with($reason, 'unreadable_image'),
            str_starts_with($reason, 'archive_'),
            $reason === 'vision_image_missing' => __('eye_images.align_unreadable_image'),
            $reason === 'no_matches'           => __('eye_images.align_no_matches'),
            $reason === 'low_confidence'       => __('eye_images.align_low_confidence'),
            $reason === 'vision_busy'          => __('eye_images.align_busy'),
            $reason === 'vision_timeout'       => __('eye_images.align_timeout'),
            default                            => __('eye_images.align_failed'),
        };
    }
}
