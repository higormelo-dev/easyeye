<?php

declare(strict_types=1);

/**
 * Regressão de segurança (auditoria manager.php — ROLE_BYPASS CRITICAL):
 * hasAnyRoleInEntity()/isOwnerOfEntity() (App\Traits\HasEntityRoles) não
 * checavam active=true no vínculo entity_users — um staff SaaS desativado
 * (active=false, sem soft-delete) continuava passando por EnsureSaasRole em
 * TODA rota /panel/manager, incluindo saas.role:admin (destruir entities,
 * credenciais de gateway, planos). Corrigido em HasEntityRoles::
 * getRuleInEntity()/isOwnerOfEntity() + AuthenticatedEntityController::store()
 * (que também não filtrava active ao reselecionar entidade).
 */

use App\Enums\ClientRule;
use App\Models\{Entity, User};

beforeEach(function () {
    $this->saasEntity = Entity::factory()->create(['is_client' => false]);
    $this->staff      = User::factory()->create();
    $this->entityUser = createEntityUser($this->saasEntity, $this->staff, ClientRule::Admin->value);
});

test('staff SaaS ativo acessa normalmente o painel manager', function () {
    $this->actingAs($this->staff)
        ->withSession([
            'selected_entity_id'        => $this->saasEntity->id,
            'selected_entity_user_id'   => $this->entityUser->id,
            'selected_entity_user_rule' => $this->entityUser->rule,
            'selected_entity_is_client' => false,
        ])
        ->get(route('manager.dashboard'))
        ->assertOk();
});

test('staff SaaS desativado (active=false) perde acesso ao painel manager mesmo com sessao antiga', function () {
    $this->entityUser->update(['active' => false]);

    $this->actingAs($this->staff)
        ->withSession([
            'selected_entity_id'        => $this->saasEntity->id,
            'selected_entity_user_id'   => $this->entityUser->id,
            'selected_entity_user_rule' => $this->entityUser->rule,
            'selected_entity_is_client' => false,
        ])
        ->get(route('manager.dashboard'))
        ->assertForbidden();
});

test('staff SaaS desativado nao consegue reselecionar a entidade e reobter sessao admin', function () {
    $this->entityUser->update(['active' => false]);

    $this->actingAs($this->staff)
        ->post(route('selectentity.store'), ['entity_user_id' => $this->entityUser->id])
        ->assertForbidden();

    expect(session('selected_entity_id'))->toBeNull();
});

test('owner desativado nao passa mais em isOwnerOfEntity via saas.role', function () {
    $this->entityUser->update(['is_owner' => true, 'active' => false]);

    $this->actingAs($this->staff)
        ->withSession([
            'selected_entity_id'        => $this->saasEntity->id,
            'selected_entity_user_id'   => $this->entityUser->id,
            'selected_entity_user_rule' => $this->entityUser->rule,
            'selected_entity_is_client' => false,
        ])
        ->get(route('manager.entities.cards'))
        ->assertForbidden();
});
