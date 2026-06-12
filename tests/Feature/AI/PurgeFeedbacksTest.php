<?php

use App\Domains\AI\Models\{AiRun, AiRunFeedback};
use App\Domains\AI\Services\AiCreditWalletService;
use App\Enums\AI\{AiRiskLevel, AiRunMode, AiRunStatus};
use App\Enums\{ClientRule, FeatureKey, SubscriptionStatus};
use App\Models\{Doctor, Entity, MedicalRecord, Patient, People, Plan, PlanFeature, Subscription, User};
use Carbon\Carbon;

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
        description: 'Créditos',
    );

    $this->patient = Patient::factory()->create(['entity_id' => $this->entity->id]);
    $this->record  = MedicalRecord::query()->create([
        'entity_id'  => $this->entity->id,
        'patient_id' => $this->patient->id,
        'doctor_id'  => $this->doctorModel->id,
    ]);
});

function makeFeedback($test, Carbon $createdAt): AiRunFeedback
{
    $run = AiRun::query()->create([
        'entity_id'         => $test->entity->id,
        'patient_id'        => $test->patient->id,
        'medical_record_id' => $test->record->id,
        'requested_by'      => $test->doctor->id,
        'workflow'          => 'record_assist',
        'mode'              => AiRunMode::Validated->value,
        'risk_level'        => AiRiskLevel::Medium->value,
        'status'            => AiRunStatus::Approved->value,
        'estimated_credits' => 5,
        'reserved_credits'  => 5,
        'consumed_credits'  => 4,
    ]);

    $fb = AiRunFeedback::query()->create([
        'ai_run_id'          => $run->id,
        'entity_id'          => $test->entity->id,
        'doctor_id'          => $test->doctorModel->id,
        'edit_ratio_percent' => 40,
        'tags'               => ['language'],
        'note'               => null,
        'submitted_at'       => $createdAt,
    ]);

    $fb->forceFill(['created_at' => $createdAt])->saveQuietly();

    return $fb->fresh();
}

it('apaga feedbacks com mais de 90 dias', function () {
    $oldA  = makeFeedback($this, now()->subDays(100));
    $oldB  = makeFeedback($this, now()->subDays(95));
    $fresh = makeFeedback($this, now()->subDays(10));

    $this->artisan('ai:purge-feedbacks')
        ->expectsOutputToContain('Feedbacks deletados: 2')
        ->assertSuccessful();

    expect(AiRunFeedback::query()->find($oldA->id))->toBeNull()
        ->and(AiRunFeedback::query()->find($oldB->id))->toBeNull()
        ->and(AiRunFeedback::query()->find($fresh->id))->not->toBeNull();
});

it('mantém feedbacks abaixo do threshold', function () {
    makeFeedback($this, now()->subDays(30));
    makeFeedback($this, now()->subDays(89));

    $this->artisan('ai:purge-feedbacks')->assertSuccessful();

    expect(AiRunFeedback::query()->count())->toBe(2);
});

it('dry-run reporta contagem sem deletar', function () {
    makeFeedback($this, now()->subDays(120));
    makeFeedback($this, now()->subDays(95));

    $this->artisan('ai:purge-feedbacks', ['--dry-run' => true])
        ->expectsOutputToContain('Feedbacks > 90 dias: 2')
        ->assertSuccessful();

    expect(AiRunFeedback::query()->count())->toBe(2);
});

it('respeita --days customizado', function () {
    makeFeedback($this, now()->subDays(40));
    makeFeedback($this, now()->subDays(20));

    $this->artisan('ai:purge-feedbacks', ['--days' => 30])
        ->expectsOutputToContain('Feedbacks deletados: 1')
        ->assertSuccessful();

    expect(AiRunFeedback::query()->count())->toBe(1);
});
