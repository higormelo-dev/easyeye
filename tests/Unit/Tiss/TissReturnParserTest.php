<?php

use App\Domains\Tiss\Parsers\TissReturnParser;

it('parses protocol status and glosas from namespaced tiss xml', function () {
    $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<ans:ansTISS xmlns:ans="http://www.ans.gov.br/padroes/tiss/schemas">
  <ans:operadoraParaPrestador>
    <ans:protocoloRecebimento>
      <ans:numeroProtocolo>PRT-20260404-001</ans:numeroProtocolo>
      <ans:statusProtocolo>Aceito</ans:statusProtocolo>
    </ans:protocoloRecebimento>
    <ans:loteGuias>
      <ans:numeroLote>LOT-202604-0001</ans:numeroLote>
      <ans:guiasTISS>
        <ans:guiaSP-SADT>
          <ans:cabecalhoGuia>
            <ans:numeroGuiaPrestador>GUI-202604-000111</ans:numeroGuiaPrestador>
          </ans:cabecalhoGuia>
          <ans:procedimentoExecutado>
            <ans:codigoProcedimento>30301257</ans:codigoProcedimento>
            <ans:glosa>
              <ans:codigoGlosa>1001</ans:codigoGlosa>
              <ans:descricaoGlosa>Valor acima da tabela</ans:descricaoGlosa>
              <ans:valorGlosa>150.50</ans:valorGlosa>
            </ans:glosa>
          </ans:procedimentoExecutado>
        </ans:guiaSP-SADT>
      </ans:guiasTISS>
    </ans:loteGuias>
  </ans:operadoraParaPrestador>
</ans:ansTISS>
XML;

    $parser = new TissReturnParser();

    $data = $parser->parse($xml);

    expect($data['protocol_number'])->toBe('PRT-20260404-001')
        ->and($data['batch_number'])->toBe('LOT-202604-0001')
        ->and($data['status'])->toBe('accepted')
        ->and($data['glosas'])->toHaveCount(1)
        ->and($data['glosas'][0]['code'])->toBe('1001')
        ->and($data['glosas'][0]['amount'])->toBe(150.50)
        ->and($data['glosas'][0]['guide_number_provider'])->toBe('GUI-202604-000111')
        ->and($data['glosas'][0]['procedure_code'])->toBe('30301257');
});
