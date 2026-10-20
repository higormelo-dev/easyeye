<?php

declare(strict_types=1);

namespace App\Services\EyeImages\Concerns;

use App\Models\PatientExam;
use App\Services\BoundedExamArchive;
use App\Services\EyeImages\BoundedVisionProcess;
use RuntimeException;

/**
 * Baixa a imagem de um exame para um arquivo temporário que o processo de
 * visão (Python) possa abrir. Mesma regra de MontageService: o derivado de
 * exibição (`display_archive`, JPEG ≤ 2560 px) quando pronto, senão o original.
 * Quem chama apaga o temporário no `finally` — nunca deixa arquivo órfão.
 */
trait StagesExamImages
{
    /** @throws RuntimeException vision_image_missing | vision_temp_failed | archive_* */
    private function stageExamImage(PatientExam $exam): string
    {
        $path = ($exam->derivative_status === 'ready' && $exam->display_archive !== null)
            ? $exam->display_archive
            : $exam->archive;

        if ($path === null || $path === '') {
            throw new RuntimeException('vision_image_missing');
        }

        $bytes = app(BoundedExamArchive::class)->read($path, BoundedVisionProcess::MAX_IMAGE_BYTES);
        $tmp   = tempnam(sys_get_temp_dir(), 'vision-');

        if ($tmp === false) {
            throw new RuntimeException('vision_temp_failed');
        }

        if (file_put_contents($tmp, $bytes) === false) {
            @unlink($tmp);

            throw new RuntimeException('vision_temp_failed');
        }

        return $tmp;
    }
}
