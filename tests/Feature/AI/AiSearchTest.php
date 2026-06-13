<?php

use App\Enums\{ClientRule, FeatureKey, SubscriptionStatus};
use App\Models\{Doctor, Entity, MedicalRecord, Patient, People, Plan, PlanFeature, Subscription, User};

beforeEach(function () {
    $this->entity = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $this->plan   = Plan::factory()->create(['active' => true]);

    PlanFeature::factory()->enabled(FeatureKey::HasAiExamAssistant)->for($this->plan)->create();
    PlanFeature::factory()->limit(FeatureKey::AiMonthlyCredits, 1000)->for($this->plan)->create();

    Subscription::factory()->create([
        'entity_id' => $this->entity->id,
        'plan_id'   => $this->plan->id,
        'status'    => SubscriptionStatus::Active,
        'starts_at' => now()->subDay(),
        'ends_at'   => now()->addMonth(),
    ]);

    $this->doctor      = User::factory()->create();
    $this->doctorUser  = createEntityUser($this->entity, $this->doctor, ClientRule::Doctor->value);
    $this->doctorModel = Doctor::query()->create([
        'entity_user_id' => $this->doctorUser->id,
        'person_id'      => People::factory()->create()->id,
        'active'         => true,
    ]);
});

it('busca pacientes por nome (LIKE) com até 20 resultados', function () {
    // O model People normaliza full_name para uppercase; assert reflete isso.
    foreach (['Maria Silva', 'Maria Santos', 'João da Silva', 'Pedro Costa'] as $name) {
        Patient::factory()->create([
            'entity_id' => $this->entity->id,
            'person_id' => People::factory()->create(['full_name' => $name])->id,
        ]);
    }

    $data = $this->actingAs($this->doctor)
        ->withSession(panelSession($this->doctorUser))
        ->getJson(route('panel.ai-runs.search.patients', ['q' => 'Maria']))
        ->assertOk()
        ->json('data');

    $labels = collect($data)->pluck('label')->all();
    expect($labels)->toContain('MARIA SILVA')
        ->and($labels)->toContain('MARIA SANTOS')
        ->and($labels)->not->toContain('JOÃO DA SILVA');
});

it('busca pacientes por code (LIKE)', function () {
    Patient::factory()->create(['entity_id' => $this->entity->id, 'code' => 'PAC-001']);
    Patient::factory()->create(['entity_id' => $this->entity->id, 'code' => 'PAC-002']);
    Patient::factory()->create(['entity_id' => $this->entity->id, 'code' => 'XYZ-999']);

    $data = $this->actingAs($this->doctor)
        ->withSession(panelSession($this->doctorUser))
        ->getJson(route('panel.ai-runs.search.patients', ['q' => 'PAC']))
        ->assertOk()
        ->json('data');

    $subLabels = collect($data)->pluck('sub_label')->all();
    expect($subLabels)->toContain('PAC-001')
        ->and($subLabels)->toContain('PAC-002');
});

it('sem query retorna top 20 pacientes ordenados por nome', function () {
    foreach (range(1, 25) as $i) {
        Patient::factory()->create([
            'entity_id' => $this->entity->id,
            'person_id' => People::factory()->create(['full_name' => "Paciente {$i}"])->id,
        ]);
    }

    $data = $this->actingAs($this->doctor)
        ->withSession(panelSession($this->doctorUser))
        ->getJson(route('panel.ai-runs.search.patients'))
        ->assertOk()
        ->json('data');

    expect(count($data))->toBe(20);
});

it('busca pacientes filtra por entity (cross-tenant)', function () {
    Patient::factory()->create([
        'entity_id' => $this->entity->id,
        'person_id' => People::factory()->create(['full_name' => 'Maria Local'])->id,
    ]);

    $other = Entity::factory()->create(['is_client' => true, 'active' => true]);
    Patient::factory()->create([
        'entity_id' => $other->id,
        'person_id' => People::factory()->create(['full_name' => 'Maria de Outra'])->id,
    ]);

    $data = $this->actingAs($this->doctor)
        ->withSession(panelSession($this->doctorUser))
        ->getJson(route('panel.ai-runs.search.patients', ['q' => 'Maria']))
        ->assertOk()
        ->json('data');

    $labels = collect($data)->pluck('label')->all();
    expect($labels)->toContain('MARIA LOCAL')
        ->and($labels)->not->toContain('MARIA DE OUTRA');
});

it('busca medical records filtra por patient_id quando passado', function () {
    $p1 = Patient::factory()->create(['entity_id' => $this->entity->id]);
    $p2 = Patient::factory()->create(['entity_id' => $this->entity->id]);

    MedicalRecord::query()->create([
        'entity_id'  => $this->entity->id,
        'patient_id' => $p1->id,
        'doctor_id'  => $this->doctorModel->id,
    ]);
    MedicalRecord::query()->create([
        'entity_id'  => $this->entity->id,
        'patient_id' => $p2->id,
        'doctor_id'  => $this->doctorModel->id,
    ]);

    $data = $this->actingAs($this->doctor)
        ->withSession(panelSession($this->doctorUser))
        ->getJson(route('panel.ai-runs.search.medical-records', ['patient_id' => $p1->id]))
        ->assertOk()
        ->json('data');

    expect(count($data))->toBe(1);
});
