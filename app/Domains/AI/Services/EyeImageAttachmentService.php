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
 * LGPD: antes de sair, os dados do paciente gravados na imagem (nome,
 * nascimento, ID) são tarjados pelo layout do equipamento
 * (ExamImageDeidentifier). Layout desconhecido: a imagem NÃO sai.
 *
 * Cada anexo: { mime_type, data(base64), exam_id, laterality }.
 */
class EyeImageAttachmentService
{
    /** Imagem fora da IA: layout do equipamento não reconhecido para tarjar. */
    public const SKIPPED_UNRECOGNIZED_LAYOUT = 'unrecognized_layout';

    private readonly ExamImageDeidentifier $deidentifier;

    public function __construct(?ExamImageDeidentifier $deidentifier = null)
    {
        $this->deidentifier = $deidentifier ?? new ExamImageDeidentifier();
    }

    /**
     * @param iterable<PatientExam> $exams
     *
     * @return list<array{mime_type: string, data: string, exam_id: string, laterality: ?int}>
     */
    public function build(iterable $exams): array
    {
        return $this->prepare($exams)['attachments'];
    }

    /**
     * Anexos + o que aconteceu com cada imagem (auditoria): chave do layout
     * tarjado ou SKIPPED_UNRECOGNIZED_LAYOUT. Arquivo ausente, grande demais
     * ou ilegível segue sendo ignorado sem registro (como antes).
     *
     * @param iterable<PatientExam> $exams
     *
     * @return array{attachments: list<array{mime_type: string, data: string, exam_id: string, laterality: ?int}>, outcomes: array<string, string>}
     */
    public function prepare(iterable $exams): array
    {
        $maxImages    = (int) config('ai.eye_image.max_images', 4);
        $maxDimension = (int) config('ai.eye_image.max_dimension', 1568);
        $maxSourceMb  = (int) config('ai.eye_image.max_source_mb', 25);

        $attachments = [];
        $outcomes    = [];

        foreach ($exams as $exam) {
            if (count($attachments) >= $maxImages) {
                break;
            }

            $encoded = $this->encodeExam($exam, $maxDimension, $maxSourceMb);

            if ($encoded === null) {
                continue;
            }

            if ($encoded['layout'] === null) {
                $outcomes[(string) $exam->id] = self::SKIPPED_UNRECOGNIZED_LAYOUT;

                continue;
            }

            $outcomes[(string) $exam->id] = $encoded['layout'];

            $attachments[] = [
                'mime_type'  => 'image/jpeg',
                'data'       => (string) $encoded['data'],
                'exam_id'    => (string) $exam->id,
                'laterality' => $exam->laterality !== null ? (int) $exam->laterality : null,
            ];
        }

        return ['attachments' => $attachments, 'outcomes' => $outcomes];
    }

    /**
     * Baixa do S3, tarja os dados do paciente, redimensiona com GD e devolve
     * JPEG em base64 com o layout aplicado (data null quando o layout não é
     * reconhecido). Null se o arquivo não existir / não for uma imagem decodificável.
     *
     * @return array{data: ?string, layout: ?string}|null
     */
    private function encodeExam(PatientExam $exam, int $maxDimension, int $maxSourceMb): ?array
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

        // Tarja na resolução original (antes de reduzir) — o original no S3 não muda.
        $layout = $this->deidentifier->deidentify($image);

        if ($layout === null) {
            imagedestroy($image);

            return ['data' => null, 'layout' => null];
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

        return ['data' => base64_encode($jpeg), 'layout' => $layout];
    }
}
