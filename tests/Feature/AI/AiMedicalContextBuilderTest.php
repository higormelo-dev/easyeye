<?php

use App\Domains\AI\Services\AiMedicalContextBuilder;
use App\Models\Entity;
use App\Models\MedicalRecord;
use App\Models\Patient;
use App\Models\People;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makePatientWithPerson(array $personAttrs = [], array $patientAttrs = []): Patient
{
    $entity = Entity::factory()->create();
    $person = People::factory()->create(array_merge([
        'full_name'  => 'João da Silva Santos',
        'gender'     => 1, // 1 = masculino (schema usa int)
        'birth_date' => now()->subYears(58),
    ], $personAttrs));

    return Patient::factory()->create(array_merge([
        'entity_id' => $entity->id,
        'person_id' => $person->id,
        'code'      => 'PAC-001',
    ], $patientAttrs));
}

test('build retorna iniciais do nome do paciente (não nome completo)', function () {
    $patient = makePatientWithPerson([
        'full_name' => 'Maria das Dores Cardoso Oliveira',
    ]);

    $context = (new AiMedicalContextBuilder())->build($patient, null);

    expect($context['patient_initials'])->toBe('M. D. D. C. O.');
    expect($context)->not->toHaveKey('full_name');
    expect($context)->not->toHaveKey('cpf');
    expect($context)->not->toHaveKey('email');
    expect($context)->not->toHaveKey('cellphone');
});

test('build inclui idade calculada a partir de birth_date', function () {
    $patient = makePatientWithPerson([
        'birth_date' => now()->subYears(45)->subMonths(2),
    ]);

    $context = (new AiMedicalContextBuilder())->build($patient, null);

    expect($context['age_years'])->toBe(45);
});

test('build inclui code do paciente como identificador opaco', function () {
    $patient = makePatientWithPerson(patientAttrs: ['code' => 'PAC-XYZ-42']);

    $context = (new AiMedicalContextBuilder())->build($patient, null);

    expect($context['patient_code'])->toBe('PAC-XYZ-42');
});

test('build sem patient retorna apenas contexto do medical record', function () {
    $patient = makePatientWithPerson();
    $doctor  = createDoctorForEntity(Entity::find($patient->entity_id));
    $record  = MedicalRecord::create([
        'doctor_id'  => $doctor->id,
        'patient_id'     => $patient->id,
        'entity_id'      => $patient->entity_id,
        'code'           => 'PRT-001',
        'main_complaint' => 'Visão embaçada bilateral há 3 meses.',
        'diabetic'       => true,
        'hypertensive'   => false,
    ]);

    $context = (new AiMedicalContextBuilder())->build(null, $record);

    expect($context['medical_record_code'])->toBe('PRT-001');
    expect($context['main_complaint'])->toBe('Visão embaçada bilateral há 3 meses.');
    expect($context['comorbidities'])->toContain('diabetes_mellitus');
    expect($context['comorbidities'])->not->toContain('hipertensao_arterial');
    expect($context)->not->toHaveKey('patient_initials');
});

test('build trunca campos longos para evitar payload gigante', function () {
    $patient = makePatientWithPerson();
    $longText = str_repeat('Detalhe clínico longo. ', 100);
    $doctor  = createDoctorForEntity(Entity::find($patient->entity_id));
    $record  = MedicalRecord::create([
        'doctor_id'  => $doctor->id,
        'patient_id'     => $patient->id,
        'entity_id'      => $patient->entity_id,
        'code'           => 'PRT-LONG',
        'main_complaint' => $longText,
    ]);

    $context = (new AiMedicalContextBuilder())->build(null, $record);

    expect(mb_strlen($context['main_complaint']))->toBeLessThanOrEqual(600);
    expect(str_ends_with($context['main_complaint'], '...'))->toBeTrue();
});

test('build remove chaves vazias do contexto final', function () {
    $patient = makePatientWithPerson(['full_name' => 'Ana']);
    $doctor  = createDoctorForEntity(Entity::find($patient->entity_id));
    $record  = MedicalRecord::create([
        'doctor_id'  => $doctor->id,
        'patient_id' => $patient->id,
        'entity_id'  => $patient->entity_id,
        'code'       => 'PRT-EMPTY',
        // Sem queixa, sem comorbidades, sem tonometria — devem ser filtrados.
    ]);

    $context = (new AiMedicalContextBuilder())->build($patient, $record);

    expect($context)->toHaveKey('patient_initials');
    expect($context)->toHaveKey('medical_record_code');
    expect($context)->not->toHaveKey('main_complaint');
    expect($context)->not->toHaveKey('comorbidities');
});

test('build retorna vazio quando patient e record são null', function () {
    expect((new AiMedicalContextBuilder())->build(null, null))->toBe([]);
});

test('build agrega comorbidades quando flags estão presentes', function () {
    $patient = makePatientWithPerson();
    $doctor  = createDoctorForEntity(Entity::find($patient->entity_id));
    $record  = MedicalRecord::create([
        'doctor_id'  => $doctor->id,
        'patient_id'   => $patient->id,
        'entity_id'    => $patient->entity_id,
        'code'         => 'PRT-COMOR',
        'diabetic'     => true,
        'hypertensive' => true,
        'glaucomatous' => true,
    ]);

    $context = (new AiMedicalContextBuilder())->build(null, $record);

    expect($context['comorbidities'])->toContain('diabetes_mellitus');
    expect($context['comorbidities'])->toContain('hipertensao_arterial');
    expect($context['comorbidities'])->toContain('glaucoma');
});
