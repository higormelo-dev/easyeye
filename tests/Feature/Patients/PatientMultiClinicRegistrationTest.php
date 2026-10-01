<?php

declare(strict_types=1);

/**
 * O mesmo paciente (CPF) pode ser cadastrado em várias clínicas — cada clínica
 * com o SEU cadastro de pessoa (People). Nenhuma clínica lê, reaproveita ou
 * reescreve o cadastro de outra: CPF, nome e e-mail são únicos só dentro da
 * clínica, e a resposta nunca revela que o CPF existe em outro lugar.
 */

use App\Enums\{ClientRule, FeatureKey, SubscriptionStatus};
use App\Models\{Covenant, Entity, Patient, People, Plan, PlanFeature, Subscription, User};
use App\Models\Doctor;

function pmcEntity(): Entity
{
    $entity                = Entity::factory()->make(['is_client' => true, 'active' => true]);
    $entity->skipAutoTrial = true;
    $entity->save();

    $plan = Plan::factory()->create(['active' => true]);
    PlanFeature::create(['plan_id' => $plan->id, 'feature' => FeatureKey::MaxPatients->value, 'value' => '0']);

    Subscription::factory()->create([
        'entity_id' => $entity->id, 'plan_id' => $plan->id, 'status' => SubscriptionStatus::Active,
        'starts_at' => now()->subDay(), 'ends_at' => now()->addMonth(),
    ]);

    Covenant::factory()->create(['entity_id' => $entity->id, 'name' => 'Particular']);

    return $entity;
}

function pmcPayload(Covenant $covenant, array $overrides = []): array
{
    return array_merge([
        'covenant_id'       => $covenant->id,
        'name'              => 'MARIA SOUZA',
        'birth_date'        => '1990-01-01',
        'gender'            => 1,
        'marital_status'    => 1,
        'email'             => 'maria@example.com',
        'national_registry' => '529.982.247-25',
        'cellphone'         => '11911112222',
        'whatsapp'          => false,
    ], $overrides);
}

function pmcPatient(Entity $entity): ?Patient
{
    return Patient::withoutGlobalScopes()->withTrashed()->where('entity_id', $entity->id)->first();
}

beforeEach(function (): void {
    $this->clinicA = pmcEntity();
    $this->clinicB = pmcEntity();
    $this->euA     = createEntityUser($this->clinicA, User::factory()->create(), ClientRule::Admin->value);
    $this->euB     = createEntityUser($this->clinicB, User::factory()->create(), ClientRule::Admin->value);
    $this->covA    = Covenant::factory()->create(['entity_id' => $this->clinicA->id]);
    $this->covB    = Covenant::factory()->create(['entity_id' => $this->clinicB->id]);

    $this->storeAs = fn ($entityUser, array $payload) => $this->actingAs($entityUser->user)
        ->withSession(panelSession($entityUser))
        ->postJson(route('panel.patients.store'), $payload);

    // Paciente de B cadastrado pelo fluxo real.
    ($this->storeAs)($this->euB, pmcPayload($this->covB, [
        'name' => 'MARIA DA CLINICA B', 'email' => 'maria.b@example.com',
    ]))->assertSuccessful();
    $this->patientB = pmcPatient($this->clinicB);
});

it('mesmo CPF de paciente ATIVO em outra clínica: A cria o próprio cadastro, B fica intocado', function (): void {
    ($this->storeAs)($this->euA, pmcPayload($this->covA, ['name' => 'MARIA DA CLINICA A', 'email' => 'maria.a@example.com']))
        ->assertSuccessful();

    $patientA = pmcPatient($this->clinicA);
    $personB  = People::find($this->patientB->person_id);

    expect($patientA)->not->toBeNull()
        ->and($patientA->person_id)->not->toBe($this->patientB->person_id)
        ->and($patientA->person->national_registry)->toBe('52998224725')
        ->and($patientA->person->full_name)->toBe('MARIA DA CLINICA A')
        ->and($personB->full_name)->toBe('MARIA DA CLINICA B')
        ->and($personB->email)->toBe('maria.b@example.com');
});

it('nome e e-mail iguais aos do cadastro de B não bloqueiam A (unicidade por clínica)', function (): void {
    ($this->storeAs)($this->euA, pmcPayload($this->covA, ['name' => 'MARIA DA CLINICA B', 'email' => 'maria.b@example.com']))
        ->assertSuccessful();

    expect(pmcPatient($this->clinicA)->person_id)->not->toBe($this->patientB->person_id);
});

