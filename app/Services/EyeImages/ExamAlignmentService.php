<?php

declare(strict_types=1);

namespace App\Services\EyeImages;

use App\Models\PatientExam;
use App\Services\EyeImages\Concerns\StagesExamImages;
use RuntimeException;

/**
 * Auto-alinhamento (fase 1, só translação) de duas imagens de exame para o
 * modo Sobrepor do Comparar: devolve o deslocamento que leva B sobre A, em
 * pixels do tamanho natural de A. Nada é persistido.
 */
class ExamAlignmentService
{
    use StagesExamImages;

    public function __construct(
        private readonly BoundedVisionProcess $process,
    ) {
    }

    /**
     * @return array{dx: float, dy: float, confidence: float}
     *
     * @throws RuntimeException com o código interno do motivo (nunca ao cliente)
     */
    public function detect(PatientExam $a, PatientExam $b): array
    {
        $cleanup = [];

        try {
            $pathA = $cleanup[] = $this->stageExamImage($a);
            $pathB = $cleanup[] = $this->stageExamImage($b);

            return $this->process->align($pathA, $pathB);
        } finally {
            foreach ($cleanup as $file) {
                @unlink($file);
            }
        }
    }
}
