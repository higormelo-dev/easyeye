<?php

/**
 * Testa a trait HasEntityRoles aplicada ao model User.
 *
 * Cobertura dos cenários:
 *  - cenário 6: mesmo usuário com papéis diferentes em entidades diferentes
 *  - cenário 7: usuário sem vínculo com a entidade
 */

use App\Enums\ClientRule;
use App\Models\{Entity, EntityUser, User};

// ── fixtures ─────────────────────────────────────────────────────────────────

beforeEach(function () {
    $this->saasEntity   = Entity::factory()->create(['is_client' => false]);
    $this->clientEntity = Entity::factory()->create(['is_client' => true]);
    $this->user         = User::factory()->create();
});

// ── getRuleInEntity / roleInEntity ────────────────────────────────────────────

test('retorna o rule correto para o usuário na entity', function () {
    createEntityUser($this->clientEntity, $this->user, 'doctor');

    expect($this->user->getRuleInEntity($this->clientEntity))->toBe('doctor')
        ->and($this->user->roleInEntity($this->clientEntity))->toBe('doctor');
});

test('retorna null quando o usuário não tem vínculo com a entity', function () {
    // Cenário 7: sem EntityUser
    expect($this->user->getRuleInEntity($this->clientEntity))->toBeNull()
        ->and($this->user->roleInEntity($this->clientEntity))->toBeNull();
});

test('retorna null quando o vínculo está soft-deleted', function () {
    $eu = createEntityUser($this->clientEntity, $this->user, 'admin');
    $eu->delete();

    expect($this->user->getRuleInEntity($this->clientEntity))->toBeNull();
});

// Cenário 6: mesmo usuário, roles diferentes em entities diferentes
test('[cenário 6] mesmo usuário com roles diferentes em entities distintas', function () {
    createEntityUser($this->saasEntity, $this->user, 'support');
    createEntityUser($this->clientEntity, $this->user, 'doctor');

    expect($this->user->roleInEntity($this->saasEntity))->toBe('support')
        ->and($this->user->roleInEntity($this->clientEntity))->toBe('doctor');
});

// ── cache por request ─────────────────────────────────────────────────────────

test('cache é isolado por entity', function () {
    $otherEntity = Entity::factory()->create(['is_client' => true]);
    createEntityUser($this->clientEntity, $this->user, 'financial');
    createEntityUser($otherEntity, $this->user, 'admin');

    expect($this->user->roleInEntity($this->clientEntity))->toBe('financial')
        ->and($this->user->roleInEntity($otherEntity))->toBe('admin');
});

test('flushEntityRuleCache limpa apenas a entity informada', function () {
    createEntityUser($this->clientEntity, $this->user, 'admin');

    // popula o cache
    $this->user->roleInEntity($this->clientEntity);

    // muda o rule direto no banco sem passar pelo modelo
    EntityUser::where('user_id', $this->user->id)
        ->where('entity_id', $this->clientEntity->id)
        ->update(['rule' => 'financial']);

    // cache ainda retorna o valor antigo
    expect($this->user->roleInEntity($this->clientEntity))->toBe('admin');

    // após flush, lê do banco novamente
    $this->user->flushEntityRuleCache($this->clientEntity);
    expect($this->user->roleInEntity($this->clientEntity))->toBe('financial');
});

test('flushEntityRuleCache sem argumento limpa todo o cache', function () {
    $otherEntity = Entity::factory()->create(['is_client' => true]);
    createEntityUser($this->clientEntity, $this->user, 'admin');
    createEntityUser($otherEntity, $this->user, 'doctor');

    // popula o cache das duas
    $this->user->roleInEntity($this->clientEntity);
    $this->user->roleInEntity($otherEntity);

    EntityUser::where('user_id', $this->user->id)->update(['rule' => 'user']);

    $this->user->flushEntityRuleCache();

    expect($this->user->roleInEntity($this->clientEntity))->toBe('user')
        ->and($this->user->roleInEntity($otherEntity))->toBe('user');
});

// ── hasRoleInEntity ───────────────────────────────────────────────────────────

test('hasRoleInEntity retorna true para o role correto (string)', function () {
    createEntityUser($this->clientEntity, $this->user, 'doctor');

    expect($this->user->hasRoleInEntity($this->clientEntity, 'doctor'))->toBeTrue()
        ->and($this->user->hasRoleInEntity($this->clientEntity, 'admin'))->toBeFalse();
});

