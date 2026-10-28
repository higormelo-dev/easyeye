<?php

use App\Domains\AI\Models\AiRun;
use App\Domains\AI\Services\AiCreditWalletService;
use App\Enums\AI\{AiRiskLevel, AiRunMode, AiRunStatus};
use App\Enums\{ClientRule, FeatureKey, SubscriptionStatus};
use App\Models\{Doctor, Entity, MedicalRecord, MedicalRecordDocumentation, Patient, People, Plan, PlanFeature, Subscription, User};
use Illuminate\Support\Facades\Queue;

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
});

function storeRecordAssist($test, array $payload = [])
{
    return $test->actingAs($test->doctor)
        ->withSession(panelSession($test->doctorUser))
        ->postJson(route('panel.ai-runs.store'), array_merge([
            'workflow'          => 'record_assist',
            'mode'              => 'validated',
            'risk_level'        => 'medium',
            'patient_id'        => $test->patient->id,
            'medical_record_id' => $test->record->id,
            'user_prompt'       => 'Resumir o caso e listar hipóteses diagnósticas.',
        ], $payload));
}

describe('store record_assist', function () {
    it('força expects_json e o system prompt server-side', function () {
        Queue::fake();
        storeRecordAssist($this)->assertStatus(201);

        $run = AiRun::query()->firstOrFail();
        expect($run->workflow)->toBe('record_assist')
            ->and($run->input_summary['expects_json'])->toBeTrue()
            ->and($run->input_summary['system_prompt'])->toContain(__('ai.record_assist_system_prompt'))
            ->and($run->input_summary['system_prompt'])->toContain(__('ai.security_preamble'));
    });

    it('[SEGURANÇA] rejeita attachments não-vazios enviados pelo cliente (422)', function () {
        Queue::fake();

        storeRecordAssist($this, [
            'attachments' => [['image_url' => 'https://attacker.example/injected.png']],
        ])->assertStatus(422)->assertJsonValidationErrors(['attachments']);

        expect(AiRun::query()->count())->toBe(0);
    });

    it('exige um prontuário em contexto', function () {
        Queue::fake();
        storeRecordAssist($this, ['medical_record_id' => null])->assertStatus(422);

        expect(AiRun::query()->count())->toBe(0);
    });

    it('bloqueia quando o plano não tem assistente de exame', function () {
        Queue::fake();
        PlanFeature::query()->where('plan_id', $this->plan->id)
            ->where('feature', FeatureKey::HasAiExamAssistant->value)->update(['value' => '0']);

        storeRecordAssist($this)->assertStatus(403);
    });
});

describe('aprovação record_assist', function () {
    it('grava a documentação (summary) no prontuário do run', function () {
        $run = AiRun::query()->create([
            'entity_id'         => $this->entity->id,
            'patient_id'        => $this->patient->id,
            'medical_record_id' => $this->record->id,
            'requested_by'      => $this->doctor->id,
            'workflow'          => 'record_assist',
            'mode'              => AiRunMode::Validated->value,
            'risk_level'        => AiRiskLevel::Medium->value,
            'status'            => AiRunStatus::WaitingApproval->value,
            'estimated_credits' => 10,
            'reserved_credits'  => 10,
            'consumed_credits'  => 8,
            'final_output'      => '{"summary":"Caso compatível com...","suggestions":{}}',
        ]);

        $this->actingAs($this->doctor)
            ->withSession(panelSession($this->doctorUser))
            ->postJson(route('panel.ai-runs.approve', $run), ['final_output' => 'Caso compatível com glaucoma incipiente.'])
            ->assertOk();

        $doc = MedicalRecordDocumentation::query()->where('ai_run_id', $run->id)->first();
        expect($doc)->not->toBeNull()
            ->and($doc->medical_record_id)->toBe($this->record->id)
            ->and($doc->content)->toContain('glaucoma incipiente');
    });
});
