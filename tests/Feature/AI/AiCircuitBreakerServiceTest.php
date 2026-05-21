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
