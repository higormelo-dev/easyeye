<?php

use App\Domains\AI\Services\AiPayloadEnricher;
use App\Enums\{ClientRule, FeatureKey, SubscriptionStatus};
use App\Models\{Doctor, Entity, MedicalRecord, Patient, PatientExam, People, Plan, PlanFeature, Subscription, User};
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    $this->entity = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $this->plan   = Plan::factory()->create(['active' => true]);

    PlanFeature::factory()->enabled(FeatureKey::HasAiExamAssistant)->for($this->plan)->create();
    PlanFeature::factory()->enabled(FeatureKey::HasAiEyeImageAnalysis)->for($this->plan)->create();
    PlanFeature::factory()->enabled(FeatureKey::HasAiReportDrafting)->for($this->plan)->create();
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

    $this->patient = Patient::factory()->create(['entity_id' => $this->entity->id]);
    $this->record  = MedicalRecord::query()->create([
        'entity_id'  => $this->entity->id,
        'patient_id' => $this->patient->id,
        'doctor_id'  => $this->doctorModel->id,
    ]);

    $this->enricher = app(AiPayloadEnricher::class);
});

it('força system_prompt e expects_json para record_assist', function () {
    $out = $this->enricher->enrich([
        'workflow'          => 'record_assist',
        'mode'              => 'validated',
        'risk_level'        => 'medium',
        'medical_record_id' => $this->record->id,
        'patient_id'        => $this->patient->id,
        'user_prompt'       => 'Resumir o caso clínico.',
    ], $this->entity->id, false);

    expect($out['system_prompt'])->toBe(__('ai.record_assist_system_prompt'))
        ->and($out['expects_json'])->toBeTrue();
});

it('aborta quando record_assist é chamado sem medical_record_id', function () {
    expect(fn () => $this->enricher->enrich([
        'workflow'    => 'record_assist',
        'mode'        => 'validated',
        'risk_level'  => 'medium',
        'user_prompt' => 'Resumir o caso clínico.',
    ], $this->entity->id, false))->toThrow(HttpException::class);
});

it('força system_prompt e _image_count para eye_image_analysis', function () {
    $exam = PatientExam::factory()->create(['patient_id' => $this->patient->id]);

    $out = $this->enricher->enrich([
        'workflow'    => 'eye_image_analysis',
        'mode'        => 'validated',
        'risk_level'  => 'medium',
        'user_prompt' => 'Avaliar a imagem ocular.',
        'exam_ids'    => [(string) $exam->id],
    ], $this->entity->id, false);

    expect($out['system_prompt'])->toBe(__('ai.eye_image_system_prompt'))
        ->and($out['_image_count'])->toBe(1)
        ->and($out['attachments'])->toBe([]);
});

it('aborta com 403 quando exam_id pertence a outra entidade', function () {
    $otherEntity  = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $otherPatient = Patient::factory()->create(['entity_id' => $otherEntity->id]);
    $otherExam    = PatientExam::factory()->create(['patient_id' => $otherPatient->id]);

    expect(fn () => $this->enricher->enrich([
        'workflow'    => 'eye_image_analysis',
        'mode'        => 'validated',
        'risk_level'  => 'medium',
        'user_prompt' => 'Avaliar a imagem ocular.',
        'exam_ids'    => [(string) $otherExam->id],
    ], $this->entity->id, false))->toThrow(HttpException::class);
});

it('aborta com 403 quando medical_record_id é de outra entidade', function () {
    $otherEntity  = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $otherPatient = Patient::factory()->create(['entity_id' => $otherEntity->id]);
    $otherUser    = User::factory()->create();
    $otherEU      = createEntityUser($otherEntity, $otherUser, ClientRule::Doctor->value);
    $otherDoctor  = Doctor::query()->create([
        'entity_user_id' => $otherEU->id,
        'person_id'      => People::factory()->create()->id,
        'active'         => true,
    ]);
    $otherRecord = MedicalRecord::query()->create([
        'entity_id'  => $otherEntity->id,
        'patient_id' => $otherPatient->id,
        'doctor_id'  => $otherDoctor->id,
    ]);

    expect(fn () => $this->enricher->enrich([
        'workflow'          => 'record_assist',
        'mode'              => 'validated',
        'risk_level'        => 'medium',
        'medical_record_id' => $otherRecord->id,
        'user_prompt'       => 'Resumir o caso clínico.',
    ], $this->entity->id, false))->toThrow(HttpException::class);
});

it('aplica guardrails e devolve flag _guardrails no payload', function () {
    $out = $this->enricher->enrich([
        'workflow'          => 'record_assist',
        'mode'              => 'validated',
        'risk_level'        => 'medium',
        'medical_record_id' => $this->record->id,
        'user_prompt'       => 'CPF do paciente 123.456.789-09 — resumir o caso.',
    ], $this->entity->id, false);

    expect($out)->toHaveKey('_guardrails')
        ->and($out['_guardrails'])->toBeArray();
});
