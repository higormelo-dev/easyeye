<?php

use App\Domains\AI\Models\AiRun;
use App\Domains\AI\Services\AiCreditWalletService;
use App\Enums\{ClientRule, FeatureKey, SubscriptionStatus};
use App\Models\{Entity, Patient, PatientExam, Plan, PlanFeature, Subscription, User};
use Illuminate\Support\Facades\{DB, Queue};

beforeEach(function () {
    Queue::fake(); // não executa o job — testamos só a criação do run + vínculo

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

    $this->admin      = User::factory()->create();
    $this->entityUser = createEntityUser($this->entity, $this->admin, ClientRule::Admin->value);

    app(AiCreditWalletService::class)->purchaseCredits(
        entityId: $this->entity->id,
        amount: 500,
        description: 'Créditos de teste',
    );

    $this->patient = Patient::factory()->create(['entity_id' => $this->entity->id]);
    $this->exam    = PatientExam::factory()->create(['patient_id' => $this->patient->id, 'laterality' => 1]);
});

function postEyeImageRun($test, array $payload = [])
{
    return $test->actingAs($test->admin)
        ->withSession(panelSession($test->entityUser))
        ->postJson(route('panel.ai-runs.store'), array_merge([
            'workflow'    => 'eye_image_analysis',
            'mode'        => 'validated',
            'risk_level'  => 'medium',
            'user_prompt' => 'Analisar as imagens oculares e descrever os achados por estrutura.',
            'exam_ids'    => [$test->exam->id],
        ], $payload));
}

describe('store eye_image_analysis', function () {
    it('cria o run, vincula os exames e guarda exam_ids (sem base64)', function () {
        postEyeImageRun($this)->assertStatus(201);

        $run = AiRun::query()->firstOrFail();

        expect($run->workflow)->toBe('eye_image_analysis')
            ->and($run->input_summary['exam_ids'])->toBe([(string) $this->exam->id])
            ->and($run->input_summary['attachments'])->toBe([]); // base64 só na execução

        expect(DB::table('ai_run_patient_exam')
            ->where('ai_run_id', $run->id)
            ->where('patient_exam_id', $this->exam->id)
            ->where('entity_id', $this->entity->id)
            ->exists())->toBeTrue();
    });

    it('exige ao menos um exame', function () {
        postEyeImageRun($this, ['exam_ids' => []])->assertStatus(422);

        expect(AiRun::query()->count())->toBe(0);
    });

    it('bloqueia exame de outra entidade (cross-tenant)', function () {
        $otherEntity  = Entity::factory()->create(['is_client' => true, 'active' => true]);
        $otherPatient = Patient::factory()->create(['entity_id' => $otherEntity->id]);
        $otherExam    = PatientExam::factory()->create(['patient_id' => $otherPatient->id]);

        postEyeImageRun($this, ['exam_ids' => [$otherExam->id]])->assertStatus(403);

        expect(AiRun::query()->count())->toBe(0);
    });
});