test('hasRoleInEntity aceita enum tipado', function () {
    createEntityUser($this->clientEntity, $this->user, 'doctor');

    expect($this->user->hasRoleInEntity($this->clientEntity, ClientRule::Doctor))->toBeTrue()
        ->and($this->user->hasRoleInEntity($this->clientEntity, ClientRule::Admin))->toBeFalse();
});

test('hasRoleInEntity retorna false quando não há vínculo', function () {
    expect($this->user->hasRoleInEntity($this->clientEntity, 'admin'))->toBeFalse();
});

// ── hasAnyRoleInEntity ────────────────────────────────────────────────────────

test('hasAnyRoleInEntity retorna true quando role está na lista', function () {
    createEntityUser($this->clientEntity, $this->user, 'financial');

    expect($this->user->hasAnyRoleInEntity($this->clientEntity, ['admin', 'financial']))->toBeTrue();
});

test('hasAnyRoleInEntity retorna false quando role não está na lista', function () {
    createEntityUser($this->clientEntity, $this->user, 'doctor');

    expect($this->user->hasAnyRoleInEntity($this->clientEntity, ['admin', 'financial']))->toBeFalse();
});

test('hasAnyRoleInEntity retorna false sem vínculo', function () {
    expect($this->user->hasAnyRoleInEntity($this->clientEntity, ['admin', 'doctor']))->toBeFalse();
});

// ── hasNoneOfRolesInEntity ────────────────────────────────────────────────────

test('hasNoneOfRolesInEntity retorna true quando role não está na lista bloqueada', function () {
    createEntityUser($this->clientEntity, $this->user, 'secretary');

    expect($this->user->hasNoneOfRolesInEntity($this->clientEntity, ['admin', 'financial']))->toBeTrue();
});

// ── canAccessEntity ───────────────────────────────────────────────────────────

test('canAccessEntity retorna true para membro ativo', function () {
    createEntityUser($this->clientEntity, $this->user, 'user', active: true);

    expect($this->user->canAccessEntity($this->clientEntity))->toBeTrue();
});

// Cenário 7: sem vínculo → false
test('[cenário 7] canAccessEntity retorna false para usuário sem vínculo', function () {
    expect($this->user->canAccessEntity($this->clientEntity))->toBeFalse();
});

test('canAccessEntity retorna false para membro inativo', function () {
    createEntityUser($this->clientEntity, $this->user, 'admin', active: false);

    expect($this->user->canAccessEntity($this->clientEntity))->toBeFalse();
});

test('canAccessEntity retorna false para vínculo soft-deleted', function () {
    $eu = createEntityUser($this->clientEntity, $this->user, 'admin');
    $eu->delete();

    expect($this->user->canAccessEntity($this->clientEntity))->toBeFalse();
});

// ── isOwnerOfEntity ───────────────────────────────────────────────────────────

test('isOwnerOfEntity retorna true quando is_owner = true', function () {
    createEntityUser($this->clientEntity, $this->user, 'admin', isOwner: true);

    expect($this->user->isOwnerOfEntity($this->clientEntity))->toBeTrue();
});

test('isOwnerOfEntity retorna false para membro não-owner', function () {
    createEntityUser($this->clientEntity, $this->user, 'admin');

    expect($this->user->isOwnerOfEntity($this->clientEntity))->toBeFalse();
});

// ── entities() BelongsToMany ──────────────────────────────────────────────────

test('entities() retorna apenas as entities não-deletadas do pivot', function () {
    $otherEntity = Entity::factory()->create(['is_client' => true]);
    createEntityUser($this->clientEntity, $this->user, 'admin');
    createEntityUser($otherEntity, $this->user, 'user')->delete();

    $entities = $this->user->entities;

    expect($entities)->toHaveCount(1)
        ->and($entities->first()->id)->toBe($this->clientEntity->id);
});

test('entities() expõe o pivot com o rule correto', function () {
    createEntityUser($this->clientEntity, $this->user, 'doctor');

    $entity = $this->user->entities->first();

    expect($entity->pivot->rule)->toBe('doctor');
});
