<?php

declare(strict_types=1);

use App\Enums\ClientRule;
use App\Http\Requests\StoreMedicalRecordRequest;
use App\Models\{Covenant, Entity, EntityUser, MedicalRecord, MedicalRecordDraft, Patient, People, User};
use App\Services\Lgpd\PatientDataExporter;
use App\Services\MedicalRecords\MedicalRecordDraftService;
use Carbon\Carbon;
use Inertia\Testing\AssertableInertia;

/*
 * Fase 2 / A3 — uso de lente de contato na anamnese (por consulta, gravado no
 * prontuário), pré-preenchido com o da última consulta; entra no rascunho
 * (FIELDS), no histórico da consulta, no PDF e na exportação LGPD.
 */
beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-07 09:00:00'));

    $this->entity = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $this->docEu  = createEntityUser($this->entity, User::factory()->create(), ClientRule::Doctor->value);
    $this->doctor = doctorRecordForEntityUser($this->docEu);

    $this->patient = cs2lPatient($this->entity);
});

function cs2lPatient(Entity $entity): Patient
{
    return Patient::create([
        'entity_id'   => $entity->id,
        'person_id'   => People::factory()->create()->id,
        'covenant_id' => Covenant::factory()->create()->id,
        'active'      => true,
    ]);
}

function cs2lAs(object $test, EntityUser $eu): object
{
    return $test->actingAs($eu->user)->withSession(panelSession($eu));
}

/** @return array<string, mixed> */
function cs2lUse(array $overrides = []): array
{
    return array_merge([
        'uses'        => true, 'type' => 'soft', 'wear' => 'daily_wear', 'hours_per_day' => 12,
        'replacement' => 'monthly', 'sleeps_with' => true, 'complaints' => 'Ardor no fim do dia',
    ], $overrides);
}

it('grava o uso de LC da consulta (só as chaves conhecidas)', function () {
    cs2lAs($this, $this->docEu)
        ->post(route('panel.patients.medicalrecords.store', $this->patient), [
            'main_complaint' => 'Ardor', 'contact_lens_use' => cs2lUse(['hack' => 'x']),
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(MedicalRecord::query()->sole()->contact_lens_use)->toBe([
        'uses'        => true, 'type' => 'soft', 'wear' => 'daily_wear', 'hours_per_day' => 12,
        'replacement' => 'monthly', 'sleeps_with' => true, 'complaints' => 'Ardor no fim do dia',
    ]);
});

it('"não usa" descarta os detalhes; nada preenchido vira null; valores fora da lista são recusados', function () {
    cs2lAs($this, $this->docEu)
        ->post(route('panel.patients.medicalrecords.store', $this->patient), ['main_complaint' => 'x', 'contact_lens_use' => cs2lUse(['uses' => false])])
        ->assertRedirect();
    expect(MedicalRecord::query()->latest('created_at')->first()->contact_lens_use)->toBe(['uses' => false]);

    $record = MedicalRecord::query()->sole();
    cs2lAs($this, $this->docEu)
        ->put(route('panel.patients.medicalrecords.update', [$this->patient, $record]), [
            'main_complaint' => 'x', 'contact_lens_use' => ['uses' => null, 'type' => '', 'complaints' => ''],
        ])
        ->assertRedirect();
    expect($record->fresh()->contact_lens_use)->toBeNull();

    cs2lAs($this, $this->docEu)
        ->post(route('panel.patients.medicalrecords.store', $this->patient), [
            'main_complaint' => 'x', 'contact_lens_use' => cs2lUse(['type' => 'hard', 'replacement' => 'weekly', 'hours_per_day' => 30]),
        ])
        ->assertSessionHasErrors(['contact_lens_use.type', 'contact_lens_use.replacement', 'contact_lens_use.hours_per_day']);
});

it('novo atendimento é pré-preenchido com o uso de LC da ÚLTIMA consulta do paciente (não de outro paciente)', function () {
    $this->travelTo(Carbon::parse('2026-09-01 10:00'));
    MedicalRecord::create(['entity_id' => $this->entity->id, 'patient_id' => $this->patient->id, 'doctor_id' => $this->doctor->id, 'main_complaint' => 'a', 'contact_lens_use' => cs2lUse(['type' => 'rgp'])]);
    $this->travelTo(Carbon::parse('2026-09-20 10:00'));
    MedicalRecord::create(['entity_id' => $this->entity->id, 'patient_id' => $this->patient->id, 'doctor_id' => $this->doctor->id, 'main_complaint' => 'b', 'contact_lens_use' => cs2lUse(['type' => 'scleral'])]);
    // Consulta mais nova SEM registro de LC não apaga o pré-preenchimento.
    $this->travelTo(Carbon::parse('2026-10-01 10:00'));
    MedicalRecord::create(['entity_id' => $this->entity->id, 'patient_id' => $this->patient->id, 'doctor_id' => $this->doctor->id, 'main_complaint' => 'c']);
    $other = cs2lPatient($this->entity);
    MedicalRecord::create(['entity_id' => $this->entity->id, 'patient_id' => $other->id, 'doctor_id' => $this->doctor->id, 'main_complaint' => 'd', 'contact_lens_use' => cs2lUse(['type' => 'ortho_k'])]);
    $this->travelTo(Carbon::parse('2026-10-07 09:00'));

    cs2lAs($this, $this->docEu)->get(route('panel.patients.medicalrecords.create', $this->patient))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('contactLensUsePrevious.value.type', 'scleral')
            ->where('contactLensUsePrevious.date', '20/09/2026'));

    cs2lAs($this, $this->docEu)->get(route('panel.patients.medicalrecords.create', cs2lPatient($this->entity)))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('contactLensUsePrevious', null));
});

