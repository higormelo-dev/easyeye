<?php

use App\Domains\Tiss\Enums\TissGuideType;
use App\Domains\Tiss\Models\{TissBatch, TissGuide, TissGuideItem, TissOperator, TissVersion};
use App\Domains\Tiss\Xml\Builders\V202603TissXmlBuilder;
use App\Models\Entity;
use Illuminate\Support\Collection;
use Tests\TestCase;

uses(TestCase::class);

it('builds tiss xml with consultation and sadt guides', function () {
    $entity = new Entity([
        'code' => 'ENT-000000001',
        'name' => 'CLINICA TESTE',
    ]);

    $operator = new TissOperator([
        'ans_code' => '326305',
    ]);

    $version = new TissVersion([
        'code'           => '202603',
        'layout_version' => '04.03.00',
    ]);

    $consultationGuide = new TissGuide([
        'guide_type'              => TissGuideType::Consultation,
        'guide_number_provider'   => 'GUI-202604-000001',
        'attendance_date'         => '2026-04-04',
        'beneficiary_name'        => 'PACIENTE 01',
        'beneficiary_card_number' => '12345',
        'total_amount'            => 150.00,
    ]);
    $consultationItem = new TissGuideItem([
        'table_code'   => '22',
        'tuss_code'    => '10101012',
        'description'  => 'CONSULTA EM CONSULTORIO',
        'quantity'     => 1,
        'unit_amount'  => 150.00,
        'total_amount' => 150.00,
    ]);
    $consultationGuide->setRelation('items', new Collection([$consultationItem]));

    $sadtGuide = new TissGuide([
        'guide_type'              => TissGuideType::Sadt,
        'guide_number_provider'   => 'GUI-202604-000002',
        'attendance_date'         => '2026-04-04',
        'beneficiary_name'        => 'PACIENTE 02',
        'beneficiary_card_number' => '67890',
        'total_amount'            => 200.00,
    ]);

    $sadtItem = new TissGuideItem([
        'table_code'     => '22',
        'tuss_code'      => '30301257',
        'description'    => 'TOMOGRAFIA DE COERÊNCIA ÓPTICA',
        'quantity'       => 1,
        'unit_amount'    => 200.00,
        'total_amount'   => 200.00,
        'execution_date' => '2026-04-04',
    ]);

    $sadtGuide->setRelation('items', new Collection([$sadtItem]));

    $batch = new TissBatch([
        'batch_number'    => 'LOT-202604-0001',
        'reference_month' => '2026-04',
    ]);

    $batch->setRelation('entity', $entity);
    $batch->setRelation('operator', $operator);
    $batch->setRelation('version', $version);
    $batch->setRelation('guides', new Collection([$consultationGuide, $sadtGuide]));

    $xml = app(V202603TissXmlBuilder::class)->buildBatch($batch);

    expect($xml)->toContain('<mensagemTISS xmlns="http://www.ans.gov.br/padroes/tiss/schemas">')
        ->and($xml)->not->toContain('<ansTISS>')
        // numeroLote é st_texto12 (máx. 12 chars) — batch_number
        // ("LOT-202604-0001", 15 chars) é truncado/alfanumérico só no XML.
        ->and($xml)->toContain('<numeroLote>OT2026040001</numeroLote>')
        ->and($xml)->toContain('<guiaConsulta>')
        ->and($xml)->toContain('<cabecalhoConsulta>')
        ->and($xml)->toContain('<guiaSP-SADT>')
        ->and($xml)->toContain('<codigoProcedimento>30301257</codigoProcedimento>')
        ->and($xml)->toContain('<Padrao>4.03.00</Padrao>')
        ->and($xml)->not->toContain('<versaoPadrao>')
        ->and($xml)->not->toContain('<hashLote>')
        ->and($xml)->not->toContain('<nomeBeneficiario>')
        ->and($xml)->toContain('<atendimentoRN>N</atendimentoRN>')
        ->and($xml)->toContain('<epilogo>')
        ->and($xml)->toContain('<procedimentosExecutados>')
        ->and($xml)->toContain('<valorTotalGeral>200.00</valorTotalGeral>');
});

