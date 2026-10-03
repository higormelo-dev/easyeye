<?php

declare(strict_types=1);

/**
 * Plano do convênio no cadastro do paciente (aba Clínico, em Pacientes e na
 * Agenda — ambos usam panel.patients.store/update): validação (convênio
 * certo, plano disponível, isolamento entre clínicas), gravação, leitura
 * (detalhes, edição, prontuário), exportação LGPD e guia TISS.
 */

use App\Enums\{ClientRule, CovenantSource, FeatureKey, SubscriptionStatus};
use App\Models\{Covenant, CovenantPlan, Entity, Patient, Plan, PlanFeature, Subscription, User};
use App\Services\Financial\BillingService;
use App\Services\Lgpd\PatientDataExporter;

function pcpEntity(): Entity
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

    return $entity;
}

function pcpPlan(Covenant $covenant, array $attributes = []): CovenantPlan
{
    return CovenantPlan::withoutGlobalScopes()->forceCreate([
        'entity_id'   => null,
        'covenant_id' => $covenant->id,
        'name'        => 'AMIL ONE S6500',
        'ans_code'    => '471234567',
        'ans_plan_id' => random_int(1, 9_999_999),
        'contracting' => 'Coletivo empresarial',
        'source'      => CovenantSource::Ans->value,
        'ans_status'  => 'active',
        'active'      => true,
        ...$attributes,
    ]);
}

beforeEach(function (): void {
    $this->clinic = pcpEntity();
    $this->eu     = createEntityUser($this->clinic, User::factory()->create(), ClientRule::Admin->value);
    $this->amil   = Covenant::factory()->create(['name' => 'AMIL', 'ans_registry' => '326305', 'source' => CovenantSource::Ans]);
    $this->plan   = pcpPlan($this->amil);

    $this->payload = fn (array $overrides = []) => array_merge([
        'covenant_id' => $this->amil->id, 'covenant_plan_id' => $this->plan->id, 'card_number' => '0012345',
        'name'        => 'Maria Souza', 'birth_date' => '1990-01-01', 'gender' => 1, 'marital_status' => 1,
        'email'       => 'maria@example.com', 'national_registry' => '52998224725', 'cellphone' => '11911112222',
        'whatsapp'    => false,
    ], $overrides);

    $this->as      = fn () => $this->actingAs($this->eu->user)->withSession(panelSession($this->eu));
    $this->patient = fn () => Patient::withoutGlobalScopes()->where('entity_id', $this->clinic->id)->sole();
});

it('cadastro grava o plano; edição e detalhes o devolvem com nome, registro e características', function (): void {
    ($this->as)()->postJson(route('panel.patients.store'), ($this->payload)())->assertSuccessful();
    $patient = ($this->patient)();

    expect($patient->covenant_plan_id)->toBe($this->plan->id);

    ($this->as)()->getJson(route('panel.patients.editData', $patient->id))
        ->assertOk()
        ->assertJsonPath('data.covenant_plan_id', $this->plan->id)
        ->assertJsonPath('data.covenant_plan.label', 'AMIL ONE S6500 (Reg. ANS 471234567)')
        ->assertJsonPath('data.covenant_plan.contracting', 'Coletivo empresarial');

    ($this->as)()->getJson(route('panel.patients.show', $patient->id))
        ->assertOk()
        ->assertJsonPath('data.covenant_plan.label', 'AMIL ONE S6500 (Reg. ANS 471234567)')
        ->assertJsonPath('data.covenant_plan.unavailable', false);
});

it('plano é opcional', function (): void {
    ($this->as)()->postJson(route('panel.patients.store'), ($this->payload)(['covenant_plan_id' => null]))->assertSuccessful();

    expect(($this->patient)()->covenant_plan_id)->toBeNull();
});

it('[VALIDAÇÃO] plano de outro convênio, inexistente ou indisponível é recusado', function (): void {
    $bradesco = Covenant::factory()->create(['name' => 'BRADESCO', 'ans_registry' => '005711', 'source' => CovenantSource::Ans]);
    $other    = pcpPlan($bradesco, ['name' => 'BRADESCO TOP']);
    $closed   = pcpPlan($this->amil, ['name' => 'AMIL ANTIGO', 'active' => false, 'ans_status' => 'cancelled']);

    ($this->as)()->postJson(route('panel.patients.store'), ($this->payload)(['covenant_plan_id' => $other->id]))
        ->assertJsonValidationErrors(['covenant_plan_id' => __('covenant_plans.wrong_covenant')]);
    ($this->as)()->postJson(route('panel.patients.store'), ($this->payload)(['covenant_plan_id' => $closed->id]))
        ->assertJsonValidationErrors(['covenant_plan_id' => __('covenant_plans.unavailable')]);
    ($this->as)()->postJson(route('panel.patients.store'), ($this->payload)(['covenant_plan_id' => '01a10021-f756-711a-abba-d8acecb8edfe']))
        ->assertJsonValidationErrors(['covenant_plan_id' => __('covenant_plans.invalid')]);
    ($this->as)()->postJson(route('panel.patients.store'), ($this->payload)(['covenant_plan_id' => 'nao-e-uuid']))
        ->assertJsonValidationErrors('covenant_plan_id');

    expect(Patient::withoutGlobalScopes()->where('entity_id', $this->clinic->id)->exists())->toBeFalse();
});

