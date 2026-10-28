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
        amount: 200,
        description: 'Créditos de teste',
    );

    $this->patient = Patient::factory()->create(['entity_id' => $this->entity->id]);
    $this->record  = MedicalRecord::query()->create([
        'entity_id'  => $this->entity->id,
        'patient_id' => $this->patient->id,
        'doctor_id'  => $this->doctorModel->id,
    ]);
});

function makeParentTestRun($test, AiRunStatus $status, AiRunMode $mode = AiRunMode::Validated, ?string $parentId = null): AiRun
{
    return AiRun::query()->create([
        'entity_id'         => $test->entity->id,
        'patient_id'        => $test->patient->id,
        'medical_record_id' => $test->record->id,
        'requested_by'      => $test->doctor->id,
        'workflow'          => 'record_assist',
        'mode'              => $mode->value,
        'risk_level'        => AiRiskLevel::Medium->value,
        'status'            => $status->value,
        'estimated_credits' => 10,
        'reserved_credits'  => 10,
        'consumed_credits'  => 8,
        'parent_run_id'     => $parentId,
    ]);
}

it('show retorna parent_summary quando run tem parent', function () {
    $parent = makeParentTestRun($this, AiRunStatus::Approved, AiRunMode::Economy);
    $child  = makeParentTestRun($this, AiRunStatus::Approved, AiRunMode::Validated, (string) $parent->id);

    $data = $this->actingAs($this->doctor)
        ->withSession(panelSession($this->doctorUser))
        ->getJson(route('panel.ai-runs.show', $child))
        ->assertOk()
        ->json('data');

    expect($data['is_escalation'])->toBeTrue()
        ->and($data['parent_run_id'])->toBe((string) $parent->id)
        ->and($data['parent_summary']['short_id'])->toBe(substr((string) $parent->id, 0, 8))
        ->and($data['parent_summary']['mode'])->toBe('economy')
        ->and($data['parent_summary']['status'])->toBe('approved');
});

it('show retorna parent_run_id null e is_escalation false para run original', function () {
    $original = makeParentTestRun($this, AiRunStatus::Approved);

    $data = $this->actingAs($this->doctor)
        ->withSession(panelSession($this->doctorUser))
        ->getJson(route('panel.ai-runs.show', $original))
        ->assertOk()
        ->json('data');

    expect($data['is_escalation'])->toBeFalse()
        ->and($data['parent_run_id'])->toBeNull()
        ->and($data['parent_summary'])->toBeNull();
});

it('index marca is_escalation no through dos runs', function () {
    $parent = makeParentTestRun($this, AiRunStatus::Approved, AiRunMode::Economy);
    $child  = makeParentTestRun($this, AiRunStatus::Approved, AiRunMode::Validated, (string) $parent->id);

    $resp = $this->actingAs($this->doctor)
        ->withSession(panelSession($this->doctorUser))
        ->get(route('panel.ai-runs.index'));

    $resp->assertOk();
    $runs = $resp->viewData('page')['props']['runs']['data'];

    $parentRow = collect($runs)->firstWhere('id', (string) $parent->id);
    $childRow  = collect($runs)->firstWhere('id', (string) $child->id);

    expect($parentRow['is_escalation'])->toBeFalse()
        ->and($childRow['is_escalation'])->toBeTrue();
});
