<?php

declare(strict_types=1);

use App\Enums\ClientRule;
use App\Models\{Covenant, Doctor, Entity, MedicalRecord, MedicalRecordDocumentation, Patient, People, ReportSettingContent, User};
use Database\Seeders\{ReportSettingContentSeeder, ReportSettingSeeder, ReportSettingVariableSeeder};
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * F4e — Pentacam multi-template via subtype.
 *
 * Cobre fluxo end-to-end:
 *   - GET exam-template?subtype=X retorna template específico
 *   - POST quick-actions/exam-report com subtype cria documentação correta
 *   - Subtype inválido cai em fallback `padrao`
 *   - Exame sem subtipos ignora payload.subtype sem erro
 *   - Response do template inclui array de `subtypes` para UI
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
        'patient_id'      => $this->patient->id,
        'doctor_id'       => $this->doctor->id,
        'main_complaint'  => 'Astigmatismo irregular',
        'tonometer_right' => 16,
        'tonometer_left'  => 17,
    ]);

    $this->actingAs($this->user);
    session(['selected_entity_id' => $this->entity->id]);
});

it('GET exam-template Pentacam sem subtype retorna template padrao', function () {
    $response = $this->getJson(
        route('panel.patients.medicalrecords.exam-template', [
            $this->patient, $this->record, 'pentacam',
        ]),
    );

    $response->assertOk();
    expect($response->json('subtype'))->toBeNull()
        ->and($response->json('subtypes'))->toHaveCount(5)
        ->and($response->json('html'))->toContain('PENTACAM — Análise Tomográfica da Córnea');
});

it('GET exam-template Pentacam com subtype=ceratocone_avancado retorna template específico', function () {
    $response = $this->getJson(
        route('panel.patients.medicalrecords.exam-template', [
            $this->patient, $this->record, 'pentacam',
        ]) . '?subtype=ceratocone_avancado',
    );

    $response->assertOk();
    expect($response->json('subtype'))->toBe('ceratocone_avancado')
        ->and($response->json('html'))->toContain('Belin ABCD Keratoconus Staging: A4B4C2')
        ->and($response->json('title'))->toBe('Pentacam — Ceratocone Avançado');
});

it('GET exam-template Pentacam com subtype=laudo_refrativo retorna template refrativo', function () {
    $response = $this->getJson(
        route('panel.patients.medicalrecords.exam-template', [
            $this->patient, $this->record, 'pentacam',
        ]) . '?subtype=laudo_refrativo',
    );

    $response->assertOk();
    expect($response->json('subtype'))->toBe('laudo_refrativo')
        ->and($response->json('html'))->toContain('LAUDO REFRATIVO');
});

it('GET exam-template Pentacam subtype inválido cai em padrao silenciosamente', function () {
    $response = $this->getJson(
        route('panel.patients.medicalrecords.exam-template', [
            $this->patient, $this->record, 'pentacam',
        ]) . '?subtype=invalido_xyz',
    );

    $response->assertOk();
    // Subtype original retornado p/ debug client-side
    expect($response->json('subtype'))->toBe('invalido_xyz')
        ->and($response->json('html'))->toContain('PENTACAM — Análise Tomográfica da Córnea');
});

it('exam-template para exame sem subtipos retorna subtypes=[]', function () {
    $response = $this->getJson(
        route('panel.patients.medicalrecords.exam-template', [
            $this->patient, $this->record, 'gonioscopia',
        ]),
    );

    $response->assertOk();
    expect($response->json('subtypes'))->toBe([]);
});

it('POST exam-report Pentacam com subtype=ceratocone_oval emite documentação correta', function () {
    $response = $this->postJson(
        route('panel.patients.medicalrecords.quick-actions.issue', [
            $this->patient, $this->record, 'exam-report',
        ]),
        [
            'exam_type' => 'pentacam',
            'subtype'   => 'ceratocone_oval',
        ],
    );

    $response->assertCreated();

    $doc = MedicalRecordDocumentation::where('medical_record_id', $this->record->id)->first();
    expect($doc->title)->toBe('Pentacam — Ceratocone Oval');

    $content = ReportSettingContent::find($doc->report_setting_content_id);
    expect($content->slug)->toBe('ceratocone_oval');
});

it('POST exam-report sem subtype usa template padrao do Pentacam', function () {
    $response = $this->postJson(
        route('panel.patients.medicalrecords.quick-actions.issue', [
            $this->patient, $this->record, 'exam-report',
        ]),
        ['exam_type' => 'pentacam'],
    );

    $response->assertCreated();
    $doc     = MedicalRecordDocumentation::first();
    $content = ReportSettingContent::find($doc->report_setting_content_id);

    expect($content->slug)->toBe('padrao')
        ->and($doc->title)->toBe('Pentacam');
});

it('POST exam-report ignora subtype em exame sem variantes', function () {
    $response = $this->postJson(
        route('panel.patients.medicalrecords.quick-actions.issue', [
            $this->patient, $this->record, 'exam-report',
        ]),
        [
            'exam_type' => 'gonioscopia',
            'subtype'   => 'qualquer_xyz', // ignorado
        ],
    );

    $response->assertCreated();
    $doc     = MedicalRecordDocumentation::first();
    $content = ReportSettingContent::find($doc->report_setting_content_id);

    expect($content->slug)->toBe('padrao');
});

it('POST exam-report subtype inválido em Pentacam cai em padrao', function () {
    $response = $this->postJson(
        route('panel.patients.medicalrecords.quick-actions.issue', [
            $this->patient, $this->record, 'exam-report',
        ]),
        [
            'exam_type' => 'pentacam',
            'subtype'   => 'inexistente_yz',
        ],
    );

    $response->assertCreated();
    $doc     = MedicalRecordDocumentation::first();
    $content = ReportSettingContent::find($doc->report_setting_content_id);

    expect($content->slug)->toBe('padrao');
});
