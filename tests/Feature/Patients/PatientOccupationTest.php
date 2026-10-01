<?php

declare(strict_types=1);

/**
 * Profissão do paciente (aba Pessoal) — mesmo cadastro via Pacientes e via
 * Agenda (ambos usam panel.patients.store/update). Opcional, gravada no
 * cadastro de pessoa (People) da clínica, em maiúsculas como os demais nomes.
 */

use App\Enums\{ClientRule, FeatureKey, SubscriptionStatus};
use App\Models\{Covenant, Entity, Patient, Plan, PlanFeature, Subscription, User};

function pocEntity(): Entity
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

beforeEach(function (): void {
    $this->clinic   = pocEntity();
    $this->eu       = createEntityUser($this->clinic, User::factory()->create(), ClientRule::Admin->value);
    $this->covenant = Covenant::factory()->create(['entity_id' => $this->clinic->id]);

    $this->payload = fn (array $overrides = []) => array_merge([
        'covenant_id'       => $this->covenant->id, 'name' => 'Maria Souza', 'birth_date' => '1990-01-01',
        'gender'            => 1, 'marital_status' => 1, 'email' => 'maria@example.com',
        'national_registry' => '52998224725', 'cellphone' => '11911112222', 'whatsapp' => false,
    ], $overrides);

    $this->as = fn () => $this->actingAs($this->eu->user)->withSession(panelSession($this->eu));
});

it('cadastro grava a profissão (maiúsculas, como os demais nomes)', function (): void {
    ($this->as)()->postJson(route('panel.patients.store'), ($this->payload)(['occupation' => 'professora']))
        ->assertSuccessful();

    expect(Patient::withoutGlobalScopes()->where('entity_id', $this->clinic->id)->sole()->person->occupation)->toBe('PROFESSORA');
});

it('profissão é opcional', function (): void {
    ($this->as)()->postJson(route('panel.patients.store'), ($this->payload)())->assertSuccessful();

    expect(Patient::withoutGlobalScopes()->where('entity_id', $this->clinic->id)->sole()->person->occupation)->toBeNull();
});

it('edição altera a profissão e os dados de edição/detalhe a devolvem', function (): void {
    ($this->as)()->postJson(route('panel.patients.store'), ($this->payload)(['occupation' => 'professora']));
    $patient = Patient::withoutGlobalScopes()->where('entity_id', $this->clinic->id)->sole();

    ($this->as)()->putJson(route('panel.patients.update', $patient->id), ($this->payload)(['occupation' => 'Motorista', 'active' => true]))
        ->assertSuccessful();

    expect($patient->person->fresh()->occupation)->toBe('MOTORISTA');

    ($this->as)()->getJson(route('panel.patients.editData', $patient->id))
        ->assertOk()->assertJsonPath('data.occupation', 'MOTORISTA');
    ($this->as)()->getJson(route('panel.patients.show', $patient->id))
        ->assertOk()->assertJsonPath('data.occupation', 'MOTORISTA');
});

it('profissão longa demais é recusada (422)', function (): void {
    ($this->as)()->postJson(route('panel.patients.store'), ($this->payload)(['occupation' => str_repeat('a', 121)]))
        ->assertStatus(422)->assertJsonValidationErrors('occupation');
});
