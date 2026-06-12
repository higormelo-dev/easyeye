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

/**
 * Cria um AiRun direto, reservando créditos para simular o estado pós-store.
 */
function makeRun($test, AiRunStatus $status, int $reserved = 50, int $consumed = 0): AiRun
{
    /** @var AiCreditWalletService $wallet */
    $wallet = app(AiCreditWalletService::class);

    $run = AiRun::query()->create([
        'entity_id'         => $test->entity->id,
        'patient_id'        => $test->patient->id,
        'medical_record_id' => $test->record->id,
        'requested_by'      => $test->doctor->id,
        'workflow'          => 'record_assist',
        'mode'              => AiRunMode::Validated->value,
        'risk_level'        => AiRiskLevel::Medium->value,
        'status'            => $status->value,
        'estimated_credits' => $reserved,
        'reserved_credits'  => $reserved,
        'consumed_credits'  => $consumed,
    ]);

    // Persiste a reserva no ledger para o release ter algo para devolver.
    $wallet->reserve(
        entityId: $test->entity->id,
        amount: $reserved,
        aiRunId: (string) $run->id,
        idempotencyKey: "ai-run:{$run->id}:reserve-test",
    );

    return $run;
}

describe('cancel de run em pré-execução', function () {
    it('cancela run em Reserved e libera créditos reservados inteiros', function () {
        $balanceBefore = app(AiCreditWalletService::class)->balance($this->entity->id);
        $run           = makeRun($this, AiRunStatus::Reserved, reserved: 100);
        $balanceAfter  = app(AiCreditWalletService::class)->balance($this->entity->id);

        // A reserva tirou 100 do saldo disponível.
        expect($balanceAfter['available'])->toBe($balanceBefore['available'] - 100);

        $this->actingAs($this->doctor)
            ->withSession(panelSession($this->doctorUser))
            ->postJson(route('panel.ai-runs.cancel', $run))
            ->assertOk()
            ->assertJsonPath('status', AiRunStatus::Cancelled->value)
            ->assertJsonPath('will_settle_async', false);

        // Saldo deve ter retornado ao patamar inicial.
        $balanceFinal = app(AiCreditWalletService::class)->balance($this->entity->id);
        expect($balanceFinal['available'])->toBe($balanceBefore['available']);

        $fresh = $run->fresh();
        expect($fresh->status)->toBe(AiRunStatus::Cancelled)
            ->and($fresh->cancelled_at)->not->toBeNull()
            ->and((string) $fresh->cancelled_by)->toBe((string) $this->doctor->id);
    });

    it('cancela run em Pending também libera reserva', function () {
        $balanceBefore = app(AiCreditWalletService::class)->balance($this->entity->id);
        $run           = makeRun($this, AiRunStatus::Pending, reserved: 30);

        $this->actingAs($this->doctor)
            ->withSession(panelSession($this->doctorUser))
            ->postJson(route('panel.ai-runs.cancel', $run))
            ->assertOk()
            ->assertJsonPath('will_settle_async', false);

        expect(app(AiCreditWalletService::class)->balance($this->entity->id)['available'])
            ->toBe($balanceBefore['available']);
    });
});

describe('cancel de run em execução', function () {
    it('marca cancelled_at em Running e devolve will_settle_async=true sem mudar status', function () {
        $run = makeRun($this, AiRunStatus::Running, reserved: 100, consumed: 0);

        $this->actingAs($this->doctor)
            ->withSession(panelSession($this->doctorUser))
            ->postJson(route('panel.ai-runs.cancel', $run))
            ->assertOk()
            ->assertJsonPath('will_settle_async', true);

        $fresh = $run->fresh();
        // Status continua Running — orchestrator faz a transição final.
        expect($fresh->status)->toBe(AiRunStatus::Running)
            ->and($fresh->cancelled_at)->not->toBeNull();
    });
});

describe('cancel de runs em estado terminal', function () {
    it('retorna 422 para run em WaitingApproval', function () {
        $run = makeRun($this, AiRunStatus::WaitingApproval);

        $this->actingAs($this->doctor)
            ->withSession(panelSession($this->doctorUser))
            ->postJson(route('panel.ai-runs.cancel', $run))
            ->assertStatus(422);
    });

    it('retorna 422 para run em Approved', function () {
        $run = makeRun($this, AiRunStatus::Approved);

        $this->actingAs($this->doctor)
            ->withSession(panelSession($this->doctorUser))
            ->postJson(route('panel.ai-runs.cancel', $run))
            ->assertStatus(422);
    });

    it('é idempotente: 2ª chamada em Cancelled responde 200', function () {
        $run = makeRun($this, AiRunStatus::Reserved);

        $this->actingAs($this->doctor)
            ->withSession(panelSession($this->doctorUser))
            ->postJson(route('panel.ai-runs.cancel', $run))
            ->assertOk();

        $this->actingAs($this->doctor)
            ->withSession(panelSession($this->doctorUser))
            ->postJson(route('panel.ai-runs.cancel', $run))
            ->assertOk();
    });
});

describe('autorização do cancel', function () {
    it('bloqueia cancelamento cross-tenant', function () {
        $otherEntity = Entity::factory()->create(['is_client' => true, 'active' => true]);
        $otherUser   = User::factory()->create();
        $otherEU     = createEntityUser($otherEntity, $otherUser, ClientRule::Doctor->value);
        $run         = makeRun($this, AiRunStatus::Reserved);

        $this->actingAs($otherUser)
            ->withSession(panelSession($otherEU))
            ->postJson(route('panel.ai-runs.cancel', $run))
            ->assertStatus(403);
    });

    it('permite que o próprio solicitante cancele seu run', function () {
        $run = makeRun($this, AiRunStatus::Reserved);

        $this->actingAs($this->doctor)
            ->withSession(panelSession($this->doctorUser))
            ->postJson(route('panel.ai-runs.cancel', $run))
            ->assertOk();
    });
});