it('entra no rascunho (FIELDS amarrado ao request) e o rascunho guarda o bloco', function () {
    expect(MedicalRecordDraftService::FIELDS)->toContain('contact_lens_use')
        ->and(array_keys((new StoreMedicalRecordRequest())->rules()))->toContain('contact_lens_use');

    cs2lAs($this, $this->docEu)
        ->putJson(route('panel.patients.medicalrecord-draft.save', $this->patient), ['payload' => ['main_complaint' => 'x', 'contact_lens_use' => cs2lUse()]])
        ->assertOk();

    expect(MedicalRecordDraft::query()->sole()->payload['contact_lens_use']['type'])->toBe('soft');
});

it('histórico da consulta, PDF e exportação LGPD mostram o uso de LC', function () {
    cs2lAs($this, $this->docEu)
        ->post(route('panel.patients.medicalrecords.store', $this->patient), ['main_complaint' => 'x', 'contact_lens_use' => cs2lUse()])
        ->assertRedirect();
    $record = MedicalRecord::query()->sole();

    cs2lAs($this, $this->docEu)->getJson(route('panel.patients.medicalrecords.show', [$this->patient, $record]))
        ->assertOk()
        ->assertJsonPath('contact_lens_use_rows.0.value', 'Sim')
        ->assertJsonPath('contact_lens_use_rows.1.value', 'Gelatinosa')
        ->assertJsonPath('contact_lens_use_rows.3.value', '12 h/dia');

    $html = view('pdf.medical_record', ['record' => $record->fresh(), 'setting' => null])->render();
    expect($html)->toContain('Uso de lente de contato')->toContain('Gelatinosa')->toContain('Descarte: Mensal')->toContain('Dorme com a lente: Sim');

    $export = app(PatientDataExporter::class)->export($this->patient->fresh());
    expect($export['medical_records'][0]['contact_lens_use']['type'])->toBe('soft')
        ->and($export['medical_records'][0]['contact_lens_use_summary'])->toContain('Gelatinosa');
});