it('[TENANT] plano próprio de OUTRA clínica não é aceito; o da própria clínica é', function (): void {
    $other   = pcpEntity();
    $foreign = CovenantPlan::withoutGlobalScopes()->forceCreate([
        'entity_id' => $other->id, 'covenant_id' => $this->amil->id, 'name' => 'SÓ DA OUTRA', 'active' => true,
    ]);
    $own = CovenantPlan::withoutGlobalScopes()->forceCreate([
        'entity_id' => $this->clinic->id, 'covenant_id' => $this->amil->id, 'name' => 'DA CLÍNICA', 'active' => true,
    ]);

    ($this->as)()->postJson(route('panel.patients.store'), ($this->payload)(['covenant_plan_id' => $foreign->id]))
        ->assertJsonValidationErrors(['covenant_plan_id' => __('covenant_plans.invalid')]);

    ($this->as)()->postJson(route('panel.patients.store'), ($this->payload)(['covenant_plan_id' => $own->id]))
        ->assertSuccessful();
    expect(($this->patient)()->covenant_plan_id)->toBe($own->id);
});

it('plano que deixou de estar disponível continua no cadastro e não trava a edição', function (): void {
    ($this->as)()->postJson(route('panel.patients.store'), ($this->payload)());
    $patient = ($this->patient)();

    $this->plan->forceFill(['active' => false, 'ans_status' => 'cancelled'])->save();

    ($this->as)()->putJson(route('panel.patients.update', $patient->id), ($this->payload)(['nickname' => 'Mari', 'active' => true]))
        ->assertSuccessful();

    expect($patient->fresh()->covenant_plan_id)->toBe($this->plan->id);
    ($this->as)()->getJson(route('panel.patients.show', $patient->id))
        ->assertJsonPath('data.covenant_plan.unavailable', true)
        ->assertJsonPath('data.covenant_plan.status_label', __('covenant_plans.status_cancelled'));
});

it('troca de convênio sem plano limpa o plano; mesmo convênio sem a chave do plano mantém', function (): void {
    ($this->as)()->postJson(route('panel.patients.store'), ($this->payload)());
    $patient = ($this->patient)();
    $payload = ($this->payload)(['active' => true]);

    unset($payload['covenant_plan_id']);
    ($this->as)()->putJson(route('panel.patients.update', $patient->id), $payload)->assertSuccessful();
    expect($patient->fresh()->covenant_plan_id)->toBe($this->plan->id);

    $other = Covenant::factory()->create(['entity_id' => $this->clinic->id, 'name' => 'CONVÊNIO LOCAL']);
    ($this->as)()->putJson(route('panel.patients.update', $patient->id), [...$payload, 'covenant_id' => $other->id])->assertSuccessful();
    expect($patient->fresh()->covenant_plan_id)->toBeNull();
});

it('Particular não guarda plano nem carteirinha', function (): void {
    $particular = Covenant::withoutGlobalScopes()->whereNull('entity_id')->where('name', 'PARTICULAR')->sole();
    $forced     = pcpPlan($particular, ['name' => 'NÃO DEVERIA EXISTIR']);

    ($this->as)()->postJson(route('panel.patients.store'), ($this->payload)([
        'covenant_id' => $particular->id, 'covenant_plan_id' => $forced->id,
    ]))->assertSuccessful();

    expect(($this->patient)()->covenant_plan_id)->toBeNull()
        ->and(($this->patient)()->card_number)->toBeNull();
});

it('prontuário mostra o plano na lateral do paciente', function (): void {
    ($this->as)()->postJson(route('panel.patients.store'), ($this->payload)());
    $patient = ($this->patient)();

    ($this->as)()->get(route('panel.patients.medicalrecords.index', $patient->id))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('patient.covenant_plan_name', 'AMIL ONE S6500 (Reg. ANS 471234567)'));
});

it('[LGPD] exportação dos dados do paciente inclui o plano', function (): void {
    ($this->as)()->postJson(route('panel.patients.store'), ($this->payload)());

    $export = app(PatientDataExporter::class)->export(($this->patient)());

    expect($export['clinic']['covenant_plan'])->toBe(['name' => 'AMIL ONE S6500', 'ans_registration' => '471234567'])
        ->and($export['format_version'])->toBe(3);
});

it('guia TISS leva o plano do paciente quando é do convênio faturado', function (): void {
    actingAsFinancialEntityUser($this->clinic);
    $schedule = createBillableSchedule($this->clinic);
    $plan     = pcpPlan($schedule->covenant, ['name' => 'PLANO DA GUIA']);
    $schedule->patient->forceFill(['covenant_plan_id' => $plan->id])->save();

    $claim = app(BillingService::class)->createIndividual(['schedule_id' => $schedule->id, 'unit_price' => 150, 'clinical_indication' => 'H40.1']);

    expect($claim->tissGuide->beneficiary_plan)->toBe('PLANO DA GUIA');
});

it('guia TISS não leva plano de OUTRO convênio (agendamento faturado por convênio diferente do cadastro)', function (): void {
    actingAsFinancialEntityUser($this->clinic);
    $schedule = createBillableSchedule($this->clinic);
    $schedule->patient->forceFill(['covenant_plan_id' => $this->plan->id])->save(); // plano da AMIL global

    $claim = app(BillingService::class)->createIndividual(['schedule_id' => $schedule->id, 'unit_price' => 150, 'clinical_indication' => 'H40.1']);

    expect($claim->tissGuide->beneficiary_plan)->toBeNull();
});
