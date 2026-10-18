<?php

use App\Support\Fiscal\NfseDiscriminacao;

it('troca cada placeholder pelo valor', function () {
    $text = NfseDiscriminacao::render(
        'Assinatura do plano {plano} ({ciclo}) — período {periodo} — fatura {fatura}',
        ['plano' => 'Plano Pro', 'ciclo' => 'Mensal', 'periodo' => '01/10/2026 a 31/10/2026', 'fatura' => 'INV-001'],
    );

    expect($text)->toBe('Assinatura do plano Plano Pro (Mensal) — período 01/10/2026 a 31/10/2026 — fatura INV-001');
});

it('renderiza o modelo do pacote de IA e o do cliente', function () {
    expect(NfseDiscriminacao::render('Pacote de créditos de IA ({creditos} créditos) — fatura {fatura}', ['creditos' => 500, 'fatura' => 'INV-9']))
        ->toBe('Pacote de créditos de IA (500 créditos) — fatura INV-9')
        ->and(NfseDiscriminacao::render('Serviços para {cliente}', ['cliente' => 'CLINICA X']))->toBe('Serviços para CLINICA X');
});

it('repete o mesmo placeholder onde ele aparece', function () {
    expect(NfseDiscriminacao::render('{fatura} / {fatura}', ['fatura' => 'A1']))->toBe('A1 / A1');
});

it('placeholder sem valor some, com os parênteses vazios e os espaços que ele deixaria', function () {
    expect(NfseDiscriminacao::render('Plano {plano} ({ciclo}) fatura {fatura}', ['plano' => 'Pro', 'ciclo' => '', 'fatura' => 'INV-1']))
        ->toBe('Plano Pro fatura INV-1')
        ->and(NfseDiscriminacao::render('Plano {plano} ({ciclo})', ['plano' => 'Pro', 'ciclo' => null]))->toBe('Plano Pro');
});

it('placeholder desconhecido nunca vaza para a nota', function () {
    $text = NfseDiscriminacao::render('Serviço {inventado} da {fatura}', ['fatura' => 'INV-1']);

    expect($text)->toBe('Serviço da INV-1')
        ->and($text)->not->toContain('{');
});

it('mantém acentos, travessão e o resto do texto livre', function () {
    expect(NfseDiscriminacao::render('Licença de uso do software — assinatura mensal', []))->toBe('Licença de uso do software — assinatura mensal');
});

it('limita ao tamanho do padrão nacional (1000 caracteres), por caractere', function () {
    $text = NfseDiscriminacao::render(str_repeat('Ã', 1200), []);

    expect(mb_strlen($text))->toBe(1000)
        ->and(mb_check_encoding($text, 'UTF-8'))->toBeTrue();
});

it('não interpreta o valor como modelo (valor com chaves não vira outro placeholder)', function () {
    expect(NfseDiscriminacao::render('Fatura {fatura}', ['fatura' => '{plano}', 'plano' => 'Pro']))->toBe('Fatura {plano}');
});

it('expõe a lista de placeholders aceitos', function () {
    expect(NfseDiscriminacao::PLACEHOLDERS)->toBe(['plano', 'ciclo', 'periodo', 'fatura', 'creditos', 'cliente']);
});
