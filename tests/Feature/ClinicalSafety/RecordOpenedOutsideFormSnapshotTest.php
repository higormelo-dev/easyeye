<?php

declare(strict_types=1);

use App\Domains\AI\Models\AiRun;
use App\Domains\AI\Services\AiCreditWalletService;
use App\Enums\AI\{AiRiskLevel, AiRunMode, AiRunStatus};
use App\Enums\{ClientRule, FeatureKey, SubscriptionStatus};
use App\Enums\ClinicalSafety\{MedicationSource, MedicationStatus};
use App\Models\{Doctor, Entity, MedicalRecord, Patient, PatientAllergy, PatientExam, PatientMedication, People, Plan, PlanFeature, Subscription, User};
use Illuminate\Support\Facades\DB;

/*
 * Fase 2 / G3 — prontuário aberto FORA do salvar do formulário também nasce
 * com o retrato das alergias e medicações ativas do paciente (mesma regra de
 * MedicalRecordService::store): laudo manual do Gerenciador de Imagens com
 * "abrir prontuário" (confirm_open_record) e prontuário aberto para o laudo
 * de IA aprovado (panel.ai-runs.record). Ponto único:
 * ConsultationRecordResolver::openRecord.
 */
beforeEach(function (): void {
    $this->entity = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $plan         = Plan::factory()->create(['active' => true]);

    PlanFeature::factory()->enabled(FeatureKey::HasAiEyeImageAnalysis)->for($plan)->create();
    PlanFeature::factory()->enabled(FeatureKey::HasAiExamAssistant)->for($plan)->create();
    PlanFeature::factory()->limit(FeatureKey::AiMonthlyCredits, 1000)->for($plan)->create();

    Subscription::factory()->create([
        'entity_id' => $this->entity->id, 'plan_id' => $plan->id, 'status' => SubscriptionStatus::Active,
        'starts_at' => now()->subDay(), 'ends_at' => now()->addMonth(),
    ]);

    $this->doctorUser = User::factory()->create();
    $this->doctorEu   = createEntityUser($this->entity, $this->doctorUser, ClientRule::Doctor->value);
    $this->doctor     = Doctor::query()->create([
        'entity_user_id' => $this->doctorEu->id, 'person_id' => People::factory()->create()->id, 'active' => true,
    ]);

    app(AiCreditWalletService::class)->purchaseCredits(entityId: $this->entity->id, amount: 200, description: 'Créditos de teste');

    $this->patient = Patient::factory()->create(['entity_id' => $this->entity->id]);

    ['schedule' => $this->schedule] = createScheduleForEntity($this->entity, ['patient_id' => $this->patient->id, 'date_time' => now()]);
    $this->exam                     = PatientExam::factory()->create(['patient_id' => $this->patient->id, 'schedule_id' => $this->schedule->id, 'laterality' => 1]);

    // Situação clínica do paciente no momento: alergia ativa, alergia
    // descartada (fica fora do retrato), medicação em uso e uma suspensa.
    PatientAllergy::query()->create([
        'entity_id' => $this->entity->id, 'patient_id' => $this->patient->id, 'category' => 'medication',
        'substance' => 'Dipirona', 'reaction' => 'Urticária', 'severity' => 'severe', 'status' => 'confirmed',
    ]);
    PatientAllergy::query()->create([
        'entity_id' => $this->entity->id, 'patient_id' => $this->patient->id, 'category' => 'food',
        'substance' => 'Camarão', 'status' => 'discarded',
    ]);
    PatientMedication::query()->create([
        'entity_id'         => $this->entity->id, 'patient_id' => $this->patient->id, 'name' => 'SECOTEX 0,4 MG',
        'active_ingredient' => 'CLORIDRATO DE TANSULOSINA', 'posology' => '1 cápsula à noite',
        'status'            => MedicationStatus::Active, 'source' => MedicationSource::Patient,
    ]);
    PatientMedication::query()->create([
        'entity_id' => $this->entity->id, 'patient_id' => $this->patient->id, 'name' => 'PREDNISONA',
        'status'    => MedicationStatus::Suspended, 'source' => MedicationSource::Patient,
    ]);
});

