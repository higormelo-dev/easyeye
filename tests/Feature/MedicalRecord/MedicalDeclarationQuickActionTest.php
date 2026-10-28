<?php

declare(strict_types=1);

use App\Enums\{ClientRule, DocumentationType};
use App\Models\{Covenant, Doctor, Entity, MedicalRecord, MedicalRecordDocumentation, Patient, People, ReportSettingContent, User};
use Barryvdh\Snappy\Facades\SnappyPdf;
use Database\Seeders\{ReportSettingContentSeeder, ReportSettingSeeder, ReportSettingVariableSeeder};

/*
 * "Declaração médica" — o servidor já emitia (quick action
 * `medical-declaration`, modelo `declaracao_medica` do RELATÓRIO
 * OFTALMOLÓGICO), mas nenhum botão chamava. Agora a barra do prontuário tem
 * o item, inclusive antes do 1º save (post_save_action).
 */
beforeEach(function () {
    $this->seed([ReportSettingSeeder::class, ReportSettingContentSeeder::class, ReportSettingVariableSeeder::class]);

    $this->entity = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $this->user   = User::factory()->create();
    $entityUser   = createEntityUser($this->entity, $this->user, ClientRule::Doctor->value);

    $this->doctor = Doctor::create([
        'entity_user_id' => $entityUser->id,
        'person_id'      => People::factory()->create()->id,
        'record'         => '54321',
        'active'         => true,
    ]);

    $this->patient = Patient::create([
        'entity_id'   => $this->entity->id,
        'person_id'   => People::factory()->create()->id,
        'covenant_id' => Covenant::factory()->create(['active' => true])->id,
        'active'      => true,
    ]);

    $this->record = MedicalRecord::create([
        'entity_id'      => $this->entity->id,
        'patient_id'     => $this->patient->id,
        'doctor_id'      => $this->doctor->id,
        'main_complaint' => 'Avaliação',
    ]);

    $this->actingAs($this->user)->withSession(panelSession($entityUser));
});

it('o modelo global declaracao_medica existe e está ativo nos seeders', function () {
    $content = ReportSettingContent::query()
        ->where('slug', 'declaracao_medica')
        ->where('active', true)
        ->whereHas('reportSetting', fn ($q) => $q->where('title', 'RELATÓRIO OFTALMOLÓGICO')->whereNull('entity_id'))
        ->first();

    expect($content)->not->toBeNull()
        ->and($content->content)->toContain('{{CONTEUDO_LIVRE}}');
});

it('emite a declaração médica (documentação + PDF)', function () {
    SnappyPdf::fake();

    $response = $this->postJson(
        route('panel.patients.medicalrecords.quick-actions.issue', [$this->patient, $this->record, 'medical-declaration']),
        ['content' => ''],
    )->assertCreated()
        ->assertJsonPath('title', 'Declaração Médica');

    $doc = MedicalRecordDocumentation::query()->sole();

    expect($doc->type)->toBe(DocumentationType::Report)
        ->and($doc->doctor_id)->toBe($this->doctor->id)
        ->and($doc->content)->not->toContain('{{');

    $this->get($response->json('pdf_url'))->assertOk();

    SnappyPdf::assertViewIs('pdf.documentation');
});

it('aceita a declaração como ação pendente do 1º save (post_save_action)', function () {
    $this->post(route('panel.patients.medicalrecords.store', $this->patient), [
        'doctor_id'        => $this->doctor->id,
        'main_complaint'   => 'Avaliação',
        'post_save_action' => 'medical_declaration',
    ])->assertSessionHasNoErrors()
        ->assertSessionHas('post_save_action', 'medical_declaration');
});
