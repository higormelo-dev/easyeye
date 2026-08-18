<?php

declare(strict_types=1);

use App\Enums\ClientRule;
use App\Models\{Covenant, Doctor, Entity, MedicalRecord, MedicalRecordDocumentation, Patient, People, User};
use Database\Seeders\{ReportSettingContentSeeder, ReportSettingSeeder, ReportSettingVariableSeeder};
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * F7 — Atestado de Comparecimento + Atestado Médico via quick-action.
 *
 * Cobre payload validation, append de observações ao final do template,
 * day_extension via NumberFormatter, escopo multi-tenant.
 */
beforeEach(function () {
    $this->seed([
        ReportSettingSeeder::class,
        ReportSettingContentSeeder::class,
        ReportSettingVariableSeeder::class,
    ]);

    $this->entity  = Entity::factory()->create(['is_client' => true]);
    $this->user    = User::factory()->create();
    $covenant      = Covenant::factory()->create();
    $patientPerson = People::factory()->create();
    $this->patient = Patient::create([
        'entity_id'   => $this->entity->id,
        'person_id'   => $patientPerson->id,
        'covenant_id' => $covenant->id,
        'active'      => true,
    ]);

    $entityUser   = createEntityUser($this->entity, $this->user, ClientRule::Doctor->value);
    $doctorPerson = People::factory()->create();
    $this->doctor = Doctor::create([
        'entity_user_id' => $entityUser->id,
        'person_id'      => $doctorPerson->id,
        'record'         => '12345',
        'color'          => '#FF0000',
        'partner'        => false,
        'active'         => true,
    ]);

    $this->record = MedicalRecord::create([
        'patient_id'     => $this->patient->id,
        'doctor_id'      => $this->doctor->id,
        'main_complaint' => 'Conjuntivite',
    ]);

    $this->actingAs($this->user);
    session(['selected_entity_id' => $this->entity->id]);
});

// ── Atestado de Comparecimento ───────────────────────────────────────────

it('emite atestado de comparecimento sem observações (happy path)', function () {
    $r = $this->postJson(
        route('panel.patients.medicalrecords.quick-actions.issue', [
            $this->patient, $this->record, 'attendance-certificate',
        ]),
        [],
    );

    $r->assertCreated();

    $doc = MedicalRecordDocumentation::first();
    expect($doc->title)->toBe('Atestado de Comparecimento')
        ->and($doc->content)->toContain('Atesto para os devidos fins')
        ->and($doc->content)->not->toContain('pmr-observations');
});

it('anexa observações ao final do atestado de comparecimento', function () {
    $r = $this->postJson(
        route('panel.patients.medicalrecords.quick-actions.issue', [
            $this->patient, $this->record, 'attendance-certificate',
        ]),
        ['content' => "Paciente acompanhado pela esposa.\nReferência: HJG."],
    );

    $r->assertCreated();
    $doc = MedicalRecordDocumentation::first();
    // Bloco de observações usa <hr> + inline styles (não mais a classe
    // pmr-observations) — workaround do bug de border-top + position:fixed
    // do wkhtmltopdf 0.12.6, ver MedicalRecordQuickActionService::appendObservations().
    expect($doc->content)->toContain('Observações:')
        ->and($doc->content)->toContain('<hr style=')
        ->and($doc->content)->toContain('Paciente acompanhado pela esposa.')
        ->and($doc->content)->toContain('<br />'); // nl2br aplicado
});

it('rejeita content > 5000 chars', function () {
    $r = $this->postJson(
        route('panel.patients.medicalrecords.quick-actions.issue', [
            $this->patient, $this->record, 'attendance-certificate',
        ]),
        ['content' => str_repeat('a', 5001)],
    );

    $r->assertStatus(422)->assertJsonValidationErrors(['content']);
});

// ── Atestado Médico (afastamento) ────────────────────────────────────────

it('emite atestado médico com dias e dia por extenso resolvido', function () {
    $r = $this->postJson(
        route('panel.patients.medicalrecords.quick-actions.issue', [
            $this->patient, $this->record, 'medical-certificate',
        ]),
        ['days' => 3],
    );

    $r->assertCreated();
    $doc = MedicalRecordDocumentation::first();
    expect($doc->title)->toBe('Atestado Médico')
        ->and($doc->content)->toContain('3 (')
        ->and($doc->content)->toContain('três')
        ->and($doc->content)->toContain('dia(s)');
});

it('honra date custom no payload', function () {
    $r = $this->postJson(
        route('panel.patients.medicalrecords.quick-actions.issue', [
            $this->patient, $this->record, 'medical-certificate',
        ]),
        ['days' => 5, 'date' => '20/05/2026'],
    );

    $r->assertCreated();
    expect(MedicalRecordDocumentation::first()->content)->toContain('20/05/2026');
});

it('anexa observações ao atestado médico', function () {
    $r = $this->postJson(
        route('panel.patients.medicalrecords.quick-actions.issue', [
            $this->patient, $this->record, 'medical-certificate',
        ]),
        [
            'days'    => 7,
            'content' => 'Necessária reavaliação após o período.',
        ],
    );

    $r->assertCreated();
    expect(MedicalRecordDocumentation::first()->content)
        ->toContain('Necessária reavaliação após o período.');
});

it('rejeita atestado médico sem days', function () {
    $r = $this->postJson(
        route('panel.patients.medicalrecords.quick-actions.issue', [
            $this->patient, $this->record, 'medical-certificate',
        ]),
        [],
    );

    $r->assertStatus(422)->assertJsonValidationErrors(['days']);
});

it('rejeita days fora do range 1..365', function () {
    $r = $this->postJson(
        route('panel.patients.medicalrecords.quick-actions.issue', [
            $this->patient, $this->record, 'medical-certificate',
        ]),
        ['days' => 0],
    );

    $r->assertStatus(422);

    $r2 = $this->postJson(
        route('panel.patients.medicalrecords.quick-actions.issue', [
            $this->patient, $this->record, 'medical-certificate',
        ]),
        ['days' => 366],
    );

    $r2->assertStatus(422);
});

it('rejeita date em formato inválido no atestado médico', function () {
    $r = $this->postJson(
        route('panel.patients.medicalrecords.quick-actions.issue', [
            $this->patient, $this->record, 'medical-certificate',
        ]),
        ['days' => 3, 'date' => '2026-05-20'],
    );

    $r->assertStatus(422)->assertJsonValidationErrors(['date']);
});

// ── Day extension preview endpoint ───────────────────────────────────────

it('day-extension-preview retorna spelt + display formatado', function () {
    $r = $this->postJson(route('panel.medical-records.day-extension-preview'), ['days' => 7]);

    $r->assertOk()
        ->assertJsonStructure(['days', 'spelt', 'display']);
    expect($r->json('display'))->toContain('7 (')
        ->and($r->json('display'))->toEndWith(' dias');
});

it('day-extension-preview rejeita days inválido', function () {
    $r = $this->postJson(route('panel.medical-records.day-extension-preview'), ['days' => 0]);
    $r->assertStatus(422);
});

it('nega secretary nos atestados (gate IssueReport)', function () {
    $secretary = User::factory()->create();
    createEntityUser($this->entity, $secretary, ClientRule::Secretary->value);
    $this->actingAs($secretary);

    $r = $this->postJson(
        route('panel.patients.medicalrecords.quick-actions.issue', [
            $this->patient, $this->record, 'medical-certificate',
        ]),
        ['days' => 3],
    );

    $r->assertForbidden();
});
