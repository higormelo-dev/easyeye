<?php

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
*/

pest()->extend(Tests\TestCase::class)
    ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
*/

/**
 * Monta o contexto completo de um integrador para testes:
 * entity → subscription (plano com features customizáveis) → integratorUser → integrator → token.
 *
 * Retorna: entity, integratorUser, integrator, token (plainText), headers
 */
function setupIntegrator(array $featureOverrides = []): array
{
    $features = array_merge([
        \App\Enums\FeatureKey::HasApiIntegrator->value => '1',
    ], $featureOverrides);

    $plan = \App\Models\Plan::factory()->create();

    foreach ($features as $key => $value) {
        \App\Models\PlanFeature::create([
            'plan_id' => $plan->id,
            'feature' => $key,
            'value'   => $value,
        ]);
    }

    $entity = \App\Models\Entity::factory()->create(['is_client' => true, 'active' => true]);

    \App\Models\Subscription::create([
        'entity_id' => $entity->id,
        'plan_id'   => $plan->id,
        'status'    => \App\Enums\SubscriptionStatus::Active,
        'starts_at' => now()->subDay(),
        'ends_at'   => now()->addMonth(),
    ]);

    $integratorUser = \App\Models\EntityUserIntegrator::factory()->create([
        'entity_id' => $entity->id,
        'active'    => true,
    ]);

    $integrator = \App\Models\EntityIntegrator::factory()->create([
        'entity_user_integrator_id' => $integratorUser->id,
        'active'                    => true,
    ]);

    $token = $integratorUser->createToken(
        'integrator-token',
        ['integrator_id:' . $integrator->id],
        \Carbon\Carbon::now()->addDays(7)
    );

    return [
        'entity'         => $entity,
        'integratorUser' => $integratorUser,
        'integrator'     => $integrator,
        'token'          => $token->plainTextToken,
        'headers'        => ['Authorization' => 'Bearer ' . $token->plainTextToken],
    ];
}

/**
 * Cria um registro EntityUser (membership) para uso nos testes de ACL.
 * O código é gerado automaticamente pelo booted() do model.
 */
function createEntityUser(
    \App\Models\Entity $entity,
    \App\Models\User $user,
    string $rule,
    bool $active = true,
    bool $isOwner = false,
): \App\Models\EntityUser {
    return \App\Models\EntityUser::create([
        'entity_id' => $entity->id,
        'user_id'   => $user->id,
        'rule'      => $rule,
        'active'    => $active,
        'is_owner'  => $isOwner,
        'joined_at' => now(),
    ]);
}

/**
 * Cria um Doctor vinculado a uma Entity para uso em testes de agendamento.
 * Retorna o Doctor criado.
 */
function createDoctorForEntity(\App\Models\Entity $entity): \App\Models\Doctor
{
    $user   = \App\Models\User::factory()->create();
    $people = \App\Models\People::factory()->create();
    $eu     = createEntityUser($entity, $user, \App\Enums\ClientRule::Doctor->value);

    return \App\Models\Doctor::create([
        'entity_user_id' => $eu->id,
        'person_id'      => $people->id,
        'active'         => true,
    ]);
}

/**
 * Cria um Schedule vinculado a uma Entity para uso em testes.
 * Retorna ['schedule' => Schedule, 'doctor' => Doctor].
 */
function createScheduleForEntity(\App\Models\Entity $entity, array $attrs = []): array
{
    $doctor   = createDoctorForEntity($entity);
    $schedule = \App\Models\Schedule::create(array_merge([
        'entity_id' => $entity->id,
        'doctor_id' => $doctor->id,
        'full_name' => 'Paciente Teste',
        'date_time' => now(),
        'situation' => \App\Enums\ScheduleSituation::Scheduled->value,
        'active'    => true,
    ], $attrs));

    return compact('schedule', 'doctor');
}

/**
 * Sessão padrão de um EntityUser navegando o painel da clínica.
 * Inclui as chaves que o middleware entity.selected espera para resolver o tenant.
 */
function panelSession($entityUser): array
{
    return [
        'selected_entity_id'        => $entityUser->entity_id,
        'selected_entity_user_id'   => $entityUser->id,
        'selected_entity_user_rule' => $entityUser->rule,
        'selected_entity_is_client' => true,
    ];
}

/**
 * Headers de uma request Inertia. Inclui X-Inertia-Version computado pelo middleware
 * para evitar 409 (version mismatch) em testes que disparam GET com X-Inertia.
 */
function inertiaHeaders(): array
{
    $middleware = app(\App\Http\Middleware\HandleInertiaRequests::class);
    $version    = $middleware->version(request()) ?? '';

    return [
        'X-Inertia'         => 'true',
        'X-Inertia-Version' => $version,
        'X-Requested-With'  => 'XMLHttpRequest',
    ];
}

/**
 * Payload mínimo válido para POST /panel/ai/runs (cria uma execução de IA).
 */
function baseRunPayload(): array
{
    return [
        'workflow'          => 'report_drafting',
        'mode'              => \App\Enums\AI\AiRunMode::Validated->value,
        'risk_level'        => \App\Enums\AI\AiRiskLevel::Medium->value,
        'user_prompt'       => 'Gerar rascunho clínico com linguagem de apoio para revisão médica.',
        'system_prompt'     => 'Você é um assistente de apoio clínico.',
        'context'           => ['specialty' => 'ophthalmology'],
        'attachments'       => [],
        'expects_json'      => false,
        'max_output_tokens' => 700,
    ];
}
