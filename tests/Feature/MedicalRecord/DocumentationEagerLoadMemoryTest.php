<?php

declare(strict_types=1);

use App\Enums\{ClientRule, DocumentationType};
use App\Models\{Covenant, Entity, MedicalRecord, MedicalRecordDocumentation, Patient, People, User};
use Illuminate\Support\Facades\DB;

/**
 * ajaxList()/show()/edit() (MedicalRecordsController) nunca podem carregar
 * metadata/content das documentações — um laudo com imagem embutida pode
 * ter ~27 MiB em base64 nesse campo (ReportImageBlockBuilder /
 * EyeImageReportController::store(), metadata['image_report']), e nenhum
 * dos 3 endpoints usa esse campo na serialização. Carregar a relação
 * inteira (ou todas as colunas dela) pra cada documentação de cada
 * prontuário da página multiplicava esse custo e podia exceder o
 * memory_limit configurado (achado via revisão adversarial Codex).
 */
beforeEach(function () {
    $this->entity     = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $this->doctorUser = User::factory()->create();
    $this->eu         = createEntityUser($this->entity, $this->doctorUser, ClientRule::Doctor->value);
    $this->doctor     = doctorRecordForEntityUser($this->eu);
    $this->patient    = Patient::create([
        'entity_id'   => $this->entity->id,
        'person_id'   => People::factory()->create()->id,
        'covenant_id' => Covenant::factory()->create(['active' => true])->id,
        'active'      => true,
    ]);
    $this->record = MedicalRecord::create([
        'entity_id' => $this->entity->id, 'patient_id' => $this->patient->id, 'doctor_id' => $this->doctor->id,
    ]);
    $this->doc = MedicalRecordDocumentation::create([
        'medical_record_id' => $this->record->id,
        'patient_id'        => $this->patient->id,
        'doctor_id'         => $this->doctor->id,
        'type'              => DocumentationType::Report->value,
        'title'             => 'Laudo com imagem embutida',
        'content'           => '<p>Achados.</p>',
        'metadata'          => ['image_report' => [
            'enabled' => true, 'columns' => 2,
            'images'  => [['data_uri' => 'data:image/jpeg;base64,' . str_repeat('A', 2000), 'caption' => null]],
        ]],
    ]);
});

function medRecAs(object $test): object
{
    return $test->actingAs($test->doctorUser)->withSession(panelSession($test->eu));
}

it('[GAP] ajaxList() usa withCount(), nunca carrega a documentação inteira só pra contar', function () {
    $log = [];
    DB::listen(function ($query) use (&$log) {
        $sql = strtolower($query->sql);

        if (str_contains($sql, 'medical_record_documentations')) {
            $log[] = $sql;
        }
    });

    medRecAs($this)->getJson(route('panel.patients.medicalrecords.ajaxlist', $this->patient))
        ->assertOk()
        ->assertJsonPath('data.0.documentations_count', 1);

    expect($log)->not->toBeEmpty();

    foreach ($log as $sql) {
        expect($sql)->not->toContain('metadata')->and($sql)->not->toContain('select *');
    }
});

it('[GAP] show() carrega documentations sem metadata/content — só as colunas de fato usadas na serialização', function () {
    $log = [];
    DB::listen(function ($query) use (&$log) {
        $sql = strtolower($query->sql);

        if (str_contains($sql, 'medical_record_documentations')) {
            $log[] = $sql;
        }
    });

    medRecAs($this)->getJson(route('panel.patients.medicalrecords.show', [$this->patient, $this->record]))
        ->assertOk()
        ->assertJsonPath('documentations.0.title', 'Laudo com imagem embutida');

    expect($log)->not->toBeEmpty();

    foreach ($log as $sql) {
        expect($sql)->not->toContain('metadata')->and($sql)->not->toContain('select *');
    }
});

it('[GAP] edit() carrega documentations sem metadata/content, inclusive com reportSettingContent/aiRun ainda aninhados', function () {
    $log = [];
    DB::listen(function ($query) use (&$log) {
        $sql = strtolower($query->sql);

        if (str_contains($sql, 'medical_record_documentations')) {
            $log[] = $sql;
        }
    });

    medRecAs($this)->get(route('panel.patients.medicalrecords.edit', [$this->patient, $this->record]))
        ->assertOk();

    expect($log)->not->toBeEmpty();

    foreach ($log as $sql) {
        expect($sql)->not->toContain('metadata')->and($sql)->not->toContain('select *');
    }
});
