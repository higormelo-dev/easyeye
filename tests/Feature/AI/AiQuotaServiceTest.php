<?php

use App\Domains\AI\Models\AiRun;
use App\Domains\AI\Services\AiQuotaService;
use App\Enums\AI\{AiRiskLevel, AiRunMode, AiRunStatus};
use App\Enums\ClientRule;
use App\Enums\{FeatureKey, SubscriptionStatus};
use App\Models\{Doctor, Entity, MedicalRecord, Patient, People, Plan, PlanFeature, Subscription, User};
use Carbon\Carbon;

beforeEach(function () {
    $this->service = app(AiQuotaService::class);
    $this->entity  = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $this->plan    = Plan::factory()->create(['active' => true]);

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

    $this->patient = Patient::factory()->create(['entity_id' => $this->entity->id]);
    $this->record  = MedicalRecord::query()->create([
        'entity_id'  => $this->entity->id,
        'patient_id' => $this->patient->id,
        'doctor_id'  => $this->doctorModel->id,
    ]);
});

function makeQuotaRun($test, AiRunStatus $status, int $consumed, ?Carbon $createdAt = null): AiRun
{
    $run = AiRun::query()->create([
        'entity_id'         => $test->entity->id,
        'patient_id'        => $test->patient->id,
        'medical_record_id' => $test->record->id,
        'requested_by'      => $test->doctor->id,
        'workflow'          => 'record_assist',
        'mode'              => AiRunMode::Validated->value,
        'risk_level'        => AiRiskLevel::Medium->value,
        'status'            => $status->value,
        'estimated_credits' => 100,
        'reserved_credits'  => 100,
        'consumed_credits'  => $consumed,
    ]);

    if ($createdAt) {
        $run->forceFill(['created_at' => $createdAt])->save();
    }

    return $run;
}

it('soma consumed_credits de runs Approved + WaitingApproval do mês corrente', function () {
    PlanFeature::factory()->limit(FeatureKey::AiMonthlyCredits, 1000)->for($this->plan)->create();

    makeQuotaRun($this, AiRunStatus::Approved, 150);
    makeQuotaRun($this, AiRunStatus::WaitingApproval, 50);
    makeQuotaRun($this, AiRunStatus::Failed, 999);     // não deve contar
    makeQuotaRun($this, AiRunStatus::Cancelled, 999);  // não deve contar
    makeQuotaRun($this, AiRunStatus::Rejected, 999);   // não deve contar

    $snap = $this->service->currentMonthSnapshot($this->entity->id);

    expect($snap['monthly_quota'])->toBe(1000)
        ->and($snap['consumed_credits'])->toBe(200)
        ->and($snap['usage_percent'])->toBe(20.0);
});

it('ignora runs de meses anteriores', function () {
    PlanFeature::factory()->limit(FeatureKey::AiMonthlyCredits, 1000)->for($this->plan)->create();

    makeQuotaRun($this, AiRunStatus::Approved, 999, now()->subMonth());
    makeQuotaRun($this, AiRunStatus::Approved, 100);

    $snap = $this->service->currentMonthSnapshot($this->entity->id);

    expect($snap['consumed_credits'])->toBe(100);
});

it('retorna usage_percent null quando não há cota definida', function () {
    // Sem PlanFeature para AiMonthlyCredits
    makeQuotaRun($this, AiRunStatus::Approved, 100);

    $snap = $this->service->currentMonthSnapshot($this->entity->id);

    expect($snap['monthly_quota'])->toBe(0)
        ->and($snap['consumed_credits'])->toBe(100)
        ->and($snap['usage_percent'])->toBeNull();
});

it('inclui period_label e datas formatadas', function () {
    PlanFeature::factory()->limit(FeatureKey::AiMonthlyCredits, 500)->for($this->plan)->create();

    $snap = $this->service->currentMonthSnapshot($this->entity->id);

    expect($snap)->toHaveKeys(['period_label', 'period_start', 'period_end'])
        ->and($snap['period_start'])->toMatch('#^\d{2}/\d{2}/\d{4}$#')
        ->and($snap['period_end'])->toMatch('#^\d{2}/\d{2}/\d{4}$#');
});

it('retorna quota 0 quando entity não tem subscription ativa', function () {
    $other = Entity::factory()->create(['is_client' => true, 'active' => true]);

    $snap = $this->service->currentMonthSnapshot($other->id);

    expect($snap['monthly_quota'])->toBe(0)
        ->and($snap['consumed_credits'])->toBe(0);
});
