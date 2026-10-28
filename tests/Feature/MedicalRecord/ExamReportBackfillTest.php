<?php

declare(strict_types=1);

use App\Enums\ClientRule;
use App\Models\{Covenant, Doctor, Entity, MedicalRecord, MedicalRecordDocumentation, Patient, People, ReportSetting, ReportSettingContent, User};
use App\Services\ReportSettingService;
use Database\Seeders\{ReportSettingContentSeeder, ReportSettingSeeder, ReportSettingVariableSeeder};
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * F4d — backfill idempotente + auto-populate enriquecido.
 *
 * Cobre:
 *   - GONIOSCOPIA agora resolve {{GONIOSCOPIA_OD/OE}} no PDF
 *   - {{CABECALHO_PACIENTE}} resolvido em todos os 14 templates do hub
 *   - syncAdoptedContentsWithGlobal cria contents novos sem afetar customizações
 *   - syncAdoptedContentsWithGlobal atualiza contents quando HTML muda
 *   - Backfill é idempotente (segunda execução = no-op)
 *   - Documentações já emitidas NÃO são alteradas pelo backfill
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
    $patientPerson = People::factory()->create([
        'full_name'  => 'MARIA DA SILVA',
        'birth_date' => '1980-05-10',
    ]);
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
        'patient_id'       => $this->patient->id,
        'doctor_id'        => $this->doctor->id,
        'main_complaint'   => 'Visão embaçada',
        'tonometer_right'  => 14,
        'tonometer_left'   => 15,
        'gonioscopy_right' => 'Câmara aberta grau IV, ângulo regular',
        'gonioscopy_left'  => 'Câmara aberta grau IV, ângulo regular',
    ]);

    $this->actingAs($this->user);
    session(['selected_entity_id' => $this->entity->id]);
});

it('GONIOSCOPIA resolve placeholders OD e OE no laudo emitido', function () {
    $response = $this->postJson(
        route('panel.patients.medicalrecords.quick-actions.issue', [
            $this->patient, $this->record, 'exam-report',
        ]),
        ['exam_type' => 'gonioscopia'],
    );

    $response->assertCreated();

    $doc = MedicalRecordDocumentation::where('medical_record_id', $this->record->id)->first();
    expect($doc->content)->toContain('Câmara aberta grau IV')
        ->and($doc->content)->not->toContain('{{GONIOSCOPIA_OD}}')
        ->and($doc->content)->not->toContain('{{GONIOSCOPIA_OE}}');
});

it('placeholder CABECALHO_PACIENTE é removido do html (PDF blade renderiza patient-block próprio)', function () {
    $response = $this->getJson(
        route('panel.patients.medicalrecords.exam-template', [
            $this->patient, $this->record, 'gonioscopia',
        ]),
    );

    $response->assertOk();
    // CABECALHO_PACIENTE agora resolve para vazio + strip de placeholders
    // pendentes no controller. Patient header é responsabilidade exclusiva
    // do pdf/documentation.blade.php (evita duplicação no PDF + ruído no
    // editor TinyMCE durante o preview).
    expect($response->json('html'))->not->toContain('{{CABECALHO_PACIENTE}}');
});

it('placeholder CABECALHO_PACIENTE removido em todos os templates do hub', function () {
    $hubExams = [
        'ecografia', 'microscopia_especular', 'paquimetria', 'retinografia',
        'angiofluoresceinografia', 'oct', 'schirmer', 'pentacam',
        'stress_curve', 'corneal_topography', 'computer_campimetry',
        'gonioscopia', 'retinal_mapping', 'ophthalmological_report',
        'declaracao_branco',
    ];

    foreach ($hubExams as $examType) {
        $response = $this->getJson(
            route('panel.patients.medicalrecords.exam-template', [
                $this->patient, $this->record, $examType,
            ]),
        );

        $response->assertOk();
        $html = $response->json('html');
        expect($html)->not->toContain('{{CABECALHO_PACIENTE}}');
    }
});

