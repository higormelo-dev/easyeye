<?php

declare(strict_types=1);

namespace App\Domains\AI\Services;

use App\Domains\AI\Support\ExamImageLayouts;
use GdImage;

/**
 * Tarja os dados do paciente gravados na imagem do exame (nome, nascimento,
 * ID, data do exame) antes de ela ir para um provedor de IA — LGPD,
 * minimização. Os equipamentos exportam o relatório como imagem com esses
 * dados no próprio pixel, em posições que mudam por equipamento, relatório e
 * idioma.
 *
 * Reconhece o layout pela "impressão digital" de regiões fixas da tela
 * (títulos, rótulos, logotipo — nunca dado do paciente) e pinta de preto os
 * campos do paciente desse layout. Layout desconhecido devolve null: a
 * imagem não deve sair (decisão do produto, 03/10/2026). Só a cópia enviada
 * à IA é tarjada; o original do exame não muda.
 *
 * Impressão digital: a região é reduzida (GD, média por área) a uma grade de
 * luminância — tolera compressão JPEG e outra resolução de exportação.
 */
class ExamImageDeidentifier
{
    /** Diferença média máxima (0–255) por célula da grade para reconhecer uma região. */
    public const MAX_DISTANCE = 12.0;

    /** Tolerância na proporção largura/altura (exportação em outra resolução). */
    private const ASPECT_TOLERANCE = 0.01;

    /** @var list<array<string, mixed>> */
    private readonly array $layouts;

    /**
     * @param list<array<string, mixed>>|null $layouts padrão: ExamImageLayouts::all()
     */
    public function __construct(?array $layouts = null)
    {
        $this->layouts = $layouts ?? ExamImageLayouts::all();
    }

    /**
     * Reconhece o layout e tarja os campos do paciente na própria imagem.
     *
     * @return string|null chave do layout aplicado; null = desconhecido (não enviar)
     */
    public function deidentify(GdImage $image): ?string
    {
        $layout = $this->match($image);

        if ($layout === null) {
            return null;
        }

        // Imagem com paleta cheia não aloca o preto: tarja em truecolor.
        if (! imageistruecolor($image)) {
            imagepalettetotruecolor($image);
        }

        $black = imagecolorallocate($image, 0, 0, 0);

        foreach ($layout['redact'] as $rect) {
            [$x, $y, $w, $h] = $this->scale($image, $rect, $layout);
            imagefilledrectangle($image, $x, $y, $x + $w - 1, $y + $h - 1, (int) $black);
        }

        return (string) $layout['key'];
    }

    /**
     * Layout de mesma proporção cujas regiões fixas batem todas; havendo
     * mais de um, o de menor diferença.
     *
     * @return array<string, mixed>|null
     */
    public function match(GdImage $image): ?array
    {
        $ratio = imagesx($image) / max(1, imagesy($image));
        $best  = null;
        $score = INF;

        foreach ($this->layouts as $layout) {
            if (abs($ratio - $layout['width'] / $layout['height']) > self::ASPECT_TOLERANCE) {
                continue;
            }

            $worst = 0.0;

            foreach ($layout['anchors'] as $anchor) {
                $worst = max($worst, self::distance($this->signature($image, $anchor['rect'], $layout), $anchor['signature']));

                if ($worst > self::MAX_DISTANCE) {
                    continue 2;
                }
            }

            if ($worst < $score) {
                $best  = $layout;
                $score = $worst;
            }
        }

        return $best;
    }

    /**
     * Grade de luminância (hex, 2 caracteres por célula) da região — em
     * pixels da resolução de referência do layout.
     *
     * @param array{int, int, int, int} $rect   [x, y, largura, altura]
     * @param array<string, mixed>      $layout precisa de width/height de referência
     */
    public function signature(GdImage $image, array $rect, array $layout): string
    {
        [$x, $y, $w, $h]  = $this->scale($image, $rect, $layout);
        [$columns, $rows] = self::grid($rect);

        $cells = imagecreatetruecolor($columns, $rows);
        imagecopyresampled($cells, $image, 0, 0, $x, $y, $columns, $rows, $w, $h);

        $hex = '';

        for ($row = 0; $row < $rows; $row++) {
            for ($column = 0; $column < $columns; $column++) {
                $rgb = imagecolorat($cells, $column, $row);
                $hex .= sprintf('%02x', (int) round(0.299 * (($rgb >> 16) & 0xFF) + 0.587 * (($rgb >> 8) & 0xFF) + 0.114 * ($rgb & 0xFF)));
            }
        }

        imagedestroy($cells);

        return $hex;
    }

    /**
     * Tamanho da grade de uma região: ~1 célula a cada 10 px de referência.
     *
     * @param array{int, int, int, int} $rect
     *
     * @return array{int, int}
     */
    public static function grid(array $rect): array
    {
        return [
            max(4, min(64, (int) round($rect[2] / 10))),
            max(2, min(16, (int) round($rect[3] / 10))),
        ];
    }

    /** Diferença média por célula entre duas impressões digitais (INF se incompatíveis). */
    public static function distance(string $a, string $b): float
    {
        if ($a === '' || strlen($a) !== strlen($b)) {
            return INF;
        }

        $sum   = 0;
        $cells = intdiv(strlen($a), 2);

        for ($i = 0; $i < $cells; $i++) {
            $sum += abs(hexdec(substr($a, $i * 2, 2)) - hexdec(substr($b, $i * 2, 2)));
        }

        return $sum / $cells;
    }

    /**
     * Retângulo de referência → pixels desta imagem (exportação em outra resolução).
     *
     * @param array{int, int, int, int} $rect
     * @param array<string, mixed>      $layout
     *
     * @return array{int, int, int, int}
     */
    private function scale(GdImage $image, array $rect, array $layout): array
    {
        $sx = imagesx($image) / $layout['width'];
        $sy = imagesy($image) / $layout['height'];

        return [
            (int) floor($rect[0] * $sx),
            (int) floor($rect[1] * $sy),
            max(1, (int) ceil($rect[2] * $sx)),
            max(1, (int) ceil($rect[3] * $sy)),
        ];
    }
}
