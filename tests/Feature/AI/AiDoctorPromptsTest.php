<?php

use App\Domains\AI\Models\AiDoctorPrompt;
use App\Enums\{ClientRule, FeatureKey, SubscriptionStatus};
use App\Models\{Doctor, Entity, People, Plan, PlanFeature, Subscription, User};

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
});

it('lista prompts vazios para o médico que ainda não criou nenhum', function () {
    $resp = $this->actingAs($this->doctor)
        ->withSession(panelSession($this->doctorUser))
        ->get(route('panel.setting.ai-prompts.index'));

    $resp->assertOk();
});

it('cria um prompt para o próprio médico', function () {
    $this->actingAs($this->doctor)
        ->withSession(panelSession($this->doctorUser))
        ->postJson(route('panel.setting.ai-prompts.store'), [
            'label'  => 'Consulta de retorno',
            'prompt' => 'Resumir o caso clínico de paciente em consulta de retorno após 30 dias.',
        ])
        ->assertStatus(201);

    expect(AiDoctorPrompt::query()->count())->toBe(1);
    $created = AiDoctorPrompt::query()->first();
    expect((string) $created->doctor_id)->toBe((string) $this->doctorModel->id)
        ->and((string) $created->entity_id)->toBe((string) $this->entity->id)
        ->and($created->label)->toBe('Consulta de retorno');
});

it('bloqueia criação além do limite de 5 prompts', function () {
    for ($i = 1; $i <= 5; $i++) {
        AiDoctorPrompt::query()->create([
            'doctor_id' => $this->doctorModel->id,
            'entity_id' => $this->entity->id,
            'label'     => "Prompt {$i}",
            'prompt'    => "Texto do prompt número {$i}, com mais de 12 caracteres.",
            'position'  => $i - 1,
        ]);
    }

    $this->actingAs($this->doctor)
        ->withSession(panelSession($this->doctorUser))
        ->postJson(route('panel.setting.ai-prompts.store'), [
            'label'  => 'Sexto prompt',
            'prompt' => 'Tentativa de criar o sexto prompt, deve falhar com 422.',
        ])
        ->assertStatus(422);

    expect(AiDoctorPrompt::query()->count())->toBe(5);
});

it('atualiza um prompt do próprio médico', function () {
    $prompt = AiDoctorPrompt::query()->create([
        'doctor_id' => $this->doctorModel->id,
        'entity_id' => $this->entity->id,
        'label'     => 'Original',
        'prompt'    => 'Texto original do prompt, com tamanho suficiente.',
        'position'  => 0,
    ]);

    $this->actingAs($this->doctor)
        ->withSession(panelSession($this->doctorUser))
        ->patchJson(route('panel.setting.ai-prompts.update', $prompt), [
            'label'  => 'Atualizado',
            'prompt' => 'Texto atualizado do prompt, com tamanho suficiente.',
        ])
        ->assertOk();

    expect($prompt->fresh()->label)->toBe('Atualizado');
});

it('bloqueia atualização de prompt de outro médico (cross-doctor)', function () {
    $otherDoctorUserMember = createEntityUser($this->entity, User::factory()->create(), ClientRule::Doctor->value);
    $otherDoctor           = Doctor::query()->create([
        'entity_user_id' => $otherDoctorUserMember->id,
        'person_id'      => People::factory()->create()->id,
        'active'         => true,
    ]);

    $prompt = AiDoctorPrompt::query()->create([
        'doctor_id' => $otherDoctor->id,
        'entity_id' => $this->entity->id,
        'label'     => 'Prompt do outro médico',
        'prompt'    => 'Texto que o usuário corrente NÃO deve conseguir editar.',
        'position'  => 0,
    ]);

    $this->actingAs($this->doctor)
        ->withSession(panelSession($this->doctorUser))
        ->patchJson(route('panel.setting.ai-prompts.update', $prompt), [
            'label'  => 'Tentando alterar',
            'prompt' => 'Tentativa de alterar prompt de outro médico.',
        ])
        ->assertStatus(403);
});

