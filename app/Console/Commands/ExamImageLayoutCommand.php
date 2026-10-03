<?php

namespace App\Console\Commands;

use App\Domains\AI\Services\ExamImageDeidentifier;
use App\Domains\AI\Support\ExamImageLayouts;
use GdImage;
use Illuminate\Console\Command;

/**
 * Manutenção dos layouts de tarja (ExamImageLayouts): diz qual layout
 * reconhece cada imagem de amostra e a distância para os candidatos; com
 * --anchor gera a impressão digital de uma região para cadastrar um layout
 * novo e com --out grava a imagem tarjada para conferência. Só lê arquivos
 * locais — nada sai da máquina e nenhum conteúdo da imagem é impresso.
 */
class ExamImageLayoutCommand extends Command
{
    protected $signature = 'ai:exam-image-layout
        {paths* : Imagens de amostra (exportação do equipamento)}
        {--anchor=* : Região x,y,largura,altura (pixels da amostra) para gerar a impressão digital}
        {--out= : Pasta para gravar a imagem tarjada (PNG) de cada amostra reconhecida}';

    protected $description = 'Confere o reconhecimento das imagens de exame para tarjar os dados do paciente antes da IA';

    public function handle(ExamImageDeidentifier $deidentifier): int
    {
        $out = $this->option('out');

        if (is_string($out) && $out !== '' && ! is_dir($out)) {
            $this->error("Pasta inexistente: {$out}");

            return self::FAILURE;
        }

        foreach ((array) $this->argument('paths') as $path) {
            $image = is_file($path) ? @imagecreatefromstring((string) file_get_contents($path)) : false;

            if ($image === false) {
                $this->error("Não é uma imagem legível: {$path}");

                return self::FAILURE;
            }

            $this->line(sprintf('%s (%d×%d)', basename($path), imagesx($image), imagesy($image)));

            foreach ($this->distances($deidentifier, $image) as $key => $distance) {
                $this->line(sprintf('  %-34s distância %s', $key, is_finite($distance) ? number_format($distance, 1) : '—'));
            }

            foreach ((array) $this->option('anchor') as $anchor) {
                $rect = array_map('intval', explode(',', (string) $anchor));

                if (count($rect) !== 4 || $rect[2] < 1 || $rect[3] < 1) {
                    $this->error("Região inválida: {$anchor} (use x,y,largura,altura)");

                    return self::FAILURE;
                }

                $reference = ['width' => imagesx($image), 'height' => imagesy($image)];
                $this->line(sprintf("  anchor [%s] => '%s'", implode(', ', $rect), $deidentifier->signature($image, $rect, $reference)));
            }

            $key = $deidentifier->deidentify($image);
            $this->info('  => ' . ($key ?? 'não reconhecido (a imagem fica fora da IA)'));

            if ($key !== null && is_string($out) && $out !== '') {
                imagepng($image, rtrim($out, '/') . '/' . pathinfo($path, PATHINFO_FILENAME) . '.tarjada.png');
            }

            imagedestroy($image);
        }

        return self::SUCCESS;
    }

    /**
     * Maior distância entre as regiões fixas de cada layout de mesma proporção.
     *
     * @return array<string, float>
     */
    private function distances(ExamImageDeidentifier $deidentifier, GdImage $image): array
    {
        $ratio  = imagesx($image) / max(1, imagesy($image));
        $result = [];

        foreach (ExamImageLayouts::all() as $layout) {
            if (abs($ratio - $layout['width'] / $layout['height']) > 0.01) {
                continue;
            }

            $worst = 0.0;

            foreach ($layout['anchors'] as $anchor) {
                $worst = max($worst, ExamImageDeidentifier::distance($deidentifier->signature($image, $anchor['rect'], $layout), $anchor['signature']));
            }

            $result[$layout['key']] = $worst;
        }

        return $result;
    }
}
