<?php

declare(strict_types=1);

use App\Enums\ClientRule;
use App\Models\{Covenant, Doctor, Entity, MedicalRecord, Patient, People, User, VisualAcuityType};
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Painel lateral "Consultas anteriores" — resumo clínico serializado em
 * buildFormProps → previousRecords[].summary (AV, refração, PIO, diagnósticos,
 * conduta). Caso de uso: médico compara dados da última consulta SEM sair do
 * prontuário atual.
 */
beforeEach(function () {
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

    $this->actingAs($this->user);
    session(['selected_entity_id' => $this->entity->id]);
});

it('serializa o resumo clínico da consulta anterior (AV, refração, PIO, diagnóstico, conduta)', function () {
    $av2020 = VisualAcuityType::create(['scale' => 2, 'name' => '20/20', 'active' => true]);
    $av2025 = VisualAcuityType::create(['scale' => 3, 'name' => '20/25', 'active' => true]);

    MedicalRecord::create([
        'patient_id'                                => $this->patient->id,
        'doctor_id'                                 => $this->doctor->id,
        'main_complaint'                            => 'Baixa acuidade para longe',
        'visual_acuity_without_correction_right_id' => $av2020->id,
        'visual_acuity_without_correction_left_id'  => $av2025->id,
        'dynamic_spherical_right'                   => '-1,50',
        'dynamic_cylindrical_right'                 => '-0,50',
        'dynamic_axis_right'                        => '180º',
        'dynamic_spherical_left'                    => '-1,25',
        'tonometer_right'                           => 14,
        'tonometer_left'                            => 15,
        'diagnosis_cids'                            => [['code' => 'H52.1', 'description' => 'Miopia']],
        'clinical_conduct'                          => 'Prescrição de óculos e retorno em 1 ano.',
    ]);

    $response = $this->get(route('panel.patients.medicalrecords.create', $this->patient));
    $response->assertOk();

    $previous = $response->viewData('page')['props']['previousRecords'];

    expect($previous)->toHaveCount(1);

    $summary = $previous[0]['summary'];

    expect($summary['av_sc'])->toBe('OD 20/20 | OE 20/25')
        ->and($summary['refraction_od'])->toBe('-1,50 / -0,50 × 180º')
        ->and($summary['refraction_oe'])->toBe('-1,25')
        ->and($summary['pio'])->toBe('OD 14 | OE 15 mmHg')
        ->and($summary['diagnoses'])->toBe(['H52.1 – Miopia'])
        ->and($summary['conduct'])->toBe('Prescrição de óculos e retorno em 1 ano.');
});

it('omite linhas sem dado (null) — a UI esconde a linha inteira', function () {
    MedicalRecord::create([
        'patient_id'     => $this->patient->id,
        'doctor_id'      => $this->doctor->id,
        'main_complaint' => 'Consulta de rotina',
    ]);

    $response = $this->get(route('panel.patients.medicalrecords.create', $this->patient));
    $summary  = $response->viewData('page')['props']['previousRecords'][0]['summary'];

    expect($summary['av_sc'])->toBeNull()
        ->and($summary['av_cc'])->toBeNull()
        ->and($summary['refraction_od'])->toBeNull()
        ->and($summary['refraction_oe'])->toBeNull()
        ->and($summary['addition'])->toBeNull()
        ->and($summary['pio'])->toBeNull()
        ->and($summary['diagnoses'])->toBe([])
        ->and($summary['conduct'])->toBeNull();
});

it('na edição, o prontuário ATUAL não aparece na lista de anteriores', function () {
    $current = MedicalRecord::create([
        'patient_id'     => $this->patient->id,
        'doctor_id'      => $this->doctor->id,
        'main_complaint' => 'Consulta atual',
    ]);
    MedicalRecord::create([
        'patient_id'     => $this->patient->id,
        'doctor_id'      => $this->doctor->id,
        'main_complaint' => 'Consulta antiga',
    ]);

    $response = $this->get(route('panel.patients.medicalrecords.edit', [$this->patient, $current]));
    $response->assertOk();

    $previous = $response->viewData('page')['props']['previousRecords'];

    expect($previous)->toHaveCount(1)
        ->and($previous[0]['main_complaint'])->toBe('Consulta antiga');
});
