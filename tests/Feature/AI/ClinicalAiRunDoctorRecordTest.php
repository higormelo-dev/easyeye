<?php

use App\Domains\AI\Models\{AiCreditLedgerEntry, AiCreditWallet, AiRun};
use App\Domains\AI\Services\AiCreditWalletService;
use App\Enums\AI\{AiRiskLevel, AiRunMode, AiRunStatus};
use App\Enums\{ClientRule, FeatureKey, SubscriptionStatus};
use App\Jobs\AI\RunAiWorkflowJob;
use App\Models\{Entity, MedicalRecord, Patient, PatientExam, Plan, PlanFeature, Subscription, User};
use Illuminate\Support\Facades\Queue;

/*
 * Fase 0 — run de IA CLÍNICO pedido por perfil médico SEM cadastro de médico
 * ativo: o servidor barra (403 + mensagem de CurrentDoctor) ANTES de criar o
 * AiRun, reservar crédito ou despachar o job que chama o provedor. É a mesma
 * regra do Gate IssueReport: a resposta nunca poderia ser aprovada.
 */

/**
 * Todos os workflows clínicos aceitos por panel.ai-runs.store.
 *
 * @return list<string>
 */
function clinicalAiGateWorkflows(): array
{
    return ['exam_assistant', 'report_drafting', 'consensus_review', 'eye_image_analysis', 'record_assist', 'assistant_chat'];
}

beforeEach(function () {
    Queue::fake();

    $this->entity = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $this->plan   = Plan::factory()->create(['active' => true]);

    foreach ([
        FeatureKey::HasAiReportDrafting,
        FeatureKey::HasAiExamAssistant,
        FeatureKey::HasAiConsensus,
        FeatureKey::HasAiEyeImageAnalysis,
        FeatureKey::HasAiChatAssistant,
    ] as $feature) {
        PlanFeature::factory()->enabled($feature)->for($this->plan)->create();
    }

    PlanFeature::factory()->limit(FeatureKey::AiMonthlyCredits, 1000)->for($this->plan)->create();

    Subscription::factory()->create([
        'entity_id' => $this->entity->id,
        'plan_id'   => $this->plan->id,
        'status'    => SubscriptionStatus::Active,
        'starts_at' => now()->subDay(),
        'ends_at'   => now()->addMonth(),
    ]);

    app(AiCreditWalletService::class)->purchaseCredits(
        entityId: $this->entity->id,
        amount: 500,
        description: 'Créditos de teste',
    );

    // Perfil médico SEM cadastro de médico.
    $this->orphan     = User::factory()->create();
    $this->orphanUser = createEntityUser($this->entity, $this->orphan, ClientRule::Doctor->value);

    // Perfil médico COM cadastro de médico ativo.
    $this->doctor      = User::factory()->create();
    $this->doctorUser  = createEntityUser($this->entity, $this->doctor, ClientRule::Doctor->value);
    $this->doctorModel = doctorRecordForEntityUser($this->doctorUser);

    $this->patient = Patient::factory()->create(['entity_id' => $this->entity->id]);
    $this->record  = MedicalRecord::query()->create([
        'entity_id'  => $this->entity->id,
        'patient_id' => $this->patient->id,
        'doctor_id'  => $this->doctorModel->id,
    ]);
    $this->exam = PatientExam::factory()->create(['patient_id' => $this->patient->id, 'laterality' => 1]);
});

/**
 * Payload válido de store para cada workflow clínico (o mesmo que a UI manda).
 *
 * @return array<string, mixed>
 */
function clinicalAiGateStorePayload($test, string $workflow): array
{
    $payload = [
        'workflow'    => $workflow,
        'mode'        => AiRunMode::Validated->value,
        'risk_level'  => AiRiskLevel::Medium->value,
        'user_prompt' => 'Resumir o caso clínico e listar hipóteses diagnósticas para revisão.',
    ];

    return match ($workflow) {
        'consensus_review'   => [...$payload, 'mode' => AiRunMode::Consensus->value],
        'eye_image_analysis' => [...$payload, 'patient_id' => $test->patient->id, 'exam_ids' => [$test->exam->id]],
        'record_assist'      => [...$payload, 'patient_id' => $test->patient->id, 'medical_record_id' => $test->record->id],
        'exam_assistant'     => [...$payload, 'patient_id' => $test->patient->id],
        default              => $payload,
    };
}

