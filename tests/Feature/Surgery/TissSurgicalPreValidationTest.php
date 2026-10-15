<?php

declare(strict_types=1);

use App\Domains\Tiss\Enums\{TissGuideStatus, TissGuideType, TissXmlStatus};
use App\Domains\Tiss\Models\TissGuide;
use App\Domains\Tiss\Services\PreValidateTissGuideService;
use App\Domains\Tiss\Surgical\{SendSurgicalGuideService, SurgicalGuideService, SurgicalMessageXmlService};
use App\Domains\Tiss\Surgical\SurgicalGuideSnapshot;
use App\Domains\Tiss\Xml\TissXmlContentFingerprint;
use App\Exceptions\Surgery\SurgeryRuleException;
use Carbon\Carbon;

require_once __DIR__ . '/../../Support/TissSurgicalFixtures.php';

/*
 * M3b — pré-validação por tipo e rigor do XML (XSD): o que a operadora recusa não sai.
 */

function stcCodes(TissGuide $guide, string $severity = 'error'): array
{
    $result = app(PreValidateTissGuideService::class)->validate($guide->refresh());

    return ($severity === 'error' ? $result->errors() : $result->warnings())->pluck('code')->all();
}

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-15 10:00:00'));
});

it('internação: acusa o que falta no hospital, no CRM, na UF, no CID e na data sugerida — com texto traduzido', function () {
    $w = stcWorld([
        'cid_codes'           => [],
        'clinical_indication' => null,
    ]);
    $w->facility->update(['operator_code' => null, 'cnes' => null]);
    $w->doctor->update(['record' => null]);
    $w->entity->update(['state' => 'XX']);

    $guide = app(SurgicalGuideService::class)->generate($w->case->refresh(), TissGuideType::InpatientRequest);

    expect(stcCodes($guide))->toContain('HOSPITAL_CODE_REQUIRED', 'SUGGESTED_DATE_REQUIRED', 'CID_REQUIRED', 'CLINICAL_INDICATION_REQUIRED', 'PROFESSIONAL_COUNCIL_REQUIRED', 'PROFESSIONAL_UF_REQUIRED')
        ->and(stcCodes($guide, 'warning'))->toContain('HOSPITAL_CNES_RECOMMENDED');

    $issue = app(PreValidateTissGuideService::class)->validate($guide)->errors()->firstWhere('code', 'HOSPITAL_CODE_REQUIRED');

    expect($issue->message)->toBe(__('tiss_prevalidation.HOSPITAL_CODE_REQUIRED.message'))
        ->and($issue->message)->not->toContain('tiss_prevalidation')
        ->and($issue->operatorHint)->toContain('UNIMED');
});

it('internação completa passa limpa (só o que o XSD exige)', function () {
    $w     = stcWorld();
    $guide = stcComplete(app(SurgicalGuideService::class)->generate($w->case, TissGuideType::InpatientRequest));

    expect(stcCodes($guide))->toBe([]);
});

it('OPME: sem ANVISA, opção inválida, sem código, sem justificativa, sem telefone e sem guia referenciada', function () {
    $w = stcWorld(['materials' => [['description' => 'Anel', 'quantity' => 1, 'manufacturer_option' => '7']]]);
    $w->doctor->person->update(['cellphone' => '', 'telephone' => '']);

    $guide = app(SurgicalGuideService::class)->generate($w->case->refresh(), TissGuideType::OpmeAttachment);

    expect(stcCodes($guide))->toContain('OPME_REFERENCED_GUIDE_REQUIRED', 'OPME_PROFESSIONAL_PHONE_REQUIRED', 'OPME_JUSTIFICATION_REQUIRED', 'OPME_ANVISA_REQUIRED', 'TUSS_CODE_MISSING');

    // O que a secretária completa na guia resolve (e reabre a validação).
    app(SurgicalGuideService::class)->complete($guide, [
        'referenced_guide_number' => 'GUI-REF-1', 'professional_phone' => '(11) 98888-7777', 'justification' => 'Subluxação do cristalino',
        'items'                   => [['id' => (string) $guide->items()->value('id'), 'tuss_code' => '70017031', 'anvisa_registration' => '80117580005', 'manufacturer_option' => '2']],
    ]);

    expect(stcCodes($guide))->toBe([]);
});

it('o que foi completado à mão SOBREVIVE à atualização a partir do caso', function () {
    $w     = stcWorld();
    $guide = stcComplete(app(SurgicalGuideService::class)->generate($w->case, TissGuideType::OpmeAttachment), ['professional_phone' => '11955554444']);
    $item  = $guide->items()->first();

    $w->case->refresh()->forceFill(['clinical_indication' => 'mudou'])->save();
    app(SurgicalGuideService::class)->refresh($guide);

    $kept = $guide->refresh()->items()->first();

    expect($kept->tuss_code)->toBe('70017031')
        ->and(SurgicalGuideSnapshot::of($guide)->string('professional.phone'))->toBe('11955554444')
        ->and((string) $kept->metadata['source_id'])->toBe((string) $item->metadata['source_id']);
});

it('XML que o XSD recusa nunca sai: documento inválido, guia volta a rascunho e o envio é barrado', function () {
    // CBO-S que a pré-validação não conhece mas o XSD (dm_CBOS) recusa.
    $w = stcWorld();
    $w->doctor->update(['cbo_code' => '000000']);
    stcRequested($w);

    $guide    = stcComplete(app(SurgicalGuideService::class)->generate($w->case->refresh(), TissGuideType::InpatientRequest));
    $document = app(SurgicalMessageXmlService::class)->generate($guide);

    expect($document->status)->toBe(TissXmlStatus::Invalid)
        ->and(implode(' ', (array) $document->validation_errors))->toContain('CBOS')
        ->and($guide->refresh()->status)->toBe(TissGuideStatus::Draft)
        ->and($guide->xml_document_id)->toBeNull()
        ->and($guide->errors[0]['code'])->toBe('XSD');

    $fake = stcFakeTransport();

    expect(fn () => app(SendSurgicalGuideService::class)->send($guide->refresh()))
        ->toThrow(SurgeryRuleException::class);
    expect($fake->calls)->toBe([]);
});

it('texto livre com & < > e aspas sai ESCAPADO e o XML continua válido', function () {
    $w = stcWorld(['procedures' => [['description' => 'Facoemulsificação <OD> & "LIO" (A&B)', 'tuss_code' => '30306019', 'quantity' => 1]]]);
    stcRequested($w);

    $guide    = stcComplete(app(SurgicalGuideService::class)->generate($w->case->refresh(), TissGuideType::InpatientRequest), ['observation' => 'Obs & <b>negrito</b>']);
    $document = app(SurgicalMessageXmlService::class)->generate($guide);

    expect($document->status)->toBe(TissXmlStatus::Validated)
        ->and($document->xml_content)->toContain('&lt;OD&gt; &amp; "LIO" (A&amp;B)')
        ->and($document->xml_content)->toContain('Obs &amp; &lt;b&gt;negrito&lt;/b&gt;');
});

it('gerar o XML de novo sem nada mudar (e antes de qualquer envio) mantém a guia consistente', function () {
    $w     = stcWorld();
    $guide = stcComplete(app(SurgicalGuideService::class)->generate($w->case, TissGuideType::InpatientRequest));
    $xml   = app(SurgicalMessageXmlService::class);

    $first  = $xml->generate($guide);
    $second = $xml->generate($guide->refresh());

    expect($guide->refresh()->xml_document_id)->toBe($second->id)
        ->and(TissXmlContentFingerprint::of($first->xml_content))
        ->toBe(TissXmlContentFingerprint::of($second->xml_content));
});
