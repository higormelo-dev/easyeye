<?php

declare(strict_types=1);

use App\Models\Entity;
use App\Providers\AppServiceProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\LazyLoadingViolationException;
use Illuminate\Support\Facades\{Exceptions, Log};

/**
 * F0 "Enxergar": lazy loading proibido fora de produção, mas o callback só REPORTA — a dívida de N+1 aparece sem
 * quebrar tela nem teste (decisão D1 de docs/internal/planos/performance-f0-f1-plano.md).
 */

/** Roda o registro de observabilidade do provider no ambiente pedido (o app de teste já o rodou em `testing`). */
function observabilityBootedAs(string $environment): void
{
    app()['env'] = $environment;

    (new ReflectionMethod(AppServiceProvider::class, 'bootObservability'))
        ->invoke(new AppServiceProvider(app()));
}

/**
 * Um model de uma coleção de 2+ itens: o framework só chama o callback assim (N+1 de verdade). Um model sozinho
 * (create/find/first) nunca é violação.
 */
function entityFromCollection(): Entity
{
    Entity::factory()->count(2)->create();

    return Entity::query()->get()->first();
}

it('em testing o acesso lazy NÃO lança: só loga model e relação, sem reportar', function () {
    $entity = entityFromCollection();
    Exceptions::fake();
    Log::spy();

    $entity->subscription;

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => $message === 'eloquent.lazy_loading_violation'
            && $context === ['model' => Entity::class, 'relation' => 'subscription'])
        ->once();
    Exceptions::assertNothingReported();
});

it('model sozinho (create/find/first) não é N+1 e não gera registro', function () {
    $created = Entity::factory()->create();
    $found   = Entity::query()->findOrFail($created->id);
    Log::spy();

    $created->subscription;
    $found->subscription;

    Log::shouldNotHaveReceived('warning');
});

it('fora de testing (local/staging) reporta a violação e não lança', function () {
    $entity = entityFromCollection();
    Exceptions::fake();
    Log::spy();
    app()['env'] = 'local';

    $entity->subscription;

    Exceptions::assertReported(fn (LazyLoadingViolationException $e): bool => $e->model === Entity::class && $e->relation === 'subscription');
    Log::shouldNotHaveReceived('warning');
});

it('a proibição de lazy loading está ligada em testing/local/staging e desligada em produção', function () {
    $prevents = fn (): bool => (new ReflectionClass(Model::class))->getStaticPropertyValue('modelsShouldPreventLazyLoading');
    $actual   = [];

    foreach (['production', 'staging', 'local', 'testing'] as $environment) {
        observabilityBootedAs($environment);
        $actual[$environment] = $prevents();
    }

    expect($actual)->toBe(['production' => false, 'staging' => true, 'local' => true, 'testing' => true]);
});
