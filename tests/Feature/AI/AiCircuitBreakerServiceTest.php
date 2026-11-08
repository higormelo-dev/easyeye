<?php

use App\Domains\AI\Models\AiCircuitBreaker;
use App\Domains\AI\Services\AiCircuitBreakerService;
use App\Enums\AI\AiProvider;
use App\Models\Entity;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('breaker inicia fechado e isOpen retorna false', function () {
    $service = new AiCircuitBreakerService(threshold: 3, cooldownSeconds: 60);

    expect($service->isOpen(AiProvider::OpenAI))->toBeFalse();
});

test('breaker abre depois de threshold falhas consecutivas', function () {
    $service = new AiCircuitBreakerService(threshold: 3, cooldownSeconds: 60);

    $service->recordFailure(AiProvider::OpenAI, 'timeout');
    $service->recordFailure(AiProvider::OpenAI, 'timeout');

    expect($service->isOpen(AiProvider::OpenAI))->toBeFalse();

    $service->recordFailure(AiProvider::OpenAI, 'timeout');

    expect($service->isOpen(AiProvider::OpenAI))->toBeTrue();

    $breaker = AiCircuitBreaker::query()->where('provider_code', 'openai')->firstOrFail();
    expect($breaker->failure_count)->toBe(3);
    expect($breaker->state)->toBe('open');
    expect($breaker->last_trigger_type)->toBe('timeout');
});

test('recordSuccess zera contador e fecha o circuito', function () {
    $service = new AiCircuitBreakerService(threshold: 3, cooldownSeconds: 60);

    $service->recordFailure(AiProvider::Anthropic, 'server_error');
    $service->recordFailure(AiProvider::Anthropic, 'server_error');
    $service->recordSuccess(AiProvider::Anthropic);

    $breaker = AiCircuitBreaker::query()->where('provider_code', 'anthropic')->firstOrFail();
    expect($breaker->state)->toBe('closed');
    expect($breaker->failure_count)->toBe(0);
});

test('breaker fica isOpen=false após open_until expirar', function () {
    $service = new AiCircuitBreakerService(threshold: 1, cooldownSeconds: 60);

    $service->recordFailure(AiProvider::Gemini, 'timeout');
    expect($service->isOpen(AiProvider::Gemini))->toBeTrue();

    // Simula expiração do cooldown.
    AiCircuitBreaker::query()->update(['open_until' => now()->subMinute()]);

    expect($service->isOpen(AiProvider::Gemini))->toBeFalse();
});

test('breaker por entity é independente do breaker global', function () {
    $entity  = Entity::factory()->create();
    $service = new AiCircuitBreakerService(threshold: 1, cooldownSeconds: 60);

    $service->recordFailure(AiProvider::OpenAI, 'rate_limit', entityId: $entity->id);

    expect($service->isOpen(AiProvider::OpenAI, $entity->id))->toBeTrue();
    expect($service->isOpen(AiProvider::OpenAI, null))->toBeFalse();
});

test('breaker entity herda do global quando entity-specific não existe', function () {
    $entity  = Entity::factory()->create();
    $service = new AiCircuitBreakerService(threshold: 1, cooldownSeconds: 60);

    // Falha global → abre breaker global.
    $service->recordFailure(AiProvider::OpenAI, 'auth', entityId: null);

    // Como não há breaker entity-specific, isOpen deve cair no global.
    expect($service->isOpen(AiProvider::OpenAI, $entity->id))->toBeTrue();
});

test('reset zera estado do breaker manualmente', function () {
    $service = new AiCircuitBreakerService(threshold: 1, cooldownSeconds: 60);

    $service->recordFailure(AiProvider::OpenAI, 'timeout');
    expect($service->isOpen(AiProvider::OpenAI))->toBeTrue();

    $service->reset(AiProvider::OpenAI);

    expect($service->isOpen(AiProvider::OpenAI))->toBeFalse();
    $breaker = AiCircuitBreaker::query()->where('provider_code', 'openai')->firstOrFail();
    expect($breaker->failure_count)->toBe(0);
    expect($breaker->state)->toBe('closed');
});

test('trip abre o circuito GLOBAL na hora: vale para toda entity, mesmo a que tem a própria linha fechada', function () {
    $service = new AiCircuitBreakerService(threshold: 5, cooldownSeconds: 120);
    $entity  = Entity::factory()->create();

    $service->recordFailure(AiProvider::OpenAI, 'timeout', (string) $entity->id); // linha da entity, fechada
    $service->trip(AiProvider::OpenAI, 'quota', 900);

    expect($service->isOpen(AiProvider::OpenAI, (string) $entity->id))->toBeTrue()
        ->and($service->isOpen(AiProvider::OpenAI))->toBeTrue()
        ->and($service->openState(AiProvider::OpenAI, (string) $entity->id)['trigger_type'])->toBe('quota')
        ->and($service->isOpen(AiProvider::Gemini))->toBeFalse();

    $this->travel(901)->seconds();

    expect($service->isOpen(AiProvider::OpenAI, (string) $entity->id))->toBeFalse()
        ->and($service->openState(AiProvider::OpenAI))->toBeNull();
});

test('trip nunca encurta um circuito já aberto por mais tempo (cota de 15 min e, no meio, um limite de 20 s)', function () {
    $service = new AiCircuitBreakerService(threshold: 5, cooldownSeconds: 120);

    $service->trip(AiProvider::OpenAI, 'quota', 900);
    $service->trip(AiProvider::OpenAI, 'rate_limit', 20);

    $this->travel(60)->seconds();

    expect($service->openState(AiProvider::OpenAI)['trigger_type'] ?? null)->toBe('quota');
});
