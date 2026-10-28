<?php

/**
 * Testa EntityRoleService, os enums SaasRule/ClientRule e os métodos auxiliares
 * de Entity (isSaas, isClient, validRules, allowedRoles, isValidRule).
 *
 * Cobertura dos cenários:
 *  - support não é role válido em entity cliente (cenário 4)
 *  - doctor não é role válido em entity SaaS (cenário 5)
 */

use App\Enums\{ClientRule, SaasRule};
use App\Models\Entity;
use App\Services\EntityRoleService;

// ── fixtures ─────────────────────────────────────────────────────────────────

beforeEach(function () {
    $this->saasEntity   = Entity::factory()->create(['is_client' => false]);
    $this->clientEntity = Entity::factory()->create(['is_client' => true]);
    $this->service      = app(EntityRoleService::class);
});

// ── Entity type helpers ───────────────────────────────────────────────────────

test('entity com is_client false é reconhecida como SaaS', function () {
    expect($this->saasEntity->isSaas())->toBeTrue()
        ->and($this->saasEntity->isClient())->toBeFalse()
        ->and($this->saasEntity->isManagerEntity())->toBeTrue();
});

test('entity com is_client true é reconhecida como cliente', function () {
    expect($this->clientEntity->isClient())->toBeTrue()
        ->and($this->clientEntity->isSaas())->toBeFalse()
        ->and($this->clientEntity->isManagerEntity())->toBeFalse();
});

// ── SaaS rules ────────────────────────────────────────────────────────────────

test('roles válidos para entity SaaS são admin, financial, support e user', function () {
    $rules = $this->saasEntity->validRules();

    expect($rules)->toContain('admin')
        ->toContain('financial')
        ->toContain('support')
        ->toContain('user')
        ->not->toContain('doctor')
        ->not->toContain('secretary');
});

test('allowedRoles é alias de validRules para entity SaaS', function () {
    expect($this->saasEntity->allowedRoles())->toBe($this->saasEntity->validRules());
});

// Cenário 5: doctor não deve ser role válido na entidade do SaaS
test('[cenário 5] doctor não é role válido na entidade SaaS', function () {
    expect($this->saasEntity->isValidRule('doctor'))->toBeFalse()
        ->and($this->saasEntity->isValidRule('secretary'))->toBeFalse();
});

test('roles do SaaS são aceitos como válidos na entity SaaS', function () {
    foreach (SaasRule::values() as $role) {
        expect($this->saasEntity->isValidRule($role))->toBeTrue("role [{$role}] deveria ser válido na SaaS");
    }
});

// ── Client rules ──────────────────────────────────────────────────────────────

test('roles válidos para entity cliente incluem doctor e secretary', function () {
    $rules = $this->clientEntity->validRules();

    expect($rules)->toContain('admin')
        ->toContain('financial')
        ->toContain('doctor')
        ->toContain('secretary')
        ->toContain('user')
        ->not->toContain('support');
});

// Cenário 4: support não deve ser role válido em entity cliente
test('[cenário 4] support não é role válido em entidade cliente', function () {
    expect($this->clientEntity->isValidRule('support'))->toBeFalse();
});

test('roles do cliente são aceitos como válidos na entity cliente', function () {
    foreach (ClientRule::values() as $role) {
        expect($this->clientEntity->isValidRule($role))->toBeTrue("role [{$role}] deveria ser válido no cliente");
    }
});

// ── EntityRoleService::parse ──────────────────────────────────────────────────

test('parse retorna SaasRule para entity SaaS', function () {
    $result = $this->service->parse($this->saasEntity, 'support');

    expect($result)->toBeInstanceOf(SaasRule::class)
        ->and($result)->toBe(SaasRule::Support);
});

test('parse retorna ClientRule para entity cliente', function () {
    $result = $this->service->parse($this->clientEntity, 'doctor');

    expect($result)->toBeInstanceOf(ClientRule::class)
        ->and($result)->toBe(ClientRule::Doctor);
});

test('parse retorna null para role inválido', function () {
    expect($this->service->parse($this->saasEntity, 'doctor'))->toBeNull()
        ->and($this->service->parse($this->clientEntity, 'support'))->toBeNull()
        ->and($this->service->parse($this->clientEntity, 'invalido'))->toBeNull();
});

// ── SaasRule enum ─────────────────────────────────────────────────────────────

test('SaasRule::values() retorna todos os valores esperados', function () {
    expect(SaasRule::values())->toBe(['admin', 'financial', 'support', 'user']);
});

test('SaasRule::options() retorna mapa value => label', function () {
    $options = SaasRule::options();

    expect($options)->toHaveKey('admin')
        ->toHaveKey('support')
        ->not->toHaveKey('doctor');
});

// ── ClientRule enum ───────────────────────────────────────────────────────────

test('ClientRule::values() retorna todos os valores esperados', function () {
    expect(ClientRule::values())->toBe(['admin', 'financial', 'doctor', 'secretary', 'user']);
});

test('ClientRule::options() retorna mapa value => label', function () {
    $options = ClientRule::options();

    expect($options)->toHaveKey('doctor')
        ->toHaveKey('secretary')
        ->not->toHaveKey('support');
});
