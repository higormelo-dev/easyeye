<?php

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(Tests\TestCase::class)
    ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
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
        \App\Enums\FeatureKey::ApiPerPageLimit->value  => '100',
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