it('bloqueia atualização cross-tenant', function () {
    $otherEntity = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $otherUser   = User::factory()->create();
    $otherEU     = createEntityUser($otherEntity, $otherUser, ClientRule::Doctor->value);
    $otherDoc    = Doctor::query()->create([
        'entity_user_id' => $otherEU->id,
        'person_id'      => People::factory()->create()->id,
        'active'         => true,
    ]);

    $prompt = AiDoctorPrompt::query()->create([
        'doctor_id' => $otherDoc->id,
        'entity_id' => $otherEntity->id,
        'label'     => 'Outra entity',
        'prompt'    => 'Prompt de uma entity diferente.',
        'position'  => 0,
    ]);

    $this->actingAs($this->doctor)
        ->withSession(panelSession($this->doctorUser))
        ->patchJson(route('panel.setting.ai-prompts.update', $prompt), [
            'label'  => 'tentativa',
            'prompt' => 'tentativa de editar entre entities.',
        ])
        ->assertStatus(403);
});

it('exclui um prompt do próprio médico e reorganiza posições', function () {
    $p1 = AiDoctorPrompt::query()->create([
        'doctor_id' => $this->doctorModel->id,
        'entity_id' => $this->entity->id,
        'label'     => 'A',
        'prompt'    => 'Prompt A com tamanho suficiente para validação.',
        'position'  => 0,
    ]);
    $p2 = AiDoctorPrompt::query()->create([
        'doctor_id' => $this->doctorModel->id,
        'entity_id' => $this->entity->id,
        'label'     => 'B',
        'prompt'    => 'Prompt B com tamanho suficiente para validação.',
        'position'  => 1,
    ]);
    $p3 = AiDoctorPrompt::query()->create([
        'doctor_id' => $this->doctorModel->id,
        'entity_id' => $this->entity->id,
        'label'     => 'C',
        'prompt'    => 'Prompt C com tamanho suficiente para validação.',
        'position'  => 2,
    ]);

    $this->actingAs($this->doctor)
        ->withSession(panelSession($this->doctorUser))
        ->deleteJson(route('panel.setting.ai-prompts.destroy', $p2))
        ->assertOk();

    expect($p1->fresh()->position)->toBe(0)
        ->and($p3->fresh()->position)->toBe(1);
});

it('reordena prompts conforme array de ids enviado', function () {
    $p1 = AiDoctorPrompt::query()->create([
        'doctor_id' => $this->doctorModel->id, 'entity_id' => $this->entity->id,
        'label'     => 'A', 'prompt' => 'aaaaaaaaaaaaa', 'position' => 0,
    ]);
    $p2 = AiDoctorPrompt::query()->create([
        'doctor_id' => $this->doctorModel->id, 'entity_id' => $this->entity->id,
        'label'     => 'B', 'prompt' => 'bbbbbbbbbbbbb', 'position' => 1,
    ]);
    $p3 = AiDoctorPrompt::query()->create([
        'doctor_id' => $this->doctorModel->id, 'entity_id' => $this->entity->id,
        'label'     => 'C', 'prompt' => 'ccccccccccccc', 'position' => 2,
    ]);

    $this->actingAs($this->doctor)
        ->withSession(panelSession($this->doctorUser))
        ->postJson(route('panel.setting.ai-prompts.reorder'), [
            'ids' => [(string) $p3->id, (string) $p1->id, (string) $p2->id],
        ])
        ->assertOk();

    expect($p3->fresh()->position)->toBe(0)
        ->and($p1->fresh()->position)->toBe(1)
        ->and($p2->fresh()->position)->toBe(2);
});

it('bloqueia acesso de admin sem perfil médico', function () {
    $admin   = User::factory()->create();
    $adminEU = createEntityUser($this->entity, $admin, ClientRule::Admin->value);

    $this->actingAs($admin)
        ->withSession(panelSession($adminEU))
        ->postJson(route('panel.setting.ai-prompts.store'), [
            'label'  => 'tentativa',
            'prompt' => 'admin não tem perfil médico, deve receber 403.',
        ])
        ->assertStatus(403);
});
