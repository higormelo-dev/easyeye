<?php

use App\Domains\AI\Models\AiRun;
use App\Domains\AI\Services\AiCreditWalletService;
use App\Enums\AI\{AiRiskLevel, AiRunMode, AiRunStatus};
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

    app(AiCreditWalletService::class)->purchaseCredits(
        entityId: $this->entity->id,
        amount: 500,
        description: 'Créditos de teste',
    );

    $this->patient = Patient::factory()->create(['entity_id' => $this->entity->id]);
    $this->record  = MedicalRecord::query()->create([
        'entity_id'  => $this->entity->id,
        'patient_id' => $this->patient->id,
        'doctor_id'  => $this->doctorModel->id,
    ]);
});

it('show() devolve current_role, current_provider, started_at e elapsed_ms quando o run está em execução', function () {
    $startedAt = now()->subSeconds(7);

    $run = AiRun::query()->create([
        'entity_id'         => $this->entity->id,
        'patient_id'        => $this->patient->id,
        'medical_record_id' => $this->record->id,
        'requested_by'      => $this->doctor->id,
        'workflow'          => 'record_assist',
        'mode'              => AiRunMode::Validated->value,
        'risk_level'        => AiRiskLevel::Medium->value,
        'status'            => AiRunStatus::Running->value,
        'current_role'      => 'reviewer',
        'current_provider'  => 'anthropic',
        'estimated_credits' => 30,
        'reserved_credits'  => 30,
        'consumed_credits'  => 12,
        'started_at'        => $startedAt,
    ]);

    $response = $this->actingAs($this->doctor)
        ->withSession(panelSession($this->doctorUser))
        ->getJson(route('panel.ai-runs.show', $run))
        ->assertOk();

    $data = $response->json('data');

    expect($data['current_role'])->toBe('reviewer')
        ->and($data['current_provider'])->toBe('anthropic')
        ->and($data['status'])->toBe('running')
        ->and($data['started_at'])->not->toBeNull()
        ->and($data['elapsed_ms'])->toBeGreaterThanOrEqual(6000)
        ->and($data['elapsed_ms'])->toBeLessThan(20000);
});

it('show() devolve elapsed_ms null quando started_at ainda não foi marcado', function () {
    $run = AiRun::query()->create([
        'entity_id'         => $this->entity->id,
        'patient_id'        => $this->patient->id,
        'medical_record_id' => $this->record->id,
        'requested_by'      => $this->doctor->id,
        'workflow'          => 'record_assist',
        'mode'              => AiRunMode::Validated->value,
        'risk_level'        => AiRiskLevel::Medium->value,
        'status'            => AiRunStatus::Reserved->value,
        'estimated_credits' => 30,
        'reserved_credits'  => 30,
    ]);

    $response = $this->actingAs($this->doctor)
        ->withSession(panelSession($this->doctorUser))
        ->getJson(route('panel.ai-runs.show', $run))
        ->assertOk();

    expect($response->json('data.elapsed_ms'))->toBeNull()
        ->and($response->json('data.started_at'))->toBeNull()
        ->and($response->json('data.current_role'))->toBeNull();
});
