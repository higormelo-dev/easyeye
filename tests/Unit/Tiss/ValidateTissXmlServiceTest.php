<?php

declare(strict_types=1);

/**
 * ValidateTissXmlService — achado via auditoria de encerramento, EE-07.
 *
 * Causa raiz: resolveSchemaPath() procurava "ansTISS.xsd", um nome que
 * NUNCA existiu em storage/app/tiss/schemas/{versão}/ (o arquivo real é
 * "tissV4_03_00.xsd" — confirmado por
 * V202603TissXmlBuilderSchemaConformanceTest, que já valida contra ele
 * direto). Sem achar o schema e com require_schema_validation=false
 * (padrão), validate() devolvia valid=true sem nem tentar carregar o XML —
 * um XML quebrado passava como "válido". Sonda da própria auditoria
 * (sondas.json): tiss_invalid_xml_default -> valid=true, schema=null.
 */

use App\Domains\Tiss\Enums\TissGuideType;
use App\Domains\Tiss\Models\{TissBatch, TissGuide, TissGuideItem, TissOperator, TissVersion};
use App\Domains\Tiss\Services\ValidateTissXmlService;
use App\Domains\Tiss\Xml\Builders\V202603TissXmlBuilder;
use App\Models\{Doctor, Entity, People};
use Illuminate\Support\Collection;
use Tests\TestCase;

uses(TestCase::class);

it('[GAP] XML malformado nunca é válido, mesmo sem schema resolvido e com require_schema_validation desligado (EE-07)', function () {
    config(['tiss.require_schema_validation' => false]);

    $result = app(ValidateTissXmlService::class)->validate('<not-valid-xml', null);

    expect($result['valid'])->toBeFalse()
        ->and($result['errors'])->not->toBeEmpty()
        ->and($result['schema'])->toBeNull();
});

it('resolve o schema real da versão 202603 e detecta XML bem formado mas fora do schema TISS', function () {
    $schemaPath = storage_path('app/tiss/schemas/202603/tissV4_03_00.xsd');
    expect(is_file($schemaPath))->toBeTrue();

    $result = app(ValidateTissXmlService::class)->validate('<foo><bar/></foo>', '202603');

    expect($result['schema'])->toBe($schemaPath)
        ->and($result['valid'])->toBeFalse()
        ->and($result['errors'])->not->toBeEmpty();
});

it('XML TISS genuinamente válido passa limpo contra o schema real resolvido', function () {
    $validXml = buildSampleValidTissBatchXml();

    $result = app(ValidateTissXmlService::class)->validate($validXml, '202603');

    expect($result['schema'])->toBe(storage_path('app/tiss/schemas/202603/tissV4_03_00.xsd'))
        ->and($result['valid'])->toBeTrue()
        ->and($result['errors'])->toBeEmpty();
});

it('versão sem schema mapeado cai no fallback de require_schema_validation, sem travar a geração (comportamento preservado)', function () {
    config(['tiss.require_schema_validation' => false]);

    $result = app(ValidateTissXmlService::class)->validate('<foo/>', '999999-sem-mapeamento');

    expect($result['schema'])->toBeNull()
        ->and($result['valid'])->toBeTrue()
        ->and($result['warnings'])->not->toBeEmpty();
});

it('require_schema_validation=true SEM schema resolvido bloqueia (não finge que validou)', function () {
    config(['tiss.require_schema_validation' => true]);

    $result = app(ValidateTissXmlService::class)->validate('<foo/>', '999999-sem-mapeamento');

    expect($result['schema'])->toBeNull()
        ->and($result['valid'])->toBeFalse()
        ->and($result['errors'])->not->toBeEmpty();
});

/**
 * Mesmo XML (envelope mínimo) que V202603TissXmlBuilderSchemaConformanceTest
 * já comprova validar 100% limpo contra o schema oficial — reconstruído
 * aqui via o builder real, não um fixture solto, pra não duplicar
 * conhecimento sobre o formato esperado.
 */
function buildSampleValidTissBatchXml(): string
{
    $entity   = new Entity(['code' => 'ENT-000000001', 'name' => 'CLINICA OFTALMO TESTE', 'state' => 'SP', 'cnes' => '1234567']);
    $operator = new TissOperator(['ans_code' => '326305']);
    $version  = new TissVersion(['code' => '202603', 'layout_version' => '04.03.00']);

    $person = new People(['full_name' => 'DR JOAO TESTE', 'national_registry' => '12345678900']);
    $doctor = new Doctor(['record' => '123456', 'cbo_code' => '225265']);
    $doctor->setRelation('person', $person);

    $guide = new TissGuide([
        'guide_type'              => TissGuideType::Consultation,
        'guide_number_provider'   => 'GUI-202604-000001',
        'attendance_date'         => '2026-04-04',
        'beneficiary_name'        => 'PACIENTE 01',
        'beneficiary_card_number' => '12345',
        'total_amount'            => 150.00,
    ]);
    $guide->setRelation('doctor', $doctor);
    $item = new TissGuideItem([
        'table_code' => '22', 'tuss_code' => '10101012', 'description' => 'CONSULTA',
        'quantity'   => 1, 'unit_amount' => 150.00, 'total_amount' => 150.00,
    ]);
    $guide->setRelation('items', new Collection([$item]));

    $batch = new TissBatch(['batch_number' => 'LOT-202604-0001', 'reference_month' => '2026-04']);
    $batch->setRelation('entity', $entity);
    $batch->setRelation('operator', $operator);
    $batch->setRelation('version', $version);
    $batch->setRelation('guides', new Collection([$guide]));

    return app(V202603TissXmlBuilder::class)->buildBatch($batch);
}