it('appends eye side laterality to the consultation guide observacao when present', function () {
    $entity   = new Entity(['code' => 'ENT-000000001', 'name' => 'CLINICA TESTE']);
    $operator = new TissOperator(['ans_code' => '326305']);
    $version  = new TissVersion(['code' => '202603', 'layout_version' => '04.03.00']);

    $guide = new TissGuide([
        'guide_type'              => TissGuideType::Consultation,
        'guide_number_provider'   => 'GUI-202604-000009',
        'attendance_date'         => '2026-04-04',
        'beneficiary_name'        => 'PACIENTE 09',
        'beneficiary_card_number' => '99999',
        'total_amount'            => 150.00,
    ]);
    $item = new TissGuideItem([
        'table_code'   => '22',
        'tuss_code'    => '10101012',
        'description'  => 'CONSULTA EM CONSULTORIO',
        'quantity'     => 1,
        'unit_amount'  => 150.00,
        'total_amount' => 150.00,
        'metadata'     => ['eye_side' => 'OD'],
    ]);
    $guide->setRelation('items', new Collection([$item]));

    $batch = new TissBatch(['batch_number' => 'LOT-202604-0009', 'reference_month' => '2026-04']);
    $batch->setRelation('entity', $entity);
    $batch->setRelation('operator', $operator);
    $batch->setRelation('version', $version);
    $batch->setRelation('guides', new Collection([$guide]));

    $xml = app(V202603TissXmlBuilder::class)->buildBatch($batch);

    expect($xml)->toContain('<observacao>Lateralidade: OD</observacao>');

    // ct_procedimentoExecutadoSadt não tem indicacaoAcidente/lateralidade;
    // sem eye_side, observacao (opcional) não deve aparecer.
    $item->metadata = null;
    $xmlWithout     = app(V202603TissXmlBuilder::class)->buildBatch($batch);

    expect($xmlWithout)->not->toContain('<observacao>');
});

it('produces a namespace-qualified document that resolves every element into the ANS TISS namespace', function () {
    $entity   = new Entity(['code' => 'ENT-000000001', 'name' => 'CLINICA TESTE']);
    $operator = new TissOperator(['ans_code' => '326305']);
    $version  = new TissVersion(['code' => '202603', 'layout_version' => '04.03.00']);

    $guide = new TissGuide([
        'guide_type'              => TissGuideType::Consultation,
        'guide_number_provider'   => 'GUI-202604-000005',
        'attendance_date'         => '2026-04-04',
        'beneficiary_name'        => 'PACIENTE 05',
        'beneficiary_card_number' => '55555',
        'total_amount'            => 150.00,
    ]);
    $item = new TissGuideItem([
        'table_code'   => '22',
        'tuss_code'    => '10101012',
        'description'  => 'CONSULTA EM CONSULTORIO',
        'quantity'     => 1,
        'unit_amount'  => 150.00,
        'total_amount' => 150.00,
    ]);
    $guide->setRelation('items', new Collection([$item]));

    $batch = new TissBatch(['batch_number' => 'LOT-202604-0005', 'reference_month' => '2026-04']);
    $batch->setRelation('entity', $entity);
    $batch->setRelation('operator', $operator);
    $batch->setRelation('version', $version);
    $batch->setRelation('guides', new Collection([$guide]));

    $xml = app(V202603TissXmlBuilder::class)->buildBatch($batch);

    $dom = new DOMDocument();
    $dom->loadXML($xml);

    expect($dom->documentElement->namespaceURI)->toBe('http://www.ans.gov.br/padroes/tiss/schemas')
        ->and($dom->getElementsByTagNameNS('http://www.ans.gov.br/padroes/tiss/schemas', 'cabecalho')->length)->toBe(1)
        ->and($dom->getElementsByTagNameNS('http://www.ans.gov.br/padroes/tiss/schemas', 'epilogo')->length)->toBe(1);
});
