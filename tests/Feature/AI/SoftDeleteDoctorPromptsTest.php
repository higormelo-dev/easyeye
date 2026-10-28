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

function makePrompt($test, int $position = 0): AiDoctorPrompt
{
    return AiDoctorPrompt::query()->create([
        'doctor_id' => $test->doctorModel->id,
        'entity_id' => $test->entity->id,
        'label'     => 'Template P' . $position,
        'prompt'    => 'Conteúdo do template suficiente para validação.',
        'position'  => $position,
    ]);
}

it('destroy marca deleted_at e mantém o registro no banco', function () {
    $prompt = makePrompt($this);

    $this->actingAs($this->doctor)
        ->withSession(panelSession($this->doctorUser))
        ->deleteJson(route('panel.setting.ai-prompts.destroy', $prompt))
        ->assertOk();

    // Eloquent padrão filtra deleted_at
    expect(AiDoctorPrompt::query()->find($prompt->id))->toBeNull();
    // Registro permanece com deleted_at preenchido
    $raw = AiDoctorPrompt::withTrashed()->find($prompt->id);
    expect($raw)->not->toBeNull()
        ->and($raw->deleted_at)->not->toBeNull();
});

it('index não retorna prompts soft-deleted', function () {
    $kept    = makePrompt($this, 0);
    $deleted = makePrompt($this, 1);
    $deleted->delete();

    $this->actingAs($this->doctor)
        ->withSession(panelSession($this->doctorUser))
        ->get(route('panel.setting.ai-prompts.index'))
        ->assertOk();

    // Estado direto do DB: apenas o vivo aparece em listagem padrão
    expect(AiDoctorPrompt::query()->where('doctor_id', $this->doctorModel->id)->count())->toBe(1)
        ->and(AiDoctorPrompt::query()->first()->id)->toBe($kept->id);
});

it('permite criar novo prompt mesmo após delete (limite 5 considera apenas vivos)', function () {
    // 5 prompts criados, 1 apagado → posso criar 1 novo (4 vivos + 1)
    foreach (range(0, 4) as $i) {
        $p = makePrompt($this, $i);

        if ($i === 2) {
            $p->delete();
        }
    }

    $this->actingAs($this->doctor)
        ->withSession(panelSession($this->doctorUser))
        ->postJson(route('panel.setting.ai-prompts.store'), [
            'label'  => 'Novo após delete',
            'prompt' => 'Conteúdo para o sexto template após delete de um.',
        ])
        ->assertStatus(201);

    expect(AiDoctorPrompt::query()->where('doctor_id', $this->doctorModel->id)->count())->toBe(5);
});
