<?php

declare(strict_types=1);

use App\Enums\{ClientRule, DocumentationType};
use App\Models\{Covenant, Entity, MedicalRecord, MedicalRecordDocumentation, Patient, PatientAccount, PatientDocumentShare, People, User};
use Illuminate\Support\Facades\DB;

/**
 * ClinicController::show() ("Meus documentos" do Portal do Paciente) lista
 * TODOS os documentos já liberados pro titular, sem paginação. Um laudo
 * compartilhado com imagem embutida pode ter ~27 MiB em base64 no metadata
 * (ReportImageBlockBuilder/EyeImageReportController::store()), nunca usado
 * em serializeShare() — mesma classe do achado original em
 * MedicalRecordsController, aqui sem nenhum teto de página (achado via
 * verificação adversarial do /codex:adversarial-review).
 */
it('[GAP] "Meus documentos" nunca seleciona metadata/content do laudo compartilhado — só as colunas que resolveTitle() usa', function () {
    $entity  = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $person  = People::factory()->create();
    $patient = Patient::create([
        'entity_id'   => $entity->id,
        'person_id'   => $person->id,
        'covenant_id' => Covenant::factory()->create(['active' => true])->id,
        'active'      => true,
    ]);
    $doctor = doctorRecordForEntityUser(createEntityUser($entity, User::factory()->create(), ClientRule::Doctor->value));
    $record = MedicalRecord::create(['entity_id' => $entity->id, 'patient_id' => $patient->id, 'doctor_id' => $doctor->id]);
    $doc    = MedicalRecordDocumentation::create([
        'medical_record_id' => $record->id,
        'patient_id'        => $patient->id,
        'doctor_id'         => $doctor->id,
        'type'              => DocumentationType::Report->value,
        'title'             => 'Laudo compartilhado',
        'content'           => '<p>Achados.</p>',
        'metadata'          => ['image_report' => [
            'enabled' => true, 'columns' => 2,
            'images'  => [['data_uri' => 'data:image/jpeg;base64,' . str_repeat('A', 2000), 'caption' => null]],
        ]],
    ]);
    PatientDocumentShare::create([
        'entity_id'      => $entity->id,
        'patient_id'     => $patient->id,
        'shareable_type' => MedicalRecordDocumentation::class,
        'shareable_id'   => $doc->id,
        'granted_at'     => now(),
    ]);

    $account = PatientAccount::factory()->create(['person_id' => $person->id]);
    loginAsPatient($account);

    $log = [];
    DB::listen(function ($query) use (&$log) {
        $sql = strtolower($query->sql);

        if (str_contains($sql, 'medical_record_documentations')) {
            $log[] = $sql;
        }
    });

    $response = $this->get(route('patient-portal.clinics.show', $patient), inertiaHeaders());

    $response->assertOk();
    $response->assertJsonPath('props.documents.0.title', 'Laudo compartilhado');

    expect($log)->not->toBeEmpty();

    foreach ($log as $sql) {
        expect($sql)->not->toContain('metadata')->and($sql)->not->toContain('select *');
    }
});
