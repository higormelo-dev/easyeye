<?php

declare(strict_types=1);

use App\Domains\Tiss\DTOs\SurgicalGuideData;
use App\Domains\Tiss\Enums\{TissGuideStatus, TissGuideType, TissTransmissionStatus};
use App\Domains\Tiss\Models\{TissGuide, TissTransmission};
use App\Domains\Tiss\Surgical\SurgicalGuideService;
use App\Enums\Surgery\SurgicalCaseStatus;
use App\Models\DataAccessLog;
use App\Services\Surgery\SurgicalCaseService;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

require_once __DIR__ . '/../../Support/TissSurgicalFixtures.php';

/*
 * M3b — HTTP do TISS do caso: perfis, isolamento (IDOR), fluxo completo e a aba.
 */

function stcUrl(string $name, $case, ?TissGuide $guide = null): string
{
    return route('panel.surgery.tiss.guides.' . $name, array_filter(['surgicalCase' => $case, 'guide' => $guide]));
}

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-15 10:00:00'));
    $this->w    = stcWorld();
    $this->fake = stcFakeTransport();
});

it('secretária e admin geram a guia; financeiro e clínica sem o recurso não', function () {
    stcRequested($this->w);

    surgeryAs($this->w, 'secretary')->post(stcUrl('store', $this->w->case), ['type' => 'inpatient'])->assertRedirect();
    expect(TissGuide::query()->where('surgical_case_id', $this->w->case->id)->count())->toBe(1);

    surgeryAs($this->w, 'admin')->post(stcUrl('store', $this->w->case), ['type' => 'opme'])->assertRedirect();
    expect(TissGuide::query()->where('surgical_case_id', $this->w->case->id)->count())->toBe(2);

    surgeryAs($this->w, 'financial')->post(stcUrl('store', $this->w->case), ['type' => 'opme'])->assertForbidden();

    // Clínica sem o recurso `has_surgery` (plano sem o módulo): 403, nada é gerado.
    $noModule = stcWorld(withModule: false);
    surgeryAs($noModule, 'admin')->postJson(stcUrl('store', $noModule->case), ['type' => 'inpatient'])->assertStatus(403);
    expect(TissGuide::query()->where('surgical_case_id', $noModule->case->id)->count())->toBe(0);
});

it('médico só opera nos casos dele; outra clínica nem enxerga o caso', function () {
    $other = createDoctorForEntity($this->w->entity);
    $user  = $other->entityUser->user;

    $this->actingAs($user)->withSession(panelSession($other->entityUser))
        ->post(stcUrl('store', $this->w->case), ['type' => 'inpatient'])
        ->assertForbidden();

    surgeryAs($this->w, 'doctor')->post(stcUrl('store', $this->w->case), ['type' => 'inpatient'])->assertRedirect();

    $w2 = surgeryWorld();
    surgeryAs($w2, 'admin')->post(stcUrl('store', $this->w->case), ['type' => 'inpatient'])->assertNotFound();
});

it('IDOR: guia de OUTRO caso ou de outra clínica é 404 em todas as ações', function () {
    stcRequested($this->w);
    $mine = app(SurgicalGuideService::class)->generate($this->w->case->refresh(), TissGuideType::InpatientRequest);

    $w2     = stcWorld();
    $theirs = app(SurgicalGuideService::class)->generate($w2->case, TissGuideType::InpatientRequest);

    $as = fn () => surgeryAs($this->w, 'admin');

    // Guia da OUTRA clínica, pela URL do meu caso.
    $as()->get(stcUrl('pdf', $this->w->case, $theirs))->assertNotFound();
    $as()->get(stcUrl('xml.download', $this->w->case, $theirs))->assertNotFound();
    $as()->put(stcUrl('update', $this->w->case, $theirs), ['observation' => 'x'])->assertNotFound();
    $as()->post(stcUrl('send', $this->w->case, $theirs))->assertNotFound();
    $as()->post(stcUrl('cancel', $this->w->case, $theirs), ['reason' => 'abc'])->assertNotFound();

    // Guia de outro caso da MESMA clínica, pela URL deste caso.
    $second = stcWorld();
    $second->entity->id; // outro mundo, outra clínica: já coberto acima
    $sameClinicCase = app(SurgicalCaseService::class)->create((string) $this->w->entity->id, [
        'patient_id'  => (string) $this->w->patient->id, 'surgeon_doctor_id' => (string) $this->w->doctor->id,
        'title'       => 'Outro caso', 'eye' => 'oe', 'regime' => 'day_hospital', 'surgery_facility_id' => (string) $this->w->facility->id,
        'covenant_id' => (string) $this->w->insurer->id, 'clinical_indication' => 'x',
        'procedures'  => [['description' => 'P', 'tuss_code' => '30306019', 'quantity' => 1]],
    ]);
    $foreign = app(SurgicalGuideService::class)->generate($sameClinicCase, TissGuideType::InpatientRequest);

    $as()->get(stcUrl('pdf', $this->w->case, $foreign))->assertNotFound();
    $as()->get(stcUrl('pdf', $this->w->case, $mine))->assertOk();
    expect(TissTransmission::query()->count())->toBe(0);
});

