<?php

declare(strict_types=1);

namespace App\Services\EyeImages;

use App\Models\PatientExam;
use App\Services\EyeImages\Concerns\StagesExamImages;
use RuntimeException;

/**
 * Panorama automático: costura 2..6 imagens de exame (OpenCV Stitcher, num
 * processo isolado) e devolve os bytes do PNG. Gerado sob demanda e nunca
 * persistido — mesmo espírito do Montage. É uma composição aproximada, não
 * uma medida: a UI avisa a cada geração.
 */
class PanoramaService
{
    use StagesExamImages;

    public function __construct(
        private readonly BoundedVisionProcess $process,
    ) {
    }

    /**
     * @param list<PatientExam> $exams na ordem da seleção
     *
     * @return string bytes do PNG
     *
     * @throws RuntimeException com o código interno do motivo (nunca ao cliente)
     */
    public function build(array $exams): string
    {
        $cleanup = [];

        try {
            $inputs = [];

            foreach ($exams as $exam) {
                $inputs[] = $cleanup[] = $this->stageExamImage($exam);
            }

            $out = tempnam(sys_get_temp_dir(), 'panorama-');

            if ($out === false) {
                throw new RuntimeException('vision_temp_failed');
            }

            $cleanup[] = $out;

            $this->process->stitch($inputs, $out);

            $png = file_get_contents($out);

            if ($png === false || $png === '') {
                throw new RuntimeException('vision_invalid_output');
            }

            return $png;
        } finally {
            foreach ($cleanup as $file) {
                @unlink($file);
            }
        }
    }
}
