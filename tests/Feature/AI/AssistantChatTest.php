<?php

use App\Domains\AI\Models\AiRun;
use App\Domains\AI\Services\AiCreditWalletService;
use App\Enums\AI\{AiRiskLevel, AiRunMode, AiRunStatus};
use App\Enums\{ClientRule, DataAccessPurpose, FeatureKey, SubscriptionStatus};
use App\Models\{DataAccessLog, Doctor, Entity, MedicalRecord, MedicalRecordDocumentation, Patient, People, Plan, PlanFeature, Subscription, User};
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/**
 * Assistente Virtual flutuante — workflow=assistant_chat.
 *
 * Reaproveita o backbone AiRun (créditos/orquestração/auditoria), mas com
 * regras próprias: gate por FeatureKey::HasAiChatAssistant, doctor-only
 * (defesa em profundidade além do gate de approve()), sem exigir
 * medical_record_id, e NUNCA grava a resposta como documentação clínica
 * mesmo quando aprovada com patient_id/medical_record_id em contexto.
 */
beforeEach(function () {
    $this->entity = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $this->plan   = Plan::factory()->create(['active' => true]);

    PlanFeature::factory()->enabled(FeatureKey::HasAiChatAssistant)->for($this->plan)->create();
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

function storeAssistantChat($test, array $payload = [])
{
    return $test->actingAs($test->doctor)
        ->withSession(panelSession($test->doctorUser))
        // Modo 'validated' (não 'economy'): com os 3 provedores default
        // habilitados (config/ai.php), AiProviderSettings::availableModes()
        // exclui Economy — mesma regra que RecordAssistTest usa.
        ->postJson(route('panel.ai-runs.store'), array_merge([
            'workflow'    => 'assistant_chat',
            'mode'        => 'validated',
            'risk_level'  => 'low',
            'user_prompt' => 'Quais são as opções de tratamento para conjuntivite alérgica?',
        ], $payload));
}

describe('store assistant_chat', function () {
    it('cria o run com o system prompt de apoio à decisão, sem exigir prontuário', function () {
        Queue::fake();
        storeAssistantChat($this)->assertStatus(201);

        $run = AiRun::query()->firstOrFail();
        expect($run->workflow)->toBe('assistant_chat')
            ->and($run->medical_record_id)->toBeNull()
            ->and($run->input_summary['expects_json'])->toBeFalse()
            ->and($run->input_summary['system_prompt'])->toBe(__('ai.assistant_chat_system_prompt'))
            ->and($run->input_summary['system_prompt'])->toContain('validad')
            ->and($run->input_summary['system_prompt'])->toContain('APOIO À DECISÃO');
    });

    it('persiste conversation_id quando enviado pelo cliente', function () {
        Queue::fake();
        $conversationId = (string) Str::uuid();

        storeAssistantChat($this, ['conversation_id' => $conversationId])->assertStatus(201);

        expect(AiRun::query()->firstOrFail()->conversation_id)->toBe($conversationId);
    });

    it('bloqueia quando o plano não tem o assistente de chat', function () {
        Queue::fake();
        PlanFeature::query()->where('plan_id', $this->plan->id)
            ->where('feature', FeatureKey::HasAiChatAssistant->value)->update(['value' => '0']);

        storeAssistantChat($this)->assertStatus(403);
        expect(AiRun::query()->count())->toBe(0);
    });

    it('[SEGURANÇA] secretária não consegue criar run de chat mesmo com o plano habilitado', function () {
        Queue::fake();
        $secretary     = User::factory()->create();
        $secretaryUser = createEntityUser($this->entity, $secretary, ClientRule::Secretary->value);

        $this->actingAs($secretary)
            ->withSession(panelSession($secretaryUser))
            ->postJson(route('panel.ai-runs.store'), [
                'workflow'    => 'assistant_chat',
                'mode'        => 'validated',
                'risk_level'  => 'low',
                'user_prompt' => 'Quais são as opções de tratamento para conjuntivite alérgica?',
            ])
            ->assertStatus(403);

        // Nenhum crédito reservado — bloqueado ANTES da reserva, não depois.
        expect(AiRun::query()->count())->toBe(0);
    });

    it('grava data_access_log quando patient_id é enviado (contexto autorizado)', function () {
        Queue::fake();
        expect(DataAccessLog::count())->toBe(0);

        storeAssistantChat($this, [
            'patient_id'        => $this->patient->id,
            'medical_record_id' => $this->record->id,
        ])->assertStatus(201);

        $log = DataAccessLog::query()->first();
        expect($log)->not->toBeNull()
            ->and((string) $log->patient_id)->toBe((string) $this->patient->id)
            ->and($log->purpose)->toBe(DataAccessPurpose::PatientCare);
    });

    it('sem patient_id: não grava data_access_log (pergunta genérica, sem dado de paciente)', function () {
        Queue::fake();
        storeAssistantChat($this)->assertStatus(201);

        expect(DataAccessLog::count())->toBe(0);
    });

    it('inclui histórico da mesma conversa no contexto da segunda mensagem', function () {
        Queue::fake();
        $conversationId = (string) Str::uuid();

        // Primeira "resposta" simulada já aprovada (turno anterior da conversa).
        AiRun::query()->create([
            'entity_id'         => $this->entity->id,
            'requested_by'      => $this->doctor->id,
            'workflow'          => 'assistant_chat',
            'mode'              => AiRunMode::Economy->value,
            'risk_level'        => AiRiskLevel::Low->value,
            'conversation_id'   => $conversationId,
            'status'            => AiRunStatus::Approved->value,
            'estimated_credits' => 1,
            'reserved_credits'  => 1,
            'consumed_credits'  => 1,
            'final_output'      => 'A dose usual de referência é X, mas confirme na bula.',
            'input_summary'     => ['user_prompt' => 'Qual a dose usual de X?'],
        ]);

        storeAssistantChat($this, [
            'conversation_id' => $conversationId,
            'user_prompt'     => 'E em caso de insuficiência renal, muda algo?',
        ])->assertStatus(201);

        // Ordena por id (UUID ordenado/HasUuids) — created_at pode colidir no
        // mesmo segundo entre o run "prior" simulado e o novo desta chamada.
        $newRun  = AiRun::query()->where('conversation_id', $conversationId)->orderByDesc('id')->firstOrFail();
        $history = $newRun->input_summary['context']['conversation_history'] ?? null;

        expect($history)->not->toBeNull()
            ->and($history)->toHaveCount(2)
            ->and($history[0])->toBe(['role' => 'user', 'content' => 'Qual a dose usual de X?'])
            ->and($history[1]['role'])->toBe('assistant');
    });
});

describe('aprovação assistant_chat', function () {
    it('[CRÍTICO] NUNCA grava a resposta do chat como documentação no prontuário, mesmo com patient_id + medical_record_id em contexto', function () {
        $run = AiRun::query()->create([
            'entity_id'         => $this->entity->id,
            'patient_id'        => $this->patient->id,
            'medical_record_id' => $this->record->id,
            'requested_by'      => $this->doctor->id,
            'workflow'          => 'assistant_chat',
            'mode'              => AiRunMode::Economy->value,
            'risk_level'        => AiRiskLevel::Low->value,
            'status'            => AiRunStatus::WaitingApproval->value,
            'estimated_credits' => 1,
            'reserved_credits'  => 1,
            'consumed_credits'  => 1,
            'final_output'      => 'Esquema usual é X, considerar Y. Validar com o médico responsável.',
        ]);

        $response = $this->actingAs($this->doctor)
            ->withSession(panelSession($this->doctorUser))
            ->postJson(route('panel.ai-runs.approve', $run), [])
            ->assertOk();

        // O run é aprovado normalmente (crédito consumido, auditoria preservada)...
        expect($run->fresh()->status)->toBe(AiRunStatus::Approved);
        // ...mas NADA é escrito em medical_record_documentations.
        expect(MedicalRecordDocumentation::query()->where('ai_run_id', $run->id)->exists())->toBeFalse();
        $response->assertJsonPath('requires_record_confirmation', false);
    });

    it('secretária não consegue aprovar (mesmo gate doctor-only das outras respostas de IA)', function () {
        $secretary     = User::factory()->create();
        $secretaryUser = createEntityUser($this->entity, $secretary, ClientRule::Secretary->value);

        $run = AiRun::query()->create([
            'entity_id'         => $this->entity->id,
            'requested_by'      => $secretary->id,
            'workflow'          => 'assistant_chat',
            'mode'              => AiRunMode::Economy->value,
            'risk_level'        => AiRiskLevel::Low->value,
            'status'            => AiRunStatus::WaitingApproval->value,
            'estimated_credits' => 1,
            'reserved_credits'  => 1,
            'consumed_credits'  => 1,
            'final_output'      => 'resposta',
        ]);

        $this->actingAs($secretary)
            ->withSession(panelSession($secretaryUser))
            ->postJson(route('panel.ai-runs.approve', $run), [])
            ->assertForbidden();
    });
});
