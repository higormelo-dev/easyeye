<?php

declare(strict_types=1);

namespace App\Services\EyeImages;

use App\Models\PatientExam;
use Illuminate\Support\Facades\Storage;
use Imagick;
use ImagickDraw;
use ImagickException;
use RuntimeException;

/**
 * Colagem (grid) de imagens selecionadas numa única imagem — benchmark
 * contra concorrente (Ger Exames/iWayBrasil, aba "Montage"). Gerada no
 * BACKEND com Imagick (decisão explícita do usuário: evita o risco de CORS
 * de compor num <canvas> client-side contra o bucket S3-compatível externo
 * — ver docs/plans, item 4).
 *
 * Imagick é EXTENSÃO OPCIONAL neste projeto (mesmo padrão de
 * GenerateExamDerivatives::imageFromPdf — checa extension_loaded() antes de
 * instanciar \Imagick, nunca assume presente). Achado ao construir esta
 * feature: nem o Dockerfile do projeto instala php-imagick hoje, então a
 * geração de thumbnail de PDF em GenerateExamDerivatives já é dormente em
 * produção pelo mesmo motivo — reportado à parte, não corrigido aqui
 * (mudar imagem Docker é decisão de infra, fora do escopo deste serviço).
 */
class MontageService
{
    public const MAX_COLUMNS = 4;

    public function isAvailable(): bool
    {
        return extension_loaded('imagick');
    }

    /**
     * @param list<PatientExam> $exams
     *
     * @throws RuntimeException quando Imagick não está disponível neste
     *                          ambiente, ou nenhuma imagem pôde ser lida
     */
    public function build(array $exams, int $columns): string
    {
        if (! $this->isAvailable()) {
            throw new RuntimeException('imagick_unavailable');
        }

        $columns = max(1, min(self::MAX_COLUMNS, $columns));

        $montage = new Imagick();

        foreach ($exams as $exam) {
            $path = $exam->display_archive ?? $exam->archive;

            if ($path === null || ! Storage::disk('s3')->exists($path)) {
                continue;
            }

            try {
                $tile = new Imagick();
                $tile->readImageBlob(Storage::disk('s3')->get($path));
                $tile->setImageFormat('jpeg');
                $montage->addImage($tile);
            } catch (ImagickException) {
                // Arquivo corrompido/formato não suportado por essa imagem
                // específica não derruba a colagem inteira — só fica de fora.
                continue;
            }
        }

        if ($montage->getNumberImages() === 0) {
            throw new RuntimeException('no_readable_images');
        }

        $rows         = (int) ceil($montage->getNumberImages() / $columns);
        $tileGeometry = new ImagickDraw();

        $result = $montage->montageImage(
            $tileGeometry,
            "{$columns}x{$rows}",
            '300x300',
            Imagick::MONTAGEMODE_FRAME,
            '0x0+4+4',
        );
        $result->setImageFormat('png');
        $blob = $result->getImageBlob();

        $montage->clear();
        $result->clear();

        return $blob;
    }
}
