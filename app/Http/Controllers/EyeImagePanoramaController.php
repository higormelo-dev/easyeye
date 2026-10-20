<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesEyeImageExams;
use App\Models\PatientExam;
use App\Services\EyeImages\{BoundedVisionProcess, PanoramaService};
use Illuminate\Http\{JsonResponse, Request, Response};
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Panorama: costura 2 a 6 imagens selecionadas numa só (OpenCV, processo
 * isolado) — benchmark contra concorrente (Ger Exames/iWayBrasil, aba
 * "Montage"). Gerado sob demanda, nunca persistido; composição APROXIMADA, não
 * uma medida.
 *
 * NÃO é ato clínico (conveniência de visualização/impressão): admin, doctor E
 * secretary têm acesso, igual ao Montage (EyeImageMontageController).
 */
class EyeImagePanoramaController extends Controller
{
    use AuthorizesEyeImageExams;

    public function __construct(
        private readonly PanoramaService $panoramaService,
    ) {
    }

    public function store(Request $request): Response|JsonResponse
    {
        $entityId = (string) session('selected_entity_id');

        $validated = $request->validate([
            'exam_ids'   => ['required', 'array', 'min:2', 'max:' . BoundedVisionProcess::MAX_PANORAMA_INPUTS],
            'exam_ids.*' => ['uuid', 'distinct'],
        ]);

        $examIds = $this->ownedExamIds($validated['exam_ids'], $entityId);

        // Ordem da seleção do usuário, não a que o whereIn() do banco devolver.
        $exams   = PatientExam::query()->whereIn('id', $examIds)->get()->keyBy(fn (PatientExam $e) => (string) $e->id);
        $ordered = collect($validated['exam_ids'])->map(fn (string $id) => $exams->get($id))->filter()->values()->all();

        // Vídeo nunca é decodificável como imagem: recusa antes de baixar nada.
        foreach ($ordered as $exam) {
            abort_if($exam->isVideo(), 422, __('eye_images.panorama_video_not_allowed'));
        }

        try {
            $png = $this->panoramaService->build($ordered);
        } catch (RuntimeException $e) {
            // O reason cru pode citar caminho de arquivo temporário: nunca vai
            // ao cliente, e ao log só o código sem o detalhe.
            Log::warning('eye_images.panorama_failed', ['reason' => BoundedVisionProcess::reasonCode($e->getMessage())]);

            return response()->json(['message' => $this->message($e->getMessage())], 422);
        }

        return response($png, 200, [
            'Content-Type'        => 'image/png',
            'Content-Disposition' => 'attachment; filename="panorama.png"',
        ]);
    }

    private function message(string $reason): string
    {
        return match (true) {
            in_array($reason, ['cv2_unavailable', 'vision_process_isolation_unavailable'], true) => __('eye_images.panorama_unavailable'),
            str_starts_with($reason, 'unreadable_image'),
            str_starts_with($reason, 'archive_'),
            $reason === 'vision_image_missing'        => __('eye_images.panorama_unreadable_image'),
            str_starts_with($reason, 'stitch_failed') => __('eye_images.panorama_stitch_failed'),
            $reason === 'vision_busy'                 => __('eye_images.panorama_busy'),
            $reason === 'vision_timeout'              => __('eye_images.panorama_timeout'),
            default                                   => __('eye_images.panorama_failed'),
        };
    }
}
