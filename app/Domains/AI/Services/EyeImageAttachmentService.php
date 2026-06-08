<?php

declare(strict_types=1);

namespace App\Domains\AI\Services;

use App\Models\PatientExam;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Converte exames de imagem ocular (PatientExam, armazenados no S3) em anexos
 * de IA inline (base64). Necessário porque o Gemini não busca URLs arbitrárias
 * — a imagem precisa ir como inline_data. As imagens são redimensionadas (GD)
 * para conter custo de tokens e caber no limite de payload inline.
 *
 * Cada anexo: { mime_type, data(base64), exam_id, laterality }.
 */
class EyeImageAttachmentService
{
    /**
     * @param iterable<PatientExam> $exams
     *
     * @return list<array{mime_type: string, data: string, exam_id: string, laterality: ?int}>
     */
    public function build(iterable $exams): array
    {
        $maxImages    = (int) config('ai.eye_image.max_images', 4);
        $maxDimension = (int) config('ai.eye_image.max_dimension', 1568);
        $maxSourceMb  = (int) config('ai.eye_image.max_source_mb', 25);

        $attachments = [];

        foreach ($exams as $exam) {
            if (count($attachments) >= $maxImages) {
                break;
            }

            $data = $this->encodeExam($exam, $maxDimension, $maxSourceMb);

            if ($data === null) {
                continue;
            }

            $attachments[] = [
                'mime_type'  => 'image/jpeg',
                'data'       => $data,
                'exam_id'    => (string) $exam->id,
                'laterality' => $exam->laterality !== null ? (int) $exam->laterality : null,
            ];
        }

        return $attachments;
    }

    /**
     * Baixa do S3, redimensiona com GD e devolve JPEG em base64 (ou null se
     * o arquivo não existir / não for uma imagem decodificável).
     */
    private function encodeExam(PatientExam $exam, int $maxDimension, int $maxSourceMb): ?string
    {
        if (blank($exam->archive)) {
            return null;
        }

        try {
            $disk = Storage::disk('s3');

            if (! $disk->exists($exam->archive)) {
                return null;
            }

            // Guarda de tamanho: evita carregar arquivos absurdamente grandes.
            $size = (int) $disk->size($exam->archive);

            if ($size > $maxSourceMb * 1024 * 1024) {
                return null;
            }

            $bytes = (string) $disk->get($exam->archive);
        } catch (Throwable) {
            return null;
        }

        if ($bytes === '') {
            return null;
        }

        $image = @imagecreatefromstring($bytes);

        if ($image === false) {
            return null;
        }

        $width   = imagesx($image);
        $height  = imagesy($image);
        $largest = max($width, $height);

        // Só reduz (nunca amplia).
        if ($largest > $maxDimension && $largest > 0) {
            $ratio    = $maxDimension / $largest;
            $newWidth = max(1, (int) round($width * $ratio));
            $resized  = imagescale($image, $newWidth);

            if ($resized !== false) {
                imagedestroy($image);
                $image = $resized;
            }
        }

        ob_start();
        imagejpeg($image, null, 85);
        $jpeg = (string) ob_get_clean();
        imagedestroy($image);

        if ($jpeg === '') {
            return null;
        }

        return base64_encode($jpeg);
    }
}
