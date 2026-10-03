<?php

use App\Services\Medicines\CmedPresentationParser;

/**
 * Coluna APRESENTAÇÃO da lista CMED → concentração + forma farmacêutica.
 * Casos tirados da lista real (set/2026).
 */
dataset('apresentacoes', [
    'suspensão oftálmica'     => ['10 MG/ML SUS OFT CT FR GOT PLAS OPC X 5 ML', '10 MG/ML', 'susp_oft', true],
    'SUSP sem espaço na dose' => ['10MG/ML SUSP OFT CT FR PLAS OPC GOT X 5ML', '10MG/ML', 'susp_oft', true],
    'espaço duplo'            => ['1,2 MG/ML SUS OFT  FR PLAS OPC GOT X 5 ML', '1,2 MG/ML', 'susp_oft', true],
    'associação'              => ['3 MG/ML + 10 MG/ML SUS OFT CT FR GOT PLAS OPC X 3 ML', '3 MG/ML + 10 MG/ML', 'susp_oft', true],
    'pomada oftálmica'        => ['5 MG/G POM OFT CT BG AL X 3,5 G', '5 MG/G', 'pom_oft', true],
    'comprimido revestido'    => ['20 MG COM REV CT BL AL PLAS PVC TRANS X 30', '20 MG', 'com_rev', false],
    'cápsula dura'            => ['500 MG CAP DURA CT BL X 21', '500 MG', 'cap', false],
    'creme (não é oftálmico)' => ['10 MG/G + 0,443 MG/G CREM DERM CT BG AL X 40 G', '10 MG/G + 0,443 MG/G', 'crem', false],
    'sem concentração'        => ['SOL OFT CT FR GOT X 10 ML', null, 'sol_oft', true],
]);

it('separa concentração e forma da apresentação', function (string $text, ?string $concentration, string $form, bool $ophthalmic) {
    expect((new CmedPresentationParser())->parse($text))->toBe([
        'concentration' => $concentration,
        'form'          => $form,
        'is_ophthalmic' => $ophthalmic,
    ]);
})->with('apresentacoes');

it('forma desconhecida vira null sem perder o flag oftálmico', function () {
    expect((new CmedPresentationParser())->parse('KIT OFT XYZ'))->toBe([
        'concentration' => null,
        'form'          => null,
        'is_ophthalmic' => true,
    ]);
});
