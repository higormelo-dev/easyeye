<?php

use App\Domains\AI\Models\AiRun;
use App\Domains\AI\Services\AiCreditWalletService;
use App\Enums\AI\{AiRiskLevel, AiRunMode, AiRunStatus};
use App\Enums\{ClientRule, FeatureKey, SubscriptionStatus};
use App\Models\{Doctor, Entity, MedicalRecord, Patient, People, Plan, PlanFeature, Subscription, User};
use App\Notifications\AiRunWaitingApprovalNotification;
use Carbon\Carbon;
use Illuminate\Support\Facades\Notification;

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

    $this->doctor      = User::factory()->create(['email' => 'doctor@test.local']);
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

function makeWaitingRun($test, ?Carbon $updatedAt = null, ?Carbon $notifiedAt = null): AiRun
{
    $run = AiRun::query()->create([
        'entity_id'         => $test->entity->id,
        'patient_id'        => $test->patient->id,
        'medical_record_id' => $test->record->id,
        'requested_by'      => $test->doctor->id,
        'workflow'          => 'record_assist',
        'mode'              => AiRunMode::Validated->value,
        'risk_level'        => AiRiskLevel::Medium->value,
        'status'            => AiRunStatus::WaitingApproval->value,
        'estimated_credits' => 10,
        'reserved_credits'  => 10,
        'consumed_credits'  => 0,
    ]);

    $payload = [];

    if ($updatedAt) {
        $payload['updated_at'] = $updatedAt;
        $payload['created_at'] = $updatedAt;
    }

    if ($notifiedAt) {
        $payload['notified_pending_at'] = $notifiedAt;
    }

    if ($payload) {
        $run->forceFill($payload)->saveQuietly();
    }

    return $run->fresh();
}

it('notifica run em WaitingApproval há mais de 24h', function () {
    Notification::fake();
    $run = makeWaitingRun($this, updatedAt: now()->subHours(26));

    $this->artisan('ai:notify-waiting-approval')->assertSuccessful();

    Notification::assertSentTo($this->doctor, AiRunWaitingApprovalNotification::class);
    expect($run->fresh()->notified_pending_at)->not->toBeNull();
});

it('não notifica run com menos de 24h', function () {
    Notification::fake();
    makeWaitingRun($this, updatedAt: now()->subHours(10));

    $this->artisan('ai:notify-waiting-approval')->assertSuccessful();

    Notification::assertNothingSent();
});

it('não notifica run em status terminal', function () {
    Notification::fake();
    $run = AiRun::query()->create([
        'entity_id'         => $this->entity->id,
        'patient_id'        => $this->patient->id,
        'medical_record_id' => $this->record->id,
        'requested_by'      => $this->doctor->id,
        'workflow'          => 'record_assist',
        'mode'              => AiRunMode::Validated->value,
        'risk_level'        => AiRiskLevel::Medium->value,
        'status'            => AiRunStatus::Approved->value,
        'estimated_credits' => 10,
        'reserved_credits'  => 10,
        'consumed_credits'  => 8,
    ]);
    $run->forceFill(['updated_at' => now()->subDays(3)])->saveQuietly();

    $this->artisan('ai:notify-waiting-approval')->assertSuccessful();

    Notification::assertNothingSent();
});

it('não re-notifica se já foi notificado nas últimas 24h', function () {
    Notification::fake();
    makeWaitingRun(
        $this,
        updatedAt: now()->subDays(3),
        notifiedAt: now()->subHours(6),
    );

    $this->artisan('ai:notify-waiting-approval')->assertSuccessful();

    Notification::assertNothingSent();
});

it('re-notifica quando última notificação foi há mais de 24h', function () {
    Notification::fake();
    makeWaitingRun(
        $this,
        updatedAt: now()->subDays(5),
        notifiedAt: now()->subDays(2),
    );

    $this->artisan('ai:notify-waiting-approval')->assertSuccessful();

    Notification::assertSentTo($this->doctor, AiRunWaitingApprovalNotification::class);
});

it('dry-run não envia notificação mas reporta count', function () {
    Notification::fake();
    makeWaitingRun($this, updatedAt: now()->subDays(2));

    $this->artisan('ai:notify-waiting-approval', ['--dry-run' => true])
        ->expectsOutputToContain('Runs a notificar: 1')
        ->assertSuccessful();

    Notification::assertNothingSent();
});
