<?php

declare(strict_types=1);

use App\Domains\Tiss\Xml\TissXmlContentFingerprint;

/**
 * Impressão digital do conteúdo de uma mensagem TISS: ignora SÓ o que o
 * builder preenche com o relógio (data/hora do cabeçalho e o hash do epílogo,
 * que é o md5 do corpo com o horário dentro) — qualquer outro dado muda a
 * impressão.
 */
function tissFingerprintXml(string $time = '12:00:00', string $date = '2026-10-12', string $hash = 'aaaa', string $card = '1234567890', string $cnes = '1234567'): string
{
    return <<<XML
        <?xml version="1.0" encoding="UTF-8"?>
        <mensagemTISS xmlns="http://www.ans.gov.br/padroes/tiss/schemas">
          <cabecalho>
            <identificacaoTransacao>
              <tipoTransacao>ENVIO_LOTE_GUIAS</tipoTransacao>
              <sequencialTransacao>OT2026100001</sequencialTransacao>
              <dataRegistroTransacao>{$date}</dataRegistroTransacao>
              <horaRegistroTransacao>{$time}</horaRegistroTransacao>
            </identificacaoTransacao>
            <destino><registroANS>326305</registroANS></destino>
            <Padrao>4.03.00</Padrao>
          </cabecalho>
          <prestadorParaOperadora>
            <loteGuias>
              <numeroLote>OT2026100001</numeroLote>
              <guiasTISS>
                <guiaConsulta>
                  <dadosBeneficiario><numeroCarteira>{$card}</numeroCarteira></dadosBeneficiario>
                  <contratadoExecutante><CNES>{$cnes}</CNES></contratadoExecutante>
                </guiaConsulta>
              </guiasTISS>
            </loteGuias>
          </prestadorParaOperadora>
          <epilogo><hash>{$hash}</hash></epilogo>
        </mensagemTISS>
        XML;
}

it('mesma mensagem gerada em outro segundo (data, hora e hash do epílogo diferentes) tem a MESMA impressão', function () {
    $a = TissXmlContentFingerprint::of(tissFingerprintXml('12:00:00', '2026-10-12', 'aaaa'));
    $b = TissXmlContentFingerprint::of(tissFingerprintXml('12:00:15', '2026-10-13', 'bbbb'));

    expect($a)->toBeString()->not->toBeEmpty()
        ->and($b)->toBe($a);
});

it('dado de verdade mudado (carteirinha, CNES) muda a impressão — só o relógio é ignorado', function () {
    $base = TissXmlContentFingerprint::of(tissFingerprintXml());

    expect(TissXmlContentFingerprint::of(tissFingerprintXml(card: '9999999999')))->not->toBe($base)
        ->and(TissXmlContentFingerprint::of(tissFingerprintXml(cnes: '7654321')))->not->toBe($base);
});

it('só os nós do relógio no cabeçalho e o epílogo da RAIZ são ignorados — mesmo nome em outro lugar é conteúdo', function () {
    $withNested = str_replace(
        '<numeroCarteira>1234567890</numeroCarteira>',
        '<numeroCarteira>1234567890</numeroCarteira><horaRegistroTransacao>08:00:00</horaRegistroTransacao>',
        tissFingerprintXml(),
    );
    $withNestedOther = str_replace('08:00:00', '09:00:00', $withNested);

    expect(TissXmlContentFingerprint::of($withNested))
        ->not->toBe(TissXmlContentFingerprint::of(tissFingerprintXml()))
        ->not->toBe(TissXmlContentFingerprint::of($withNestedOther));
});

it('identação diferente não muda a impressão (compara estrutura, não bytes)', function () {
    $compact = preg_replace('/>\s+</', '><', tissFingerprintXml());

    expect(TissXmlContentFingerprint::of($compact))->toBe(TissXmlContentFingerprint::of(tissFingerprintXml()));
});

it('texto que não é XML bem formado não tem impressão (nada a comparar)', function (string $text) {
    expect(TissXmlContentFingerprint::of($text))->toBeNull();
})->with(['vazio' => '', 'lixo' => 'isto não é xml', 'tag aberta' => '<mensagemTISS><cabecalho></mensagemTISS>']);

it('XML sem namespace (fixtures antigas) também é aceito', function () {
    $plain = '<ansTISS><cabecalho><identificacaoTransacao><horaRegistroTransacao>10:00:00</horaRegistroTransacao></identificacaoTransacao></cabecalho><corpo>x</corpo></ansTISS>';
    $later = str_replace('10:00:00', '10:00:09', $plain);

    expect(TissXmlContentFingerprint::of($plain))->toBeString()
        ->toBe(TissXmlContentFingerprint::of($later));
});
