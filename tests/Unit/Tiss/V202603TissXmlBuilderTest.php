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
    ]);

    $operator = new TissOperator([
        'ans_code' => '326305',
    ]);

    $version = new TissVersion([
        'code' => '202603',
        'layout_version' => '04.03.00',
    ]);

    $consultationGuide = new TissGuide([
        'guide_type' => TissGuideType::Consultation,
        'guide_number_provider' => 'GUI-202604-000001',
        'attendance_date' => '2026-04-04',
        'beneficiary_name' => 'PACIENTE 01',
        'beneficiary_card_number' => '12345',
        'total_amount' => 150.00,
    ]);
    $consultationGuide->setRelation('items', new Collection());

    $sadtGuide = new TissGuide([
        'guide_type' => TissGuideType::Sadt,
        'guide_number_provider' => 'GUI-202604-000002',
        'attendance_date' => '2026-04-04',
        'beneficiary_name' => 'PACIENTE 02',
        'beneficiary_card_number' => '67890',
        'total_amount' => 200.00,
    ]);

    $sadtItem = new TissGuideItem([
        'table_code' => '22',
        'tuss_code' => '30301257',
        'description' => 'TOMOGRAFIA DE COERÊNCIA ÓPTICA',
        'quantity' => 1,
        'unit_amount' => 200.00,
        'total_amount' => 200.00,
        'execution_date' => '2026-04-04',
    ]);

    $sadtGuide->setRelation('items', new Collection([$sadtItem]));

    $batch = new TissBatch([
        'batch_number' => 'LOT-202604-0001',
        'reference_month' => '2026-04',
    ]);

    $batch->setRelation('entity', $entity);
    $batch->setRelation('operator', $operator);
    $batch->setRelation('version', $version);
    $batch->setRelation('guides', new Collection([$consultationGuide, $sadtGuide]));

    $xml = app(V202603TissXmlBuilder::class)->buildBatch($batch);

    expect($xml)->toContain('<ansTISS>')
        ->and($xml)->toContain('<numeroLote>LOT-202604-0001</numeroLote>')
        ->and($xml)->toContain('<guiaConsulta>')
        ->and($xml)->toContain('<guiaSP-SADT>')
        ->and($xml)->toContain('<codigoProcedimento>30301257</codigoProcedimento>');
});
