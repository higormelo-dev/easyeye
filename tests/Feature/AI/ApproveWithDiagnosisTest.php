<?php

use App\Domains\AI\Models\AiRun;
use App\Domains\AI\Services\AiCreditWalletService;
use App\Enums\AI\{AiRiskLevel, AiRunMode, AiRunStatus};
use App\Enums\{ClientRule, FeatureKey, SubscriptionStatus};
use App\Models\{Doctor, Entity, Patient, PatientExam, People, Plan, PlanFeature, Subscription, User};
use Illuminate\Support\Facades\DB;

/**
 * Cobre a gravação de diagnosis_cids em patient_exams no momento do approve
 * de laudo de IA (AiRunsController::approve) — ver PLAN.md "Dado estruturado
 * de diagnóstico". Setup espelha tests/Feature/AI/EyeImageReportRecordTest.php.
 */
beforeEach(function () {
    $this->entity = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $this->plan   = Plan::factory()->create(['active' => true]);

    PlanFeature::factory()->enabled(FeatureKey::HasAiEyeImageAnalysis)->for($this->plan)->create();
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

    ['schedule' => $this->schedule] = createScheduleForEntity($this->entity, [
        'patient_id' => $this->patient->id,
        'date_time'  => now(),
    ]);
    $this->exam = PatientExam::factory()->create([
        'patient_id'  => $this->patient->id,
        'schedule_id' => $this->schedule->id,
        'laterality'  => 1,
    ]);
});

function waitingEyeRunForExam($test, PatientExam $exam): AiRun
{
    $run = AiRun::query()->create([
        'entity_id'         => $test->entity->id,
        'patient_id'        => $test->patient->id,
        'requested_by'      => $test->doctor->id,
        'workflow'          => 'eye_image_analysis',
        'mode'              => AiRunMode::Economy->value,
        'risk_level'        => AiRiskLevel::Medium->value,
        'status'            => AiRunStatus::WaitingApproval->value,
        'estimated_credits' => 10,
        'reserved_credits'  => 10,
        'consumed_credits'  => 8,
        'final_output'      => 'Achados compatíveis com retinopatia leve.',
        'input_summary'     => ['exam_ids' => [(string) $exam->id]],
    ]);

    DB::table('ai_run_patient_exam')->insert([
        'ai_run_id'       => $run->id,
        'patient_exam_id' => $exam->id,
        'entity_id'       => $test->entity->id,
        'created_at'      => now(),
    ]);

    return $run;
}

function approveWithPayload($test, AiRun $run, array $extra = [])
{
    return $test->actingAs($test->doctor)
        ->withSession(panelSession($test->doctorUser))
        ->postJson(route('panel.ai-runs.approve', $run), array_merge(['final_output' => $run->final_output], $extra));
}

it('grava diagnosis_cids nos exames do pivot quando enviado no approve', function () {
    $run = waitingEyeRunForExam($this, $this->exam);

    approveWithPayload($this, $run, [
        'diagnosis_cids' => [['code' => 'H40.1', 'description' => 'Glaucoma primário de ângulo aberto']],
    ])->assertOk();

    expect($this->exam->fresh()->diagnosis_cids)->toBe([
        ['code' => 'H40.1', 'description' => 'Glaucoma primário de ângulo aberto'],
    ]);
});

it('não sobrescreve diagnosis_cids já salvo quando o approve não envia a chave', function () {
    $this->exam->update(['diagnosis_cids' => [['code' => 'H35.0', 'description' => 'Retinopatia de fundo']]]);
    $run = waitingEyeRunForExam($this, $this->exam);

    approveWithPayload($this, $run)->assertOk();

    expect($this->exam->fresh()->diagnosis_cids)->toBe([
        ['code' => 'H35.0', 'description' => 'Retinopatia de fundo'],
    ]);
});

it('rejeita diagnosis_cids com shape inválido (422)', function () {
    $run = waitingEyeRunForExam($this, $this->exam);

    approveWithPayload($this, $run, [
        'diagnosis_cids' => [['code' => str_repeat('X', 20), 'description' => 'Código inválido']],
    ])->assertStatus(422);

    expect($this->exam->fresh()->diagnosis_cids)->toBeNull();
});

it('approve de run sem exames vinculados (record_assist) ignora diagnosis_cids sem quebrar', function () {
    $run = AiRun::query()->create([
        'entity_id'         => $this->entity->id,
        'patient_id'        => $this->patient->id,
        'requested_by'      => $this->doctor->id,
        'workflow'          => 'record_assist',
        'mode'              => AiRunMode::Economy->value,
        'risk_level'        => AiRiskLevel::Medium->value,
        'status'            => AiRunStatus::WaitingApproval->value,
        'estimated_credits' => 10,
        'reserved_credits'  => 10,
        'consumed_credits'  => 8,
        'final_output'      => 'Resumo do caso.',
    ]);

    approveWithPayload($this, $run, [
        'diagnosis_cids' => [['code' => 'H40.1', 'description' => 'Glaucoma']],
    ])->assertOk();

    expect($this->exam->fresh()->diagnosis_cids)->toBeNull()
        ->and($run->fresh()->status)->toBe(AiRunStatus::Approved);
});
