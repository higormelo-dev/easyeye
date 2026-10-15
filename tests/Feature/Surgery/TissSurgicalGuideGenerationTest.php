<?php

declare(strict_types=1);

use App\Domains\Tiss\Enums\{TissGuideStatus, TissGuideType, TissXmlStatus};
use App\Domains\Tiss\Models\{TissGuide, TissGuideLink};
use App\Domains\Tiss\Services\ValidateTissXmlService;
use App\Domains\Tiss\Surgical\{SurgicalGuideService, SurgicalMessageXmlService};
use App\Exceptions\Surgery\SurgeryRuleException;
use App\Services\Surgery\SurgicalCaseService;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;

require_once __DIR__ . '/../../Support/TissSurgicalFixtures.php';

/*
 * M3b — guias TISS do caso cirúrgico: geração a partir do caso, retrato, XML contra o
 * XSD 4.03.00 e pré-validação por tipo.
 */

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-15 10:00:00'));
});

it('gera a solicitação de internação a partir do caso: retrato, itens, operadora e vínculo ao caso', function () {
    $w = stcWorld();

    $guide = app(SurgicalGuideService::class)->generate($w->case, TissGuideType::InpatientRequest);

    expect($guide->guide_type)->toBe(TissGuideType::InpatientRequest)
        ->and($guide->status)->toBe(TissGuideStatus::Draft)
        ->and((string) $guide->surgical_case_id)->toBe((string) $w->case->id)
        ->and((string) $guide->patient_id)->toBe((string) $w->patient->id)
        ->and((string) $guide->doctor_id)->toBe((string) $w->doctor->id)
        ->and($guide->beneficiary_card_number)->toBe('0099887766')
        ->and($guide->guide_number_provider)->toStartWith('GUI-')
        ->and($guide->operator->ans_code)->toBe('326305')
        ->and($guide->items)->toHaveCount(1)
        ->and($guide->items->first()->tuss_code)->toBe('30306019')
        ->and($guide->items->first()->table_code)->toBe('22');

    $data = $guide->payload['surgical']['data'];

    expect($data['diagnoses'])->toBe(['H251'])
        ->and($data['regime_code'])->toBe('2')
        ->and($data['urgency_code'])->toBe('1')
        ->and($data['inpatient_type_code'])->toBe('2')
        ->and($data['opme'])->toBeTrue()
        ->and($data['hospital']['operator_code'])->toBe('HOSP-001')
        ->and($data['professional']['uf'])->toBe('35')
        ->and($data['professional']['council_number'])->toBe('123456')
        ->and($data['professional']['phone'])->toBe('11987654321');
});

it('gerar de novo devolve a MESMA guia (clique duplo) e o índice único segura o resto', function () {
    $w       = stcWorld();
    $service = app(SurgicalGuideService::class);

    $first  = $service->generate($w->case, TissGuideType::InpatientRequest);
    $second = $service->generate($w->case, TissGuideType::InpatientRequest);

    expect((string) $second->id)->toBe((string) $first->id)
        ->and(TissGuide::query()->where('surgical_case_id', $w->case->id)->count())->toBe(1);

    expect(fn () => TissGuide::query()->create([
        'entity_id'             => $w->entity->id,
        'operator_id'           => $first->operator_id,
        'guide_type'            => 'inpatient_request',
        'guide_number_provider' => 'GUI-OUTRA',
        'status'                => 'draft',
        'attendance_date'       => now()->toDateString(),
        'surgical_case_id'      => $w->case->id,
    ]))->toThrow(UniqueConstraintViolationException::class);
});

it('anexo de OPME nasce ligado à internação e referencia o número dela', function () {
    $w       = stcWorld();
    $service = app(SurgicalGuideService::class);

    $opme      = $service->generate($w->case, TissGuideType::OpmeAttachment);
    $inpatient = $service->generate($w->case, TissGuideType::InpatientRequest);

    $link = TissGuideLink::query()->where('child_guide_id', $opme->id)->firstOrFail();

    expect((string) $link->parent_guide_id)->toBe((string) $inpatient->id)
        ->and($link->link_type)->toBe('opme_attachment')
        // OPME gerado ANTES da internação: ficou sem a referência — e a guia sabe que está atrasada.
        ->and($service->isStale($opme->refresh()))->toBeTrue();

    $service->refresh($opme);

    expect($opme->refresh()->payload['surgical']['data']['referenced_guide_number'])->toBe($inpatient->guide_number_provider)
        ->and($service->isStale($opme))->toBeFalse();
});