/**
 * Run terminal (Economy, aprovado) do workflow — ponto de partida do escalate.
 */
function clinicalAiGateParentRun($test, string $workflow, User $requestedBy): AiRun
{
    return AiRun::query()->create([
        'entity_id'         => $test->entity->id,
        'patient_id'        => $test->patient->id,
        'medical_record_id' => $workflow === 'record_assist' ? $test->record->id : null,
        'requested_by'      => $requestedBy->id,
        'workflow'          => $workflow,
        'mode'              => AiRunMode::Economy->value,
        'risk_level'        => AiRiskLevel::Medium->value,
        'status'            => AiRunStatus::Approved->value,
        'estimated_credits' => 30,
        'reserved_credits'  => 30,
        'consumed_credits'  => 25,
        'input_summary'     => [
            'user_prompt'  => 'Resumir o caso clínico do paciente em consulta.',
            'context'      => [],
            'attachments'  => [],
            'exam_ids'     => $workflow === 'eye_image_analysis' ? [(string) $test->exam->id] : [],
            'expects_json' => $workflow === 'record_assist',
        ],
        'final_output' => 'Resposta anterior.',
    ]);
}

/**
 * Estado da carteira + livro-razão: nada pode mudar quando o run é barrado.
 *
 * @return array<string, int>
 */
function clinicalAiGateWalletSnapshot(string $entityId): array
{
    $wallet = AiCreditWallet::query()->where('entity_id', $entityId)->firstOrFail();

    return [
        'balance'            => (int) $wallet->balance,
        'reserved_balance'   => (int) $wallet->reserved_balance,
        'monthly_quota_used' => (int) $wallet->monthly_quota_used,
        'ledger_entries'     => AiCreditLedgerEntry::query()->where('entity_id', $entityId)->count(),
    ];
}

describe('store: perfil médico sem cadastro de médico ativo', function () {
    it('recusa o workflow clínico sem criar AiRun nem reservar crédito', function (string $workflow) {
        $wallet = clinicalAiGateWalletSnapshot($this->entity->id);

        $this->actingAs($this->orphan)
            ->withSession(panelSession($this->orphanUser))
            ->postJson(route('panel.ai-runs.store'), clinicalAiGateStorePayload($this, $workflow))
            ->assertForbidden()
            ->assertJsonPath('message', __('schedules.doctor_missing'));

        expect(AiRun::query()->count())->toBe(0)
            ->and(clinicalAiGateWalletSnapshot($this->entity->id))->toBe($wallet);

        Queue::assertNotPushed(RunAiWorkflowJob::class);
    })->with(clinicalAiGateWorkflows());

    it('recusa também quando o cadastro de médico existe mas está inativo', function () {
        $this->doctorModel->update(['active' => false]);
        $wallet = clinicalAiGateWalletSnapshot($this->entity->id);

        $this->actingAs($this->doctor)
            ->withSession(panelSession($this->doctorUser))
            ->postJson(route('panel.ai-runs.store'), clinicalAiGateStorePayload($this, 'report_drafting'))
            ->assertForbidden()
            ->assertJsonPath('message', __('schedules.doctor_missing'));

        expect(AiRun::query()->count())->toBe(0)
            ->and(clinicalAiGateWalletSnapshot($this->entity->id))->toBe($wallet);

        Queue::assertNotPushed(RunAiWorkflowJob::class);
    });

    it('a estimativa (pré-envio) devolve a mesma recusa', function () {
        $this->actingAs($this->orphan)
            ->withSession(panelSession($this->orphanUser))
            ->postJson(route('panel.ai-runs.estimate'), clinicalAiGateStorePayload($this, 'record_assist'))
            ->assertForbidden()
            ->assertJsonPath('message', __('schedules.doctor_missing'));
    });

    it('usa a mensagem traduzida em inglês', function () {
        $this->actingAs($this->orphan)
            ->withSession([...panelSession($this->orphanUser), 'locale' => 'en'])
            ->postJson(route('panel.ai-runs.store'), clinicalAiGateStorePayload($this, 'report_drafting'))
            ->assertForbidden()
            ->assertJsonPath('message', trans('schedules.doctor_missing', [], 'en'));

        expect(trans('schedules.doctor_missing', [], 'en'))->not->toBe(trans('schedules.doctor_missing', [], 'pt_BR'));
    });
});

