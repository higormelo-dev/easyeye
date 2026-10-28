<?php

declare(strict_types=1);

use App\Domains\AI\Services\ExamImageDeidentifier;
use App\Domains\AI\Support\ExamImageLayouts;

/**
 * Tarja dos dados do paciente gravados na imagem do exame antes da IA (LGPD).
 * Fixtures: só as regiões fixas da tela (título, rótulos, logotipo) copiadas
 * de exportações reais sobre fundo cinza + um texto falso "PACIENTE TESTE"
 * nos campos — nenhum dado real de paciente.
 */
function layoutFixture(string $key): GdImage
{
    return imagecreatefrompng(__DIR__ . "/../../Fixtures/exam-image-layouts/{$key}.png");
}

/** @param array{int, int, int, int} $rect */
function rectIsBlack(GdImage $image, array $rect): bool
{
    [$x0, $y0, $w, $h] = $rect;

    for ($y = $y0; $y < $y0 + $h; $y++) {
        for ($x = $x0; $x < $x0 + $w; $x++) {
            if ((imagecolorat($image, $x, $y) & 0xFFFFFF) !== 0) {
                return false;
            }
        }
    }

    return true;
}

$layoutKeys = array_column(ExamImageLayouts::all(), 'key');

// Layouts com "vizinho" do mesmo tamanho de tela (Pentacam, Keratograph).
$layoutKeysWithSiblings = array_values(array_filter($layoutKeys, function (string $key) {
    $all  = collect(ExamImageLayouts::all());
    $self = $all->firstWhere('key', $key);

    return $all->contains(fn (array $l) => $l['key'] !== $key && $l['width'] === $self['width'] && $l['height'] === $self['height']);
}));

it('[LGPD] reconhece cada layout pronto e pinta de preto os campos do paciente', function (string $key) {
    $layout = collect(ExamImageLayouts::all())->firstWhere('key', $key);
    $image  = layoutFixture($key);
    $corner = imagecolorat($image, imagesx($image) - 2, imagesy($image) - 2);

    expect((new ExamImageDeidentifier())->deidentify($image))->toBe($key);

    foreach ($layout['redact'] as $rect) {
        expect(rectIsBlack($image, $rect))->toBeTrue();
    }

    // Fora dos campos do paciente a imagem não muda.
    expect(imagecolorat($image, imagesx($image) - 2, imagesy($image) - 2))->toBe($corner);
})->with($layoutKeys);

it('[LGPD] cada layout só reconhece a própria tela — folga contra falso positivo', function (string $key) {
    $image   = layoutFixture($key);
    $service = new ExamImageDeidentifier();
    $ratio   = imagesx($image) / imagesy($image);

    foreach (ExamImageLayouts::all() as $other) {
        if ($other['key'] === $key || abs($ratio - $other['width'] / $other['height']) > 0.01) {
            continue;
        }

        $worst = max(array_map(
            fn (array $anchor) => ExamImageDeidentifier::distance($service->signature($image, $anchor['rect'], $other), $anchor['signature']),
            $other['anchors'],
        ));

        expect($worst)->toBeGreaterThan(ExamImageDeidentifier::MAX_DISTANCE + 3);
    }
})->with($layoutKeysWithSiblings);

it('[LGPD] tela desconhecida de mesma proporção não é reconhecida e não é alterada', function () {
    $image = imagecreatetruecolor(800, 646);
    imagefill($image, 0, 0, imagecolorallocate($image, 128, 128, 128));

    expect((new ExamImageDeidentifier())->deidentify($image))->toBeNull()
        ->and(imagecolorat($image, 100, 90) & 0xFFFFFF)->toBe(0x808080);
});

it('imagem de outra proporção nunca é comparada', function () {
    expect((new ExamImageDeidentifier())->match(imagecreatetruecolor(500, 500)))->toBeNull();
});

it('exportação em outra resolução (125%) é reconhecida e tarjada na escala certa', function () {
    $image = imagescale(layoutFixture('oculus_pentacam_panel_a_pt'), 1000);

    expect((new ExamImageDeidentifier())->deidentify($image))->toBe('oculus_pentacam_panel_a_pt')
        // Centro do campo [76, 44, 152, 98] escalado 1,25×.
        ->and(imagecolorat($image, (int) ((76 + 76) * 1.25), (int) ((44 + 49) * 1.25)) & 0xFFFFFF)->toBe(0);
});

it('registro de layouts consistente: chaves únicas, regiões dentro da tela e impressão digital do tamanho da grade', function () {
    $layouts = ExamImageLayouts::all();

    expect(array_unique(array_column($layouts, 'key')))->toHaveCount(count($layouts));

    $inside = fn (array $rect, array $layout) => $rect[0] >= 0 && $rect[1] >= 0 && $rect[2] > 0 && $rect[3] > 0
        && $rect[0] + $rect[2] <= $layout['width'] && $rect[1] + $rect[3] <= $layout['height'];

    foreach ($layouts as $layout) {
        expect(count($layout['anchors']))->toBeGreaterThanOrEqual(2)
            ->and($layout['redact'])->not->toBeEmpty();

        foreach ($layout['anchors'] as $anchor) {
            [$columns, $rows] = ExamImageDeidentifier::grid($anchor['rect']);

            expect($inside($anchor['rect'], $layout))->toBeTrue()
                ->and(strlen($anchor['signature']))->toBe($columns * $rows * 2);
        }

        foreach ($layout['redact'] as $rect) {
            expect($inside($rect, $layout))->toBeTrue();
        }
    }
});

it('impressões digitais incompatíveis têm distância infinita', function () {
    expect(ExamImageDeidentifier::distance('ff00', 'ff'))->toBe(INF)
        ->and(ExamImageDeidentifier::distance('', ''))->toBe(INF)
        ->and(ExamImageDeidentifier::distance('ff00', 'f00a'))->toBe(12.5);
});

it('imagem com paleta de cores (GIF/PNG-8) é tarjada de preto mesmo com a paleta cheia', function () {
    $image = layoutFixture('oculus_pentacam_panel_a_pt');
    imagetruecolortopalette($image, false, 256);

    expect((new ExamImageDeidentifier())->deidentify($image))->toBe('oculus_pentacam_panel_a_pt')
        ->and(imageistruecolor($image))->toBeTrue()
        ->and(rectIsBlack($image, [76, 44, 152, 98]))->toBeTrue();
});