function g3fase2AsDoctor(object $test): object
{
    return $test->actingAs($test->doctorUser)->withSession(panelSession($test->doctorEu));
}

function g3fase2AssertSnapshot(MedicalRecord $record): void
{
    expect($record->allergies_snapshot['state'])->toBe('has')
        ->and(collect($record->allergies_snapshot['items'])->pluck('substance')->all())->toBe(['Dipirona'])
        ->and($record->allergies_snapshot['items'][0])->toMatchArray(['severity' => 'severe', 'status' => 'confirmed', 'reaction' => 'Urticária'])
        ->and(collect($record->medications_snapshot)->pluck('name')->all())->toBe(['SECOTEX 0,4 MG'])
        ->and($record->medications_snapshot[0])->toMatchArray(['active_ingredient' => 'CLORIDRATO DE TANSULOSINA', 'posology' => '1 cápsula à noite']);
}

it('laudo manual do Gerenciador de Imagens com "abrir prontuário": o prontuário nasce com o retrato', function (): void {
    $res = g3fase2AsDoctor($this)->postJson(route('panel.eye-images.reports.store'), [
        'patient_id'          => $this->patient->id,
        'exam_ids'            => [$this->exam->id],
        'content'             => '<p>Achados normais.</p>',
        'confirm_open_record' => true,
    ])->assertCreated();

    $record = MedicalRecord::query()->findOrFail($res->json('medical_record_id'));

    expect($record->schedule_id)->toBe($this->schedule->id)
        ->and($record->doctor_id)->toBe($this->doctor->id);
    g3fase2AssertSnapshot($record);
});

it('paciente que nega alergias: o retrato do prontuário aberto pelo laudo guarda "nega"', function (): void {
    PatientAllergy::query()->delete();
    $this->patient->forceFill(['allergies_denied_at' => now(), 'allergies_denied_by' => $this->doctorUser->id])->save();

    $res = g3fase2AsDoctor($this)->postJson(route('panel.eye-images.reports.store'), [
        'patient_id' => $this->patient->id, 'exam_ids' => [$this->exam->id], 'content' => '<p>Ok.</p>', 'confirm_open_record' => true,
    ])->assertCreated();

    expect(MedicalRecord::query()->findOrFail($res->json('medical_record_id'))->allergies_snapshot)
        ->toMatchArray(['state' => 'denied', 'items' => []]);
});

it('prontuário aberto para o laudo de IA aprovado nasce com o retrato', function (): void {
    $run = AiRun::query()->create([
        'entity_id'         => $this->entity->id,
        'patient_id'        => $this->patient->id,
        'requested_by'      => $this->doctorUser->id,
        'workflow'          => 'eye_image_analysis',
        'mode'              => AiRunMode::Economy->value,
        'risk_level'        => AiRiskLevel::Medium->value,
        'status'            => AiRunStatus::WaitingApproval->value,
        'estimated_credits' => 10,
        'reserved_credits'  => 10,
        'consumed_credits'  => 8,
        'final_output'      => 'Achados compatíveis com retinopatia leve.',
        'input_summary'     => ['exam_ids' => [(string) $this->exam->id]],
    ]);
    DB::table('ai_run_patient_exam')->insert([
        'ai_run_id' => $run->id, 'patient_exam_id' => $this->exam->id, 'entity_id' => $this->entity->id, 'created_at' => now(),
    ]);

    g3fase2AsDoctor($this)
        ->postJson(route('panel.ai-runs.approve', $run), ['final_output' => $run->final_output])
        ->assertOk()
        ->assertJsonPath('requires_record_confirmation', true);

    expect(MedicalRecord::query()->where('patient_id', $this->patient->id)->count())->toBe(0);

    g3fase2AsDoctor($this)->postJson(route('panel.ai-runs.record', $run))->assertOk();

    $record = MedicalRecord::query()->where('patient_id', $this->patient->id)->sole();

    expect($record->schedule_id)->toBe($this->schedule->id);
    g3fase2AssertSnapshot($record);
});