describe('store: médico com cadastro ativo segue funcionando', function () {
    it('cria o run reservado e despacha o job', function (string $workflow) {
        $wallet = clinicalAiGateWalletSnapshot($this->entity->id);

        $this->actingAs($this->doctor)
            ->withSession(panelSession($this->doctorUser))
            ->postJson(route('panel.ai-runs.store'), clinicalAiGateStorePayload($this, $workflow))
            ->assertCreated()
            ->assertJsonPath('status', AiRunStatus::Reserved->value);

        $run = AiRun::query()->sole();

        expect($run->workflow)->toBe($workflow)
            ->and((string) $run->requested_by)->toBe((string) $this->doctor->id)
            ->and($run->reserved_credits)->toBeGreaterThan(0)
            ->and(clinicalAiGateWalletSnapshot($this->entity->id)['ledger_entries'])->toBeGreaterThan($wallet['ledger_entries']);

        Queue::assertPushed(RunAiWorkflowJob::class, 1);
    })->with(clinicalAiGateWorkflows());
});

describe('escalate', function () {
    it('recusa perfil médico sem cadastro de médico ativo: nenhum run novo nem crédito reservado', function (string $workflow) {
        $parent = clinicalAiGateParentRun($this, $workflow, $this->doctor);
        $wallet = clinicalAiGateWalletSnapshot($this->entity->id);

        $this->actingAs($this->orphan)
            ->withSession(panelSession($this->orphanUser))
            ->postJson(route('panel.ai-runs.escalate', $parent))
            ->assertForbidden()
            ->assertJsonPath('message', __('schedules.doctor_missing'));

        expect(AiRun::query()->count())->toBe(1)
            ->and(AiRun::query()->where('parent_run_id', $parent->id)->exists())->toBeFalse()
            ->and(clinicalAiGateWalletSnapshot($this->entity->id))->toBe($wallet);

        Queue::assertNotPushed(RunAiWorkflowJob::class);
    })->with(clinicalAiGateWorkflows());

    it('médico com cadastro escala normalmente', function (string $workflow) {
        $parent = clinicalAiGateParentRun($this, $workflow, $this->doctor);

        $this->actingAs($this->doctor)
            ->withSession(panelSession($this->doctorUser))
            ->postJson(route('panel.ai-runs.escalate', $parent))
            ->assertCreated();

        $child = AiRun::query()->where('parent_run_id', $parent->id)->sole();

        expect($child->mode)->toBe(AiRunMode::Validated)
            ->and($child->status)->toBe(AiRunStatus::Reserved);

        Queue::assertPushed(RunAiWorkflowJob::class, 1);
    })->with(['exam_assistant', 'report_drafting', 'eye_image_analysis', 'record_assist', 'assistant_chat']);
});

describe('fora da trava', function () {
    it('admin (perfil não médico) segue criando run de laudo como antes', function () {
        $admin     = User::factory()->create();
        $adminUser = createEntityUser($this->entity, $admin, ClientRule::Admin->value);

        $this->actingAs($admin)
            ->withSession(panelSession($adminUser))
            ->postJson(route('panel.ai-runs.store'), clinicalAiGateStorePayload($this, 'report_drafting'))
            ->assertCreated();

        expect(AiRun::query()->count())->toBe(1);
    });
});
