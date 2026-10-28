<?php

use App\Domains\AI\Models\{AiRun, AiRunFeedback};
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

    $this->run = AiRun::query()->create([
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
});

it('aceita feedback com tags válidas + nota', function () {
    $this->actingAs($this->doctor)
        ->withSession(panelSession($this->doctorUser))
        ->postJson(route('panel.ai-runs.feedback', $this->run), [
            'edit_ratio_percent' => 45,
            'tags'               => ['diagnosis_wrong', 'missing_context'],
            'note'               => 'Faltou considerar a comorbidade do paciente.',
        ])
        ->assertOk();

    $fb = AiRunFeedback::query()->where('ai_run_id', $this->run->id)->firstOrFail();
    expect($fb->edit_ratio_percent)->toBe(45)
        ->and($fb->tags)->toBe(['diagnosis_wrong', 'missing_context'])
        ->and($fb->note)->toContain('comorbidade');
});

it('rejeita tag fora da allowlist', function () {
    $this->actingAs($this->doctor)
        ->withSession(panelSession($this->doctorUser))
        ->postJson(route('panel.ai-runs.feedback', $this->run), [
            'edit_ratio_percent' => 35,
            'tags'               => ['hacker_attempt'],
        ])
        ->assertStatus(422);

    expect(AiRunFeedback::query()->count())->toBe(0);
});

it('re-submit substitui feedback anterior (idempotente)', function () {
    $this->actingAs($this->doctor)
        ->withSession(panelSession($this->doctorUser))
        ->postJson(route('panel.ai-runs.feedback', $this->run), [
            'edit_ratio_percent' => 40,
            'tags'               => ['language'],
        ])
        ->assertOk();

    $this->actingAs($this->doctor)
        ->withSession(panelSession($this->doctorUser))
        ->postJson(route('panel.ai-runs.feedback', $this->run), [
            'edit_ratio_percent' => 65,
            'tags'               => ['excess', 'other'],
            'note'               => 'reformulando feedback',
        ])
        ->assertOk();

    expect(AiRunFeedback::query()->where('ai_run_id', $this->run->id)->count())->toBe(1);
    $fb = AiRunFeedback::query()->where('ai_run_id', $this->run->id)->firstOrFail();
    expect($fb->edit_ratio_percent)->toBe(65)
        ->and($fb->tags)->toBe(['excess', 'other']);
});

it('bloqueia feedback de quem não pediu nem aprovou o run', function () {
    $other   = User::factory()->create();
    $otherEU = createEntityUser($this->entity, $other, ClientRule::Doctor->value);

    $this->actingAs($other)
        ->withSession(panelSession($otherEU))
        ->postJson(route('panel.ai-runs.feedback', $this->run), [
            'edit_ratio_percent' => 50,
        ])
        ->assertStatus(403);
});

it('bloqueia cross-tenant com 403', function () {
    $otherEntity = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $otherUser   = User::factory()->create();
    $otherEU     = createEntityUser($otherEntity, $otherUser, ClientRule::Doctor->value);

    $this->actingAs($otherUser)
        ->withSession(panelSession($otherEU))
        ->postJson(route('panel.ai-runs.feedback', $this->run), [
            'edit_ratio_percent' => 50,
        ])
        ->assertStatus(403);
});

it('aceita feedback sem tags nem nota (só ratio)', function () {
    $this->actingAs($this->doctor)
        ->withSession(panelSession($this->doctorUser))
        ->postJson(route('panel.ai-runs.feedback', $this->run), [
            'edit_ratio_percent' => 35,
        ])
        ->assertOk();

    $fb = AiRunFeedback::query()->where('ai_run_id', $this->run->id)->firstOrFail();
    expect($fb->edit_ratio_percent)->toBe(35)
        ->and($fb->tags)->toBe([])
        ->and($fb->note)->toBeNull();
});
