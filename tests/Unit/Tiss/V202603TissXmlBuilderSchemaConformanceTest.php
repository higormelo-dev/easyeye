<?php

use App\Domains\Tiss\Enums\TissGuideType;
use App\Domains\Tiss\Models\{TissBatch, TissGuide, TissGuideItem, TissOperator, TissVersion};
use App\Domains\Tiss\Xml\Builders\V202603TissXmlBuilder;
use App\Models\{Doctor, Entity, People};
use Illuminate\Support\Collection;
use Tests\TestCase;

uses(TestCase::class);

/**
 * Fase A (envelope/namespace/epilogo/estrutura) + Fase B (CNES da entidade,
 * CPF/CRM/CBO do médico via doctors.cbo_code e people.national_registry)
 * juntas fecham o gap: com os dois preenchidos, o XML gerado valida 100%
 * limpo contra o XSD oficial TISS 4.03.00 (copiado em
 * storage/app/tiss/schemas/202603/) — confirmado rodando
 * DOMDocument::schemaValidate() de verdade, não é estimativa.
 *
 * Sem CNES/CBO/CRM (dado ainda não cadastrado pela clínica — os campos são
 * opcionais no formulário), a mensagem continua estruturalmente correta mas
 * falha nos elementos que dependem desse dado — por isso
 * TISS_REQUIRE_SCHEMA_VALIDATION continua false por padrão em produção
 * (ver config/tiss.php): nem toda clínica vai ter preenchido CNES/CBO no
 * dia em que isso for ligado.
 *
 * Achado à parte (não é sobre CNES/CBO): guiasTISS é um <choice> no XSD real
 * — um lote só pode ter UM tipo de guia (só consulta OU só SP-SADT, nunca
 * os dois juntos). Testado em lotes separados por tipo abaixo porque é
 * assim que BillingService::createBatch() já opera hoje (guide_type fica
 * fixo em "consultation" pra todo o lote) — misturar os dois no mesmo lote
 * é inválido pelo schema e ficaria fora do escopo desta correção estrutural
 * caso vire necessário no futuro (exigiria dividir o lote por tipo).
 */
it('validates cleanly against the official ANS 4.03.00 schema when cnes and doctor identification are present', function () {
    $schemaPath = storage_path('app/tiss/schemas/202603/tissV4_03_00.xsd');

    expect(is_file($schemaPath))->toBeTrue();

    $entity = new Entity([
        'code'  => 'ENT-000000001',
        'name'  => 'CLINICA OFTALMO TESTE',
        'state' => 'SP',
        'cnes'  => '1234567',
    ]);
    $operator = new TissOperator(['ans_code' => '326305']);
    $version  = new TissVersion(['code' => '202603', 'layout_version' => '04.03.00']);

    $person = new People(['full_name' => 'DR JOAO TESTE', 'national_registry' => '12345678900']);
    $doctor = new Doctor(['record' => '123456', 'cbo_code' => '225265']);
    $doctor->setRelation('person', $person);

    $consultationGuide = new TissGuide([
        'guide_type'              => TissGuideType::Consultation,
        'guide_number_provider'   => 'GUI-202604-000001',
        'attendance_date'         => '2026-04-04',
        'beneficiary_name'        => 'PACIENTE 01',
        'beneficiary_card_number' => '12345',
        'total_amount'            => 150.00,
    ]);
    $consultationGuide->setRelation('doctor', $doctor);
    $consultationItem = new TissGuideItem([
        'table_code' => '22', 'tuss_code' => '10101012', 'description' => 'CONSULTA',
        'quantity'   => 1, 'unit_amount' => 150.00, 'total_amount' => 150.00,
    ]);
    $consultationGuide->setRelation('items', new Collection([$consultationItem]));

    $sadtGuide = new TissGuide([
        'guide_type'              => TissGuideType::Sadt,
        'guide_number_provider'   => 'GUI-202604-000002',
        'attendance_date'         => '2026-04-04',
        'beneficiary_name'        => 'PACIENTE 02',
        'beneficiary_card_number' => '67890',
        'total_amount'            => 200.00,
        'clinical_indication'     => 'H40.1',
    ]);
    $sadtGuide->setRelation('doctor', $doctor);
    $sadtItem = new TissGuideItem([
        'table_code' => '22', 'tuss_code' => '30301257', 'description' => 'TOMOGRAFIA DE COERENCIA OPTICA',
        'quantity'   => 1, 'unit_amount' => 200.00, 'total_amount' => 200.00, 'execution_date' => '2026-04-04',
    ]);
    $sadtGuide->setRelation('items', new Collection([$sadtItem]));

    // Lotes separados por tipo de guia — guiasTISS é um <choice> no schema
    // real, não dá pra misturar guiaConsulta e guiaSP-SADT no mesmo lote.
    $consultationBatch = new TissBatch(['batch_number' => 'LOT-202604-0001', 'reference_month' => '2026-04']);
    $consultationBatch->setRelation('entity', $entity);
    $consultationBatch->setRelation('operator', $operator);
    $consultationBatch->setRelation('version', $version);
    $consultationBatch->setRelation('guides', new Collection([$consultationGuide]));

    $sadtBatch = new TissBatch(['batch_number' => 'LOT-202604-0002', 'reference_month' => '2026-04']);
    $sadtBatch->setRelation('entity', $entity);
    $sadtBatch->setRelation('operator', $operator);
    $sadtBatch->setRelation('version', $version);
    $sadtBatch->setRelation('guides', new Collection([$sadtGuide]));

    $builder = app(V202603TissXmlBuilder::class);

    foreach ([$consultationBatch, $sadtBatch] as $batch) {
        $xml = $builder->buildBatch($batch);

        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadXML($xml);
        $isValid = $dom->schemaValidate($schemaPath);
        $errors  = libxml_get_errors();
        libxml_clear_errors();

        expect($errors)->toBeEmpty()
            ->and($isValid)->toBeTrue();
    }
});

it('documents that the batch xml still fails validation when cnes and doctor identification are absent', function () {
    $schemaPath = storage_path('app/tiss/schemas/202603/tissV4_03_00.xsd');

    $entity   = new Entity(['code' => 'ENT-000000001', 'national_registration' => '12345678000199']);
    $operator = new TissOperator(['ans_code' => '326305']);
    $version  = new TissVersion(['code' => '202603', 'layout_version' => '04.03.00']);

    $guide = new TissGuide([
        'guide_type'              => TissGuideType::Consultation,
        'guide_number_provider'   => 'GUI-202604-000001',
        'attendance_date'         => '2026-04-04',
        'beneficiary_name'        => 'PACIENTE 01',
        'beneficiary_card_number' => '12345',
        'total_amount'            => 150.00,
    ]);
    $guide->setRelation('items', new Collection());

    $batch = new TissBatch(['batch_number' => 'LOT-202604-0001', 'reference_month' => '2026-04']);
    $batch->setRelation('entity', $entity);
    $batch->setRelation('operator', $operator);
    $batch->setRelation('version', $version);
    $batch->setRelation('guides', new Collection([$guide]));

    $xml = app(V202603TissXmlBuilder::class)->buildBatch($batch);

    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadXML($xml);
    $isValid = @$dom->schemaValidate($schemaPath);
    libxml_clear_errors();

    expect($isValid)->toBeFalse();
});
