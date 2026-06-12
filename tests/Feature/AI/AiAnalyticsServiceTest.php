<?php

use App\Domains\AI\Models\AiRun;
use App\Domains\AI\Services\AiAnalyticsService;
use App\Enums\AI\{AiRiskLevel, AiRunMode, AiRunStatus};
use App\Enums\{ClientRule, FeatureKey, SubscriptionStatus};
use App\Models\{Doctor, Entity, MedicalRecord, Patient, People, Plan, PlanFeature, Subscription, User};

beforeEach(function () {
    $this->service = app(AiAnalyticsService::class);
    $this->entity  = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $this->plan    = Plan::factory()->create(['active' => true]);

    PlanFeature::factory()->enabled(FeatureKey::HasAiExamAssistant)->for($this->plan)->create();
    PlanFeature::factory()->limit(FeatureKey::AiMonthlyCredits, 1000)->for($this->plan)->create();

    Subscription::factory()->create([
        'entity_id' => $this->entity->id,
        'plan_id'   => $this->plan->id,
        'status'    => SubscriptionStatus::Active,
        'starts_at' => now()->subDay(),
        'ends_at'   => now()->addMonth(),
    ]);

    $this->user      = User::factory()->create(['name' => 'DR. MARIO']);
    $this->userOther = User::factory()->create(['name' => 'DR. ANA']);

    $this->eu1 = createEntityUser($this->entity, $this->user, ClientRule::Doctor->value);
    $this->eu2 = createEntityUser($this->entity, $this->userOther, ClientRule::Doctor->value);

    $this->doctor1 = Doctor::query()->create([
        'entity_user_id' => $this->eu1->id,
        'person_id'      => People::factory()->create()->id,
        'active'         => true,
    ]);

    $this->patient = Patient::factory()->create(['entity_id' => $this->entity->id]);
    $this->record1 = MedicalRecord::query()->create([
        'entity_id'  => $this->entity->id,
        'patient_id' => $this->patient->id,
        'doctor_id'  => $this->doctor1->id,
    ]);
    $this->record2 = MedicalRecord::query()->create([
        'entity_id'  => $this->entity->id,
        'patient_id' => $this->patient->id,
        'doctor_id'  => $this->doctor1->id,
    ]);
});

function makeAnalyticsRun($test, User $approver, int $consumed, ?int $approveAfterSec = 60, ?string $recordId = null): AiRun
{
    $createdAt  = now()->subMinutes(10);
    $approvedAt = $approveAfterSec !== null ? $createdAt->copy()->addSeconds($approveAfterSec) : null;

    $run = AiRun::query()->create([
        'entity_id'         => $test->entity->id,
        'patient_id'        => $test->patient->id,
        'medical_record_id' => $recordId ?? $test->record1->id,
        'requested_by'      => $test->user->id,
        'approved_by'       => $approver->id,
        'workflow'          => 'record_assist',
        'mode'              => AiRunMode::Validated->value,
        'risk_level'        => AiRiskLevel::Medium->value,
        'status'            => AiRunStatus::Approved->value,
        'estimated_credits' => $consumed,
        'reserved_credits'  => $consumed,
        'consumed_credits'  => $consumed,
        'approved_at'       => $approvedAt,
    ]);

    $run->forceFill(['created_at' => $createdAt])->save();

    return $run;
}

it('byDoctor agrupa por médico aprovador e calcula avg de créditos', function () {
    makeAnalyticsRun($this, $this->user, 10, 30);
    makeAnalyticsRun($this, $this->user, 20, 60);
    makeAnalyticsRun($this, $this->userOther, 50, 120);

    $rows = $this->service->byDoctor($this->entity->id, now()->subDay(), now()->addDay());

    expect($rows)->toHaveCount(2);

    $mario = collect($rows)->firstWhere('doctor_name', 'DR. MARIO');
    $ana   = collect($rows)->firstWhere('doctor_name', 'DR. ANA');

    expect($mario['approved'])->toBe(2)
        ->and($mario['avg_credits'])->toBe(15.0)
        ->and($ana['approved'])->toBe(1)
        ->and($ana['avg_credits'])->toBe(50.0);
});

it('byDoctor ordena descendente por número de aprovados', function () {
    foreach (range(1, 3) as $_) {
        makeAnalyticsRun($this, $this->user, 10);
    }
    makeAnalyticsRun($this, $this->userOther, 10);

    $rows = $this->service->byDoctor($this->entity->id, now()->subDay(), now()->addDay());

    expect($rows[0]['doctor_name'])->toBe('DR. MARIO')
        ->and($rows[1]['doctor_name'])->toBe('DR. ANA');
});

it('byDoctor ignora runs fora do período', function () {
    $run = makeAnalyticsRun($this, $this->user, 10);
    $run->forceFill(['created_at' => now()->subMonths(2)])->save();

    $rows = $this->service->byDoctor($this->entity->id, now()->subDay(), now()->addDay());

    expect($rows)->toHaveCount(0);
});

it('averageApproveSeconds retorna média correta em segundos', function () {
    makeAnalyticsRun($this, $this->user, 10, 30);
    makeAnalyticsRun($this, $this->user, 10, 90);

    $avg = $this->service->averageApproveSeconds($this->entity->id, now()->subDay(), now()->addDay());

    expect($avg)->toBe(60.0);
});

it('averageApproveSeconds retorna null sem runs aprovados', function () {
    $avg = $this->service->averageApproveSeconds($this->entity->id, now()->subDay(), now()->addDay());

    expect($avg)->toBeNull();
});

it('averageCostPerRecord soma créditos por record e calcula média entre records', function () {
    // record1: 10 + 20 = 30
    makeAnalyticsRun($this, $this->user, 10, recordId: $this->record1->id);
    makeAnalyticsRun($this, $this->user, 20, recordId: $this->record1->id);
    // record2: 50
    makeAnalyticsRun($this, $this->user, 50, recordId: $this->record2->id);

    $avg = $this->service->averageCostPerRecord($this->entity->id, now()->subDay(), now()->addDay());

    // (30 + 50) / 2 = 40
    expect($avg)->toBe(40.0);
});