it('dentro da MESMA clínica, CPF, nome e e-mail continuam únicos (422)', function (): void {
    ($this->storeAs)($this->euB, pmcPayload($this->covB, ['name' => 'OUTRO NOME', 'email' => 'outro@example.com']))
        ->assertStatus(422)->assertJsonValidationErrors('national_registry');

    ($this->storeAs)($this->euB, pmcPayload($this->covB, ['national_registry' => '11144477735', 'name' => 'MARIA DA CLINICA B', 'email' => 'x@example.com']))
        ->assertStatus(422)->assertJsonValidationErrors('name');

    ($this->storeAs)($this->euB, pmcPayload($this->covB, ['national_registry' => '11144477735', 'name' => 'OUTRA', 'email' => 'maria.b@example.com']))
        ->assertStatus(422)->assertJsonValidationErrors('email');

    expect(Patient::withoutGlobalScopes()->where('entity_id', $this->clinicB->id)->count())->toBe(1);
});

it('paciente EXCLUÍDO em B: A cria cadastro novo e o histórico de B continua com os dados de B', function (): void {
    $this->actingAs($this->euB->user)->withSession(panelSession($this->euB))
        ->deleteJson(route('panel.patients.destroy', $this->patientB->id))->assertSuccessful();

    ($this->storeAs)($this->euA, pmcPayload($this->covA, ['name' => 'MARIA DA CLINICA A', 'email' => 'maria.a@example.com']))
        ->assertSuccessful();

    $personB = People::withTrashed()->find($this->patientB->person_id);

    expect(pmcPatient($this->clinicA)->person_id)->not->toBe($this->patientB->person_id)
        ->and($personB->full_name)->toBe('MARIA DA CLINICA B')
        ->and($personB->email)->toBe('maria.b@example.com');
});

it('re-cadastrar paciente excluído na PRÓPRIA clínica restaura o mesmo paciente e o mesmo cadastro', function (): void {
    $this->actingAs($this->euB->user)->withSession(panelSession($this->euB))
        ->deleteJson(route('panel.patients.destroy', $this->patientB->id))->assertSuccessful();

    ($this->storeAs)($this->euB, pmcPayload($this->covB, ['name' => 'MARIA DA CLINICA B', 'email' => 'maria.b@example.com']))
        ->assertSuccessful();

    $patients = Patient::withoutGlobalScopes()->withTrashed()->where('entity_id', $this->clinicB->id)->get();

    expect($patients)->toHaveCount(1)
        ->and($patients->first()->id)->toBe($this->patientB->id)
        ->and($patients->first()->trashed())->toBeFalse();
});

it('editar paciente de A para o CPF/e-mail de B é permitido e não altera B', function (): void {
    ($this->storeAs)($this->euA, pmcPayload($this->covA, [
        'national_registry' => '11144477735', 'name' => 'JOAO', 'email' => 'joao@example.com',
    ]))->assertSuccessful();
    $patientA = pmcPatient($this->clinicA);

    $this->actingAs($this->euA->user)->withSession(panelSession($this->euA))
        ->putJson(route('panel.patients.update', $patientA->id), pmcPayload($this->covA, [
            'name' => 'JOAO', 'email' => 'maria.b@example.com', 'active' => true,
        ]))->assertSuccessful();

    expect($patientA->person->fresh()->national_registry)->toBe('52998224725')
        ->and(People::find($this->patientB->person_id)->full_name)->toBe('MARIA DA CLINICA B');
});

it('a mensagem de erro nunca revela que o CPF existe em outra clínica', function (): void {
    // Antes: "cpf já registrado" também para CPF de OUTRA clínica — oráculo
    // para descobrir quem é paciente de quem. Agora A simplesmente cadastra.
    $response = ($this->storeAs)($this->euA, pmcPayload($this->covA, ['name' => 'MARIA DA CLINICA A', 'email' => 'maria.a@example.com']));

    $response->assertSuccessful();
    expect($response->json('errors'))->toBeNull();
});

it('CPF de MÉDICO da própria clínica: paciente ganha cadastro próprio e os dados do médico não são reescritos', function (): void {
    $doctorPerson = People::factory()->create(['national_registry' => '11144477735', 'full_name' => 'DR JOAO', 'email' => 'dr.joao@example.com']);
    Doctor::query()->create([
        'entity_user_id' => createEntityUser($this->clinicA, User::factory()->create(), ClientRule::Doctor->value)->id,
        'person_id'      => $doctorPerson->id,
        'record'         => 'CRM12345',
        'active'         => true,
    ]);

    ($this->storeAs)($this->euA, pmcPayload($this->covA, [
        'national_registry' => '11144477735', 'name' => 'JOAO PACIENTE', 'email' => 'joao.paciente@example.com',
    ]))->assertSuccessful();

    expect(pmcPatient($this->clinicA)->person_id)->not->toBe($doctorPerson->id)
        ->and($doctorPerson->fresh()->only(['full_name', 'email']))->toBe(['full_name' => 'DR JOAO', 'email' => 'dr.joao@example.com']);
});
