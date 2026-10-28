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

function makeHistoryRun($test, AiRunStatus $status, string $output = 'Resultado'): AiRun
{
    return AiRun::query()->create([
        'entity_id'         => $test->entity->id,
        'patient_id'        => $test->patient->id,
        'medical_record_id' => $test->record->id,
        'requested_by'      => $test->doctor->id,
        'workflow'          => 'record_assist',
        'mode'              => AiRunMode::Validated->value,
        'risk_level'        => AiRiskLevel::Medium->value,
        'status'            => $status->value,
        'estimated_credits' => 20,
        'reserved_credits'  => 20,
        'consumed_credits'  => 15,
        'final_output'      => $output,
    ]);
}

it('retorna até 5 últimos runs do paciente em ordem decrescente', function () {
    foreach (range(1, 7) as $i) {
        $r = makeHistoryRun($this, AiRunStatus::Approved, "Run {$i}");
        $r->forceFill(['created_at' => now()->subMinutes(7 - $i)])->save();
    }

    $response = $this->actingAs($this->doctor)
        ->withSession(panelSession($this->doctorUser))
        ->getJson(route('panel.ai-runs.by-patient', $this->patient))
        ->assertOk();

    $data = $response->json('data');
    expect($data)->toHaveCount(5)
        ->and($data[0]['preview'])->toBe('Run 7')
        ->and($data[1]['preview'])->toBe('Run 6')
        ->and($data[4]['preview'])->toBe('Run 3');
});

it('não retorna runs de outro paciente', function () {
    $other = Patient::factory()->create(['entity_id' => $this->entity->id]);

    AiRun::query()->create([
        'entity_id'         => $this->entity->id,
        'patient_id'        => $other->id,
        'medical_record_id' => $this->record->id,
        'requested_by'      => $this->doctor->id,
        'workflow'          => 'record_assist',
        'mode'              => AiRunMode::Validated->value,
        'risk_level'        => AiRiskLevel::Medium->value,
        'status'            => AiRunStatus::Approved->value,
        'estimated_credits' => 10,
        'reserved_credits'  => 10,
        'final_output'      => 'Outro paciente',
    ]);

    makeHistoryRun($this, AiRunStatus::Approved, 'Meu paciente');

    $data = $this->actingAs($this->doctor)
        ->withSession(panelSession($this->doctorUser))
        ->getJson(route('panel.ai-runs.by-patient', $this->patient))
        ->assertOk()
        ->json('data');

    expect($data)->toHaveCount(1)
        ->and($data[0]['preview'])->toBe('Meu paciente');
});

it('só retorna runs em Approved e WaitingApproval', function () {
    makeHistoryRun($this, AiRunStatus::Approved, 'aprovado');
    makeHistoryRun($this, AiRunStatus::WaitingApproval, 'aguarda');
    makeHistoryRun($this, AiRunStatus::Failed, 'falhou');
    makeHistoryRun($this, AiRunStatus::Cancelled, 'cancelou');
    makeHistoryRun($this, AiRunStatus::Rejected, 'rejeitou');

    $data = $this->actingAs($this->doctor)
        ->withSession(panelSession($this->doctorUser))
        ->getJson(route('panel.ai-runs.by-patient', $this->patient))
        ->assertOk()
        ->json('data');

    $statuses = collect($data)->pluck('status')->all();
    expect($statuses)->toContain('approved')
        ->and($statuses)->toContain('waiting_approval')
        ->and($statuses)->not->toContain('failed')
        ->and($statuses)->not->toContain('cancelled')
        ->and($statuses)->not->toContain('rejected');
});

it('bloqueia cross-tenant com 403', function () {
    $otherEntity  = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $otherPatient = Patient::factory()->create(['entity_id' => $otherEntity->id]);

    $this->actingAs($this->doctor)
        ->withSession(panelSession($this->doctorUser))
        ->getJson(route('panel.ai-runs.by-patient', $otherPatient))
        ->assertStatus(403);
});

it('retorna preview truncado em 140 caracteres', function () {
    makeHistoryRun($this, AiRunStatus::Approved, str_repeat('x', 200));

    $data = $this->actingAs($this->doctor)
        ->withSession(panelSession($this->doctorUser))
        ->getJson(route('panel.ai-runs.by-patient', $this->patient))
        ->assertOk()
        ->json('data');

    expect(mb_strlen($data[0]['preview']))->toBe(140);
});