it('não gera guia para caso particular, regime ambulatorial ou sem OPME', function () {
    $w       = stcWorld();
    $service = app(SurgicalGuideService::class);

    $w->patient->update(['covenant_id' => $w->particular->id]);
    $private = app(SurgicalCaseService::class)->create((string) $w->entity->id, [
        'patient_id' => (string) $w->patient->id, 'surgeon_doctor_id' => (string) $w->doctor->id,
        'title'      => 'Particular', 'eye' => 'od', 'regime' => 'day_hospital', 'covenant_id' => (string) $w->particular->id,
        'procedures' => [['description' => 'X', 'quantity' => 1]],
    ]);

    expect(fn () => $service->generate($private, TissGuideType::InpatientRequest))
        ->toThrow(SurgeryRuleException::class, __('surgery_tiss.errors.not_insurer'));

    $ambulatory = stcWorld(['regime' => 'ambulatory', 'surgery_facility_id' => null], opme: false);

    expect(fn () => $service->generate($ambulatory->case, TissGuideType::InpatientRequest))
        ->toThrow(SurgeryRuleException::class, __('surgery_tiss.errors.regime_ambulatory'));
    expect(fn () => $service->generate($ambulatory->case, TissGuideType::OpmeAttachment))
        ->toThrow(SurgeryRuleException::class, __('surgery_tiss.errors.no_opme'));
});

it('gera o XML da internação VÁLIDO contra o XSD 4.03.00 e na estrutura de solicitação avulsa', function () {
    $w = stcWorld();
    stcRequested($w);

    $guide    = stcComplete(app(SurgicalGuideService::class)->generate($w->case, TissGuideType::InpatientRequest));
    $document = app(SurgicalMessageXmlService::class)->generate($guide);

    expect($document->status)->toBe(TissXmlStatus::Validated)
        ->and($document->document_type)->toBe('inpatient_request')
        ->and($document->metadata['schema'])->not->toBeNull()
        ->and((string) $document->guide_id)->toBe((string) $guide->id);

    $xml = $document->xml_content;

    expect($xml)->toContain('<tipoTransacao>SOLICITACAO_PROCEDIMENTOS</tipoTransacao>')
        ->and($xml)->toContain('<solicitacaoProcedimento>')
        ->and($xml)->toContain('<solicitacaoInternacao>')
        ->and($xml)->not->toContain('loteGuias')
        ->and($xml)->not->toContain('guiaSP-SADT')
        ->and($xml)->toContain('<diagnosticoCID>H251</diagnosticoCID>')
        ->and($xml)->toContain('<codigoIndicadonaOperadora>HOSP-001</codigoIndicadonaOperadora>')
        ->and($xml)->toContain('<regimeInternacao>2</regimeInternacao>')
        ->and($xml)->toContain('<indicadorOPME>S</indicadorOPME>')
        ->and($xml)->toContain('<dataSugeridaInternacao>2026-11-03</dataSugeridaInternacao>')
        ->and($xml)->toContain('<UF>35</UF>')
        ->and($guide->refresh()->status)->toBe(TissGuideStatus::XmlGenerated)
        ->and((string) $guide->xml_document_id)->toBe((string) $document->id);

    expect(app(ValidateTissXmlService::class)->validate($xml, '202603'))->toMatchArray(['valid' => true, 'errors' => []]);
});

it('gera o XML do anexo de OPME VÁLIDO contra o XSD (envelope loteAnexos / ENVIO_ANEXO)', function () {
    $w = stcWorld();
    stcRequested($w);
    $service = app(SurgicalGuideService::class);

    $inpatient = $service->generate($w->case, TissGuideType::InpatientRequest);
    $opme      = $service->generate($w->case, TissGuideType::OpmeAttachment);

    $document = app(SurgicalMessageXmlService::class)->generate(stcComplete($opme));
    $xml      = $document->xml_content;

    expect($document->status)->toBe(TissXmlStatus::Validated)
        ->and($document->document_type)->toBe('opme_attachment')
        ->and($xml)->toContain('<tipoTransacao>ENVIO_ANEXO</tipoTransacao>')
        ->and($xml)->toContain('<loteAnexos>')
        ->and($xml)->toContain('<anexoSolicitacaoOPME>')
        ->and($xml)->toContain('<numeroGuiaReferenciada>' . $inpatient->guide_number_provider . '</numeroGuiaReferenciada>')
        ->and($xml)->toContain('<codigoProcedimento>70017031</codigoProcedimento>')
        ->and($xml)->toContain('<registroANVISA>80117580005</registroANVISA>')
        ->and($xml)->toContain('<codigoRefFabricante>SN60WF</codigoRefFabricante>')
        ->and($xml)->toContain('<opcaoFabricante>1</opcaoFabricante>')
        ->and($xml)->toContain('<telefoneProfissional>11987654321</telefoneProfissional>')
        ->and($xml)->toContain('<valorSolicitado>850.00</valorSolicitado>')
        ->and($xml)->not->toContain('loteGuias');
});
