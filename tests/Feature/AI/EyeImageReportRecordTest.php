<?php

use App\Domains\AI\Models\AiRun;
use App\Domains\AI\Services\AiCreditWalletService;
use App\Enums\AI\{AiRiskLevel, AiRunMode, AiRunStatus};
use App\Enums\{ClientRule, FeatureKey, SubscriptionStatus};
use App\Models\{Doctor, Entity, MedicalRecord, MedicalRecordDocumentation, Patient, PatientExam, People, Plan, PlanFeature, Subscription, User};
use Illuminate\Support\Facades\DB;

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

    // Aprovador com perfil médico real (passa IssueReport e pode abrir prontuário).
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

    // Consulta (agendamento) do dia + exame vinculado a ela.
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

function makeWaitingEyeRun($test): AiRun
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
        'input_summary'     => ['exam_ids' => [(string) $test->exam->id]],
    ]);

    DB::table('ai_run_patient_exam')->insert([
        'ai_run_id'       => $run->id,
        'patient_exam_id' => $test->exam->id,
        'entity_id'       => $test->entity->id,
        'created_at'      => now(),
    ]);

    return $run;
}

function approveAs($test, AiRun $run)
{
    return $test->actingAs($test->doctor)
        ->withSession(panelSession($test->doctorUser))
        ->postJson(route('panel.ai-runs.approve', $run), ['final_output' => $run->final_output]);
}

describe('laudo de imagem -> prontuário do dia da consulta', function () {
    it('sem prontuário do dia: aprova e pede confirmação para abrir', function () {
        $run = makeWaitingEyeRun($this);

        approveAs($this, $run)
            ->assertOk()
            ->assertJsonPath('requires_record_confirmation', true);

        expect(MedicalRecordDocumentation::query()->where('ai_run_id', $run->id)->exists())->toBeFalse()
            ->and($run->fresh()->status)->toBe(AiRunStatus::Approved);
    });

    it('com prontuário do dia: anexa o laudo automaticamente', function () {
        $record = MedicalRecord::query()->create([
            'entity_id'   => $this->entity->id,
            'patient_id'  => $this->patient->id,
            'doctor_id'   => $this->doctorModel->id,
            'schedule_id' => $this->schedule->id, // prontuário da consulta (mesmo agendamento)
        ]);

        $run = makeWaitingEyeRun($this);

        approveAs($this, $run)
            ->assertOk()
            ->assertJsonPath('requires_record_confirmation', false);

        expect(MedicalRecordDocumentation::query()
            ->where('ai_run_id', $run->id)
            ->where('medical_record_id', $record->id)
            ->exists())->toBeTrue();
    });

    it('endpoint record abre prontuário e registra o laudo quando confirmado', function () {
        $run = makeWaitingEyeRun($this);
        approveAs($this, $run)->assertOk();

        $before = MedicalRecord::query()->count();

        $this->actingAs($this->doctor)
            ->withSession(panelSession($this->doctorUser))
            ->postJson(route('panel.ai-runs.record', $run))
            ->assertOk();

        expect(MedicalRecord::query()->count())->toBe($before + 1)
            ->and(MedicalRecordDocumentation::query()->where('ai_run_id', $run->id)->exists())->toBeTrue();
    });
});