it('fluxo completo pela HTTP: completar, gerar XML, baixar XML/PDF e enviar', function () {
    stcRequested($this->w);
    $guide = app(SurgicalGuideService::class)->generate($this->w->case->refresh(), TissGuideType::InpatientRequest);

    // Sem a data sugerida o XML não sai: pré-validação barra (422 com as pendências).
    surgeryAs($this->w, 'secretary')->postJson(stcUrl('xml.generate', $this->w->case, $guide))
        ->assertStatus(422)
        ->assertJsonPath('context.issues.0.code', 'SUGGESTED_DATE_REQUIRED');

    surgeryAs($this->w, 'secretary')->put(stcUrl('update', $this->w->case, $guide), ['suggested_date' => '2026-11-03'])->assertRedirect();
    surgeryAs($this->w, 'secretary')->post(stcUrl('xml.generate', $this->w->case, $guide))->assertRedirect();

    $guide->refresh();
    expect($guide->status)->toBe(TissGuideStatus::XmlGenerated);

    $xml = surgeryAs($this->w, 'secretary')->get(stcUrl('xml.download', $this->w->case, $guide));
    $xml->assertOk();
    expect($xml->headers->get('content-type'))->toContain('application/xml')
        ->and($xml->streamedContent())->toContain('<solicitacaoInternacao>');

    $pdf = surgeryAs($this->w, 'secretary')->get(stcUrl('pdf', $this->w->case, $guide));
    $pdf->assertOk();
    expect($pdf->headers->get('content-type'))->toContain('application/pdf');

    surgeryAs($this->w, 'secretary')->post(stcUrl('send', $this->w->case, $guide))->assertRedirect()->assertSessionHas('success');

    expect($guide->refresh()->status)->toBe(TissGuideStatus::Sent)
        ->and(TissTransmission::query()->value('status'))->toBe(TissTransmissionStatus::Acknowledged);
});

it('envio sem resposta devolve 409 com a mensagem de dúvida e deixa a guia travada', function () {
    $guide             = stcReadyGuide($this->w);
    $this->fake->queue = [StcFakeTransport::noResponse()];

    surgeryAs($this->w, 'secretary')->postJson(stcUrl('send', $this->w->case, $guide))
        ->assertStatus(409)
        ->assertJsonPath('message', fn ($m) => str_contains($m, 'TRAVADA'));

    surgeryAs($this->w, 'secretary')->putJson(stcUrl('update', $this->w->case, $guide), ['observation' => 'tentando editar'])
        ->assertStatus(409);
    surgeryAs($this->w, 'secretary')->postJson(stcUrl('cancel', $this->w->case, $guide), ['reason' => 'desisti'])
        ->assertStatus(409);

    surgeryAs($this->w, 'secretary')->post(stcUrl('send', $this->w->case, $guide))->assertRedirect()->assertSessionHas('success');
    expect(TissTransmission::query()->where('guide_id', $guide->id)->count())->toBe(1);
});

it('valida o que a secretária completa: telefone, tabela, opção de fabricante e UUID do item', function () {
    stcRequested($this->w);
    $opme = app(SurgicalGuideService::class)->generate($this->w->case->refresh(), TissGuideType::OpmeAttachment);

    surgeryAs($this->w, 'secretary')->putJson(stcUrl('update', $this->w->case, $opme), [
        'suggested_date' => '03/11/2026',
        'items'          => [['id' => 'não-uuid', 'table_code' => '77', 'manufacturer_option' => '9', 'tuss_code' => 'código inválido!']],
    ])->assertStatus(422)->assertJsonValidationErrors(['suggested_date', 'items.0.id', 'items.0.table_code', 'items.0.manufacturer_option', 'items.0.tuss_code']);

    // Item que não é desta guia: recusado pelo service.
    surgeryAs($this->w, 'secretary')->putJson(stcUrl('update', $this->w->case, $opme), [
        'items' => [['id' => (string) Str::uuid(), 'tuss_code' => '70017031']],
    ])->assertStatus(422);
});

