<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\PatientExam;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

/**
 * Gera os derivados de visualização de um exame recém-enviado:
 * JPEG de alta resolução (viewer) + miniatura JPEG (grid do Gerenciador de
 * Imagens). O original em `archive` nunca é alterado.
 *
 * Formatos:
 * - jpg/jpeg/png/bmp: via GD (sempre disponível);
 * - pdf: 1ª página via Imagick + Ghostscript — somente quando a extensão
 *   está instalada; sem ela o exame fica sem derivado e o front usa o
 *   fallback (ícone/arquivo original);
 * - demais (emr, …): sem derivado.
 *
 * Falhas aqui nunca podem derrubar o upload: em fila, o job falha isolado;
 * em QUEUE_CONNECTION=sync, qualquer exceção é reportada e engolida.
 */
class GenerateExamDerivatives implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    private const DISPLAY_MAX_EDGE = 2560;
    private const DISPLAY_QUALITY = 85;
    private const THUMB_MAX_EDGE = 400;
    private const THUMB_QUALITY = 75;
    private const PDF_RENDER_DPI = 200;

    public function __construct(public string $patientExamId)
    {
    }

    public function handle(): void
    {
        try {
            $this->generate();
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private function generate(): void
    {
        $exam = PatientExam::query()->find($this->patientExamId);

        if ($exam === null || ! $exam->archive) {
            return;
        }

        $raw = Storage::disk('s3')->get($exam->archive);

        if ($raw === null) {
            return;
        }

        $extension = strtolower(pathinfo($exam->archive, PATHINFO_EXTENSION));

        $image = match (true) {
            in_array($extension, ['jpg', 'jpeg', 'png', 'bmp'], true) => $this->imageFromRaster($raw),
            $extension === 'pdf' && extension_loaded('imagick') => $this->imageFromPdf($raw),
            default => null,
        };

        if ($image === null) {
            return;
        }

        $display = $this->encodeJpeg($image, self::DISPLAY_MAX_EDGE, self::DISPLAY_QUALITY);
        $thumb = $this->encodeJpeg($image, self::THUMB_MAX_EDGE, self::THUMB_QUALITY);

        if ($display === null || $thumb === null) {
            return;
        }

        $base = preg_replace('/\.[^.\/]+$/', '', $exam->archive);
        $displayPath = "{$base}_display.jpg";
        $thumbPath = "{$base}_thumb.jpg";

        Storage::disk('s3')->put($displayPath, $display);
        Storage::disk('s3')->put($thumbPath, $thumb);

        $exam->forceFill([
            'display_archive' => $displayPath,
            'thumb_archive' => $thumbPath,
        ])->save();
    }

    /** Decodifica jpg/png/bmp via GD, achatando transparência sobre branco. */
    private function imageFromRaster(string $raw): ?\GdImage
    {
        $image = @imagecreatefromstring($raw);

        if ($image === false) {
            return null;
        }

        // PNG com alfa sobre fundo branco (laudos/plots exportados com
        // transparência ficariam pretos no JPEG).
        $flattened = imagecreatetruecolor(imagesx($image), imagesy($image));
        $white = imagecolorallocate($flattened, 255, 255, 255);
        imagefill($flattened, 0, 0, $white);
        imagecopy($flattened, $image, 0, 0, 0, 0, imagesx($image), imagesy($image));

        return $flattened;
    }

    /** Rasteriza a 1ª página de um PDF via Imagick (requer Ghostscript). */
    private function imageFromPdf(string $raw): ?\GdImage
    {
        $tmp = tempnam(sys_get_temp_dir(), 'exam-pdf-');

        if ($tmp === false) {
            return null;
        }

        try {
            file_put_contents($tmp, $raw);

            $imagick = new \Imagick();
            $imagick->setResolution(self::PDF_RENDER_DPI, self::PDF_RENDER_DPI);
            $imagick->readImage($tmp . '[0]');
            $imagick->setImageBackgroundColor('white');
            $imagick = $imagick->flattenImages();
            $imagick->setImageFormat('jpeg');
            $jpeg = $imagick->getImageBlob();
            $imagick->clear();

            return $this->imageFromRaster($jpeg);
        } catch (\Throwable) {
            return null;
        } finally {
            @unlink($tmp);
        }
    }

    /** Reduz (nunca amplia) para o lado maior indicado e codifica JPEG. */
    private function encodeJpeg(\GdImage $image, int $maxEdge, int $quality): ?string
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $edge = max($width, $height);

        $scaled = $image;
        if ($edge > $maxEdge) {
            $targetWidth = $width >= $height
                ? $maxEdge
                : (int) round($width * $maxEdge / $height);
            $scaled = imagescale($image, $targetWidth, -1, IMG_BICUBIC);

            if ($scaled === false) {
                return null;
            }
        }

        ob_start();
        $ok = imagejpeg($scaled, null, $quality);
        $jpeg = ob_get_clean();

        return $ok && $jpeg !== false ? $jpeg : null;
    }
}
