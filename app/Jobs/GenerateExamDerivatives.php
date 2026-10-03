<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\PatientExam;
use App\Services\{BoundedExamArchive, BoundedPdfProcess, EmrReport};
use App\Services\RasterMemoryBudget;
use GdImage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\{InteractsWithQueue, SerializesModels};
use Imagick;
use RuntimeException;
use Throwable;

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
 * Falhas registram estado recuperável sem alterar o original. Em fila, o
 * job falha isolado; chamadores síncronos tratam a exceção após o commit
 * (importador externo) ou preservam o intent durável (publisher outbox).
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

    public function __construct(public string $patientExamId, public ?string $expectedArchive = null)
    {
    }

    public function handle(): void
    {
        $this->expectedArchive ??= PatientExam::whereKey($this->patientExamId)->value('archive');

        if ($this->expectedArchive === null) {
            return;
        }

        try {
            $this->generate();
        } catch (Throwable $e) {
            PatientExam::whereKey($this->patientExamId)->when($this->expectedArchive, fn ($q) => $q->where('archive', $this->expectedArchive))->update(['derivative_status' => 'failed', 'derivative_error_code' => 'derivative_generation_failed']);

            throw $e;
        }
    }

    private function generate(): void
    {
        $exam = PatientExam::query()->find($this->patientExamId);

        if ($exam === null || ! $exam->archive || ($this->expectedArchive !== null && $exam->archive !== $this->expectedArchive)) {
            return;
        }

        $raw = app(BoundedExamArchive::class)->read($exam->archive);

        $extension = strtolower(pathinfo($exam->archive, PATHINFO_EXTENSION));

        if ($extension === 'emr') {
            $report = app(EmrReport::class)->parse($raw);
            $svg    = app(EmrReport::class)->svg($report);
            $base   = preg_replace('/\.[^.\/]+$/', '', $exam->archive);
            app(BoundedExamArchive::class)->putVerified($base . '_display.svg', $svg);
            PatientExam::whereKey($exam->id)->where('archive', $exam->archive)->update(['display_archive' => $base . '_display.svg', 'thumb_archive' => $base . '_display.svg', 'derivative_status' => 'ready', 'derivative_error_code' => null]);

            return;
        }
        $image = match (true) {
            in_array($extension, ['jpg', 'jpeg', 'png', 'bmp'], true)                                => $this->imageFromRaster($raw),
            $extension === 'pdf' && extension_loaded('imagick') && BoundedPdfProcess::hasIsolation() => $this->imageFromPdf($raw),
            default                                                                                  => null,
        };

        if ($image === null) {
            PatientExam::whereKey($exam->id)->where('archive', $exam->archive)->update(['derivative_status' => 'unsupported', 'derivative_error_code' => $extension === 'pdf' ? 'pdf_renderer_unavailable' : 'format_no_derivative']);

            return;
        }

        $display = $this->encodeJpeg($image, self::DISPLAY_MAX_EDGE, self::DISPLAY_QUALITY);
        $thumb   = $this->encodeJpeg($image, self::THUMB_MAX_EDGE, self::THUMB_QUALITY);

        if ($display === null || $thumb === null) {
            throw new RuntimeException('raster_encode_failed');
        }

        $base        = preg_replace('/\.[^.\/]+$/', '', $exam->archive);
        $displayPath = "{$base}_display.jpg";
        $thumbPath   = "{$base}_thumb.jpg";

        app(BoundedExamArchive::class)->putVerified($displayPath, $display);
        app(BoundedExamArchive::class)->putVerified($thumbPath, $thumb);

        PatientExam::whereKey($exam->id)->where('archive', $exam->archive)->update([
            'derivative_status' => 'ready', 'derivative_error_code' => null,
            'display_archive'   => $displayPath,
            'thumb_archive'     => $thumbPath,
        ]);
    }

    /** Decodifica jpg/png/bmp via GD, achatando transparência sobre branco. */
    private function imageFromRaster(string $raw): ?GdImage
    {
        $dimensions = @getimagesizefromstring($raw);

        if (! $dimensions || $dimensions[0] > 16000 || $dimensions[1] > 16000 || $dimensions[0] * $dimensions[1] > 40000000) {
            throw new RuntimeException('raster_pixel_limit');
        }
        $pixels   = $dimensions[0] * $dimensions[1];
        $estimate = $pixels * 16 + min($pixels, 2560 * 2560) * 4 + strlen($raw) * 2 + 16777216;
        $budget   = app(RasterMemoryBudget::class)->available();

        if ($estimate > $budget) {
            throw new RuntimeException('raster_memory_limit');
        }
        $image = @imagecreatefromstring($raw);

        if ($image === false) {
            throw new RuntimeException('raster_decode_failed');
        }

        // PNG com alfa sobre fundo branco (laudos/plots exportados com
        // transparência ficariam pretos no JPEG).
        $flattened = imagecreatetruecolor(imagesx($image), imagesy($image));

        if ($flattened === false) {
            throw new RuntimeException('raster_flatten_failed');
        }
        $white = imagecolorallocate($flattened, 255, 255, 255);
        imagefill($flattened, 0, 0, $white);
        imagecopy($flattened, $image, 0, 0, 0, 0, imagesx($image), imagesy($image));

        return $flattened;
    }

    /** Rasteriza a 1ª página de um PDF via Imagick (requer Ghostscript). */
    private function imageFromPdf(string $raw): ?GdImage
    {
        $tmp = tempnam(sys_get_temp_dir(), 'exam-pdf-');

        if ($tmp === false) {
            throw new RuntimeException('pdf_temp_failed');
        }

        try {
            file_put_contents($tmp, $raw);
            $bytes = app(BoundedPdfProcess::class)->run('render', $tmp);

            return $this->imageFromRaster($bytes);
        } finally {
            @unlink($tmp);
        }
    }

    /** Reduz (nunca amplia) para o lado maior indicado e codifica JPEG. */
    private function encodeJpeg(GdImage $image, int $maxEdge, int $quality): ?string
    {
        $width  = imagesx($image);
        $height = imagesy($image);
        $edge   = max($width, $height);

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
        $ok   = imagejpeg($scaled, null, $quality);
        $jpeg = ob_get_clean();

        return $ok && $jpeg !== false ? $jpeg : null;
    }
}