it('syncAdoptedContentsWithGlobal cria content novo quando aparece no global', function () {
    // Setup: cria entidade adotada manualmente
    $service = app(ReportSettingService::class);
    $service->adoptPublishedGlobalsForEntity((string) $this->entity->id);

    // Adiciona content novo no global
    $globalSetting = ReportSetting::whereNull('entity_id')
        ->where('title', 'LAUDO DE GONIOSCOPIA')
        ->first();

    ReportSettingContent::create([
        'report_setting_id' => $globalSetting->id,
        'type'              => 'report',
        'slug'              => 'novo_template_f4d',
        'label'             => 'Novo Template F4d',
        'content'           => '<p>Conteúdo novo</p>',
        'is_system'         => true,
        'sort_order'        => 99,
        'active'            => true,
    ]);

    // Verifica que cópia local NÃO tem o content ainda
    $localSetting = ReportSetting::where('entity_id', $this->entity->id)
        ->where('source_setting_id', $globalSetting->id)
        ->first();
    expect($localSetting->contents()->where('slug', 'novo_template_f4d')->exists())->toBeFalse();

    // Roda backfill
    $stats = $service->syncAdoptedContentsWithGlobal();

    expect($stats['contents_created'])->toBeGreaterThan(0);
    expect($localSetting->contents()->where('slug', 'novo_template_f4d')->exists())->toBeTrue();
});

it('syncAdoptedContentsWithGlobal é idempotente (segunda execução não modifica nada)', function () {
    $service = app(ReportSettingService::class);
    $service->adoptPublishedGlobalsForEntity((string) $this->entity->id);

    // Primeira execução
    $first = $service->syncAdoptedContentsWithGlobal();

    // Segunda execução imediata
    $second = $service->syncAdoptedContentsWithGlobal();

    expect($second['contents_created'])->toBe(0)
        ->and($second['contents_updated'])->toBe(0)
        ->and($second['settings_synced'])->toBe(0);
});

it('backfill atualiza HTML quando global muda mas mantém ID do content', function () {
    $service = app(ReportSettingService::class);
    $service->adoptPublishedGlobalsForEntity((string) $this->entity->id);

    $globalSetting = ReportSetting::whereNull('entity_id')
        ->where('title', 'LAUDO DE GONIOSCOPIA')
        ->first();
    $globalContent = $globalSetting->contents()->where('slug', 'padrao')->first();

    $localSetting = ReportSetting::where('entity_id', $this->entity->id)
        ->where('source_setting_id', $globalSetting->id)
        ->first();
    $localContent           = $localSetting->contents()->where('slug', 'padrao')->first();
    $originalLocalContentId = $localContent->id;

    // Modifica global
    $globalContent->update(['content' => '<p>Nova versão F4d</p>']);

    // Backfill
    $service->syncAdoptedContentsWithGlobal();

    // ID preservado, conteúdo atualizado
    $localContent->refresh();
    expect($localContent->id)->toBe($originalLocalContentId)
        ->and($localContent->content)->toBe('<p>Nova versão F4d</p>');
});

it('backfill NÃO altera documentações já emitidas (compliance)', function () {
    // Emite uma doc primeiro
    $response = $this->postJson(
        route('panel.patients.medicalrecords.quick-actions.issue', [
            $this->patient, $this->record, 'exam-report',
        ]),
        ['exam_type' => 'gonioscopia'],
    );
    $response->assertCreated();
    $doc                = MedicalRecordDocumentation::first();
    $originalDocContent = $doc->content;

    // Modifica template global
    $globalContent = ReportSettingContent::whereHas('reportSetting', fn ($q) => $q
        ->whereNull('entity_id')->where('title', 'LAUDO DE GONIOSCOPIA'))
        ->where('slug', 'padrao')
        ->first();
    $globalContent->update(['content' => '<p>Template totalmente alterado</p>']);

    // Backfill propaga
    app(ReportSettingService::class)->syncAdoptedContentsWithGlobal();

    // Documentação emitida fica intacta (CFM 2.227/2018)
    $doc->refresh();
    expect($doc->content)->toBe($originalDocContent);
});