it('anexar o XML de resposta pela aba aplica a decisão; arquivo que não é resposta é recusado', function () {
    $guide             = stcReadyGuide($this->w);
    $this->fake->queue = [StcFakeTransport::accepted()];
    surgeryAs($this->w, 'secretary')->post(stcUrl('send', $this->w->case, $guide));

    $good = UploadedFile::fake()->createWithContent('resposta.xml', stcAuthXml($guide->guide_number_provider, '1'));
    surgeryAs($this->w, 'secretary')->post(stcUrl('response', $this->w->case, $guide), ['xml_file' => $good])->assertRedirect()->assertSessionHas('success');

    // A guia de internação só cobre os procedimentos: o anexo de OPME ainda espera resposta, então o
    // caso segue aguardando (e a guia do OPME continua podendo ser enviada).
    $case = $this->w->case->refresh();

    expect($guide->refresh()->status)->toBe(TissGuideStatus::Authorized)
        ->and($case->status)->toBe(SurgicalCaseStatus::AwaitingAuthorization)
        ->and($case->procedures->first()->authorized_quantity)->toBe(1)
        ->and($case->materials->first()->authorized_quantity)->toBeNull();

    $bad = UploadedFile::fake()->createWithContent('qualquer.xml', '<a><b/></a>');
    surgeryAs($this->w, 'secretary')->post(stcUrl('response', $this->w->case, $guide), ['xml_file' => $bad])->assertSessionHasErrors('xml_file');
});

it('a aba TISS aparece só para convênio real com internação ou OPME, e traz o estado da guia', function () {
    stcRequested($this->w);
    $guide = app(SurgicalGuideService::class)->generate($this->w->case->refresh(), TissGuideType::InpatientRequest);

    surgeryAs($this->w, 'secretary')->get(route('panel.surgery.show', $this->w->case))->assertInertia(fn ($page) => $page
        ->where('tabs', fn ($tabs) => collect($tabs)->pluck('key')->contains('tiss'))
        ->where('sections.tiss.case_awaiting', true)
        ->where('sections.tiss.guides.inpatient.id', (string) $guide->id)
        ->where('sections.tiss.guides.inpatient.delivery.state', 'none')
        ->where('sections.tiss.guides.inpatient.editable', true)
        ->where('sections.tiss.guides.opme', null)
        ->where('sections.tiss.available.opme', true));

    $private = stcWorld(['covenant_id' => null]);
    $private->patient->update(['covenant_id' => $private->particular->id]);
    $privateCase = app(SurgicalCaseService::class)->create((string) $private->entity->id, [
        'patient_id' => (string) $private->patient->id, 'surgeon_doctor_id' => (string) $private->doctor->id,
        'title'      => 'Particular', 'eye' => 'od', 'regime' => 'day_hospital', 'covenant_id' => (string) $private->particular->id,
        'procedures' => [['description' => 'X', 'quantity' => 1]],
    ]);

    surgeryAs($private, 'secretary')->get(route('panel.surgery.show', $privateCase))->assertInertia(fn ($page) => $page
        ->where('tabs', fn ($tabs) => ! collect($tabs)->pluck('key')->contains('tiss')));
});

it('baixar o XML e o PDF (carteira e diagnóstico do paciente) entra na trilha de acesso LGPD', function () {
    $guide = stcReadyGuide($this->w);

    surgeryAs($this->w, 'secretary')->get(stcUrl('xml.download', $this->w->case, $guide))->assertOk();
    surgeryAs($this->w, 'secretary')->get(stcUrl('pdf', $this->w->case, $guide))->assertOk();

    $logs = DataAccessLog::query()->where('resource_id', $guide->id)->get();

    expect($logs)->toHaveCount(2)
        ->and((string) $logs->first()->patient_id)->toBe((string) $this->w->patient->id)
        ->and((string) $logs->first()->entity_id)->toBe((string) $this->w->entity->id);
});

it('download de XML/PDF tem limite de taxa (30 por minuto): o 31º é 429', function () {
    $guide = stcReadyGuide($this->w);

    for ($i = 1; $i <= 30; $i++) {
        surgeryAs($this->w, 'secretary')->get(stcUrl('xml.download', $this->w->case, $guide))->assertOk();
    }

    surgeryAs($this->w, 'secretary')->get(stcUrl('xml.download', $this->w->case, $guide))->assertStatus(429);
});

it('o PDF da guia mostra data no idioma do documento (nada de d/m/Y fixo)', function () {
    $guide = stcReadyGuide($this->w);

    $render = function (string $locale) use ($guide): string {
        app()->setLocale($locale);

        return view('pdf.tiss_surgical_guide', [
            'guide'        => $guide, 'data' => SurgicalGuideData::fromGuide($guide->refresh()),
            'operatorName' => 'UNIMED', 'beneficiary' => 'PACIENTE', 'xmlHash' => null,
        ])->render();
    };

    expect($render('pt_BR'))->toContain('03/11/2026')
        ->and($render('en'))->toContain('11/03/2026')
        ->and($render('en'))->not->toContain('03/11/2026');
});
