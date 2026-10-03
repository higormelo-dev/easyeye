<?php

use App\Domains\AI\Services\AiMedicalContextBuilder;
use App\Models\{Entity, MedicalRecord, Patient, People};
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

test('[LGPD] build não envia nome nem iniciais do paciente — só idade e sexo', function () {
    $patient = makePatientWithPerson([
        'full_name' => 'Maria das Dores Cardoso Oliveira',
    ]);

    $context = (new AiMedicalContextBuilder())->build($patient, null);

    expect($context)->toBe(['age_years' => 58, 'gender' => 1]);
    expect($context)->not->toHaveKey('patient_initials');
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

test('[LGPD] build não envia os códigos internos de paciente e prontuário ao provedor', function () {
    $patient = makePatientWithPerson([], ['code' => 'PAC-XYZ-42']);
    $record  = MedicalRecord::create([
        'doctor_id'      => createDoctorForEntity(Entity::find($patient->entity_id))->id,
        'patient_id'     => $patient->id,
        'entity_id'      => $patient->entity_id,
        'code'           => 'PRT-001',
        'main_complaint' => 'Baixa visual',
    ]);

    $context = (new AiMedicalContextBuilder())->build($patient, $record);

    // Repetidos a cada chamada, permitiriam ao provedor ligar as consultas do mesmo paciente.
    expect($context)->not->toHaveKey('patient_code')
        ->and($context)->not->toHaveKey('medical_record_code')
        ->and(json_encode($context))->not->toContain('PAC-XYZ-42')->not->toContain('PRT-001');
});

test('protectedNames devolve nome completo e apelido para a redação', function () {
    $patient = makePatientWithPerson(['full_name' => 'João da Silva Santos', 'nickname' => 'Joãozinho']);

    expect((new AiMedicalContextBuilder())->protectedNames($patient))->toBe(['JOÃO DA SILVA SANTOS', 'JOÃOZINHO'])
        ->and((new AiMedicalContextBuilder())->protectedNames(null))->toBe([]);
});

test('build sem patient retorna apenas contexto do medical record', function () {
    $patient = makePatientWithPerson();
    $doctor  = createDoctorForEntity(Entity::find($patient->entity_id));
    $record  = MedicalRecord::create([
        'doctor_id'      => $doctor->id,
        'patient_id'     => $patient->id,
        'entity_id'      => $patient->entity_id,
        'code'           => 'PRT-001',
        'main_complaint' => 'Visão embaçada bilateral há 3 meses.',
        'diabetic'       => true,
        'hypertensive'   => false,
    ]);

    $context = (new AiMedicalContextBuilder())->build(null, $record);

    expect($context)->not->toHaveKey('medical_record_code');
    expect($context['main_complaint'])->toBe('Visão embaçada bilateral há 3 meses.');
    expect($context['comorbidities'])->toContain('diabetes_mellitus');
    expect($context['comorbidities'])->not->toContain('hipertensao_arterial');
    expect($context)->not->toHaveKey('patient_initials');
});

test('build trunca campos longos para evitar payload gigante', function () {
    $patient  = makePatientWithPerson();
    $longText = str_repeat('Detalhe clínico longo. ', 100);
    $doctor   = createDoctorForEntity(Entity::find($patient->entity_id));
    $record   = MedicalRecord::create([
        'doctor_id'      => $doctor->id,
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

    expect($context)->toHaveKey('age_years');
    expect($context)->not->toHaveKey('medical_record_code');
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
        'doctor_id'    => $doctor->id,
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

test('toda chave que o build() envia tem categoria na auditoria do envio (demografia ou prontuário)', function () {
    $patient = makePatientWithPerson();
    $doctor  = createDoctorForEntity(Entity::find($patient->entity_id));
    $record  = MedicalRecord::create([
        'doctor_id'           => $doctor->id, 'patient_id' => $patient->id, 'entity_id' => $patient->entity_id, 'code' => 'PRT-KEYS',
        'main_complaint'      => 'a', 'hda' => 'b', 'others_history' => 'c', 'medications_in_use' => 'd', 'ocular_surgical_history' => 'e',
        'biomicroscopy_right' => 'f', 'fundoscopy_left' => 'g', 'tonometer_right' => '14',
    ]);

    $keys = array_keys((new AiMedicalContextBuilder())->build($patient, $record));

    expect(array_diff($keys, [...AiMedicalContextBuilder::DEMOGRAPHIC_KEYS, ...AiMedicalContextBuilder::CLINICAL_KEYS]))->toBe([])
        ->and($keys)->toContain('age_years')->toContain('main_complaint');
});
