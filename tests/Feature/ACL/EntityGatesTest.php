<?php

/**
 * Testa todos os Gates de ACL contextual por entity definidos em AuthServiceProvider.
 *
 * Cobertura dos cenários solicitados:
 *  - cenário 1: admin da entity cliente pode acessar recursos da clínica
 *  - cenário 2: doctor da entity cliente não pode acessar financeiro
 *  - cenário 3: support da entity SaaS pode acessar painel interno
 *  - cenário 4: support não é role válido em entity cliente
 *  - cenário 5: doctor não é role válido na entity SaaS
 *  - cenário 6: mesmo usuário com papéis diferentes em entities diferentes
 *  - cenário 7: usuário sem vínculo recebe 403 (Gate nega)
 */

use App\Enums\EntityGate;
use App\Models\{Entity, User};
use Illuminate\Support\Facades\Gate;

// ── fixtures ─────────────────────────────────────────────────────────────────

beforeEach(function () {
    $this->saasEntity   = Entity::factory()->create(['is_client' => false]);
    $this->clientEntity = Entity::factory()->create(['is_client' => true]);
    $this->user         = User::factory()->create();
});

// =============================================================================
// SaaS Gates
// =============================================================================

// ── saas.access ───────────────────────────────────────────────────────────────

test('saas.access permite qualquer membro ativo da entity SaaS', function () {
    createEntityUser($this->saasEntity, $this->user, 'user');

    expect(Gate::forUser($this->user)->check(EntityGate::SaasAccess->value, $this->saasEntity))
        ->toBeTrue();
});

test('saas.access nega para entity cliente mesmo que o usuário seja membro', function () {
    createEntityUser($this->clientEntity, $this->user, 'admin');

    expect(Gate::forUser($this->user)->check(EntityGate::SaasAccess->value, $this->clientEntity))
        ->toBeFalse();
});

// Cenário 7: sem vínculo
test('[cenário 7] saas.access nega usuário sem vínculo com a entity SaaS', function () {
    expect(Gate::forUser($this->user)->check(EntityGate::SaasAccess->value, $this->saasEntity))
        ->toBeFalse();
});

test('saas.access nega membro inativo da entity SaaS', function () {
    createEntityUser($this->saasEntity, $this->user, 'admin', active: false);

    expect(Gate::forUser($this->user)->check(EntityGate::SaasAccess->value, $this->saasEntity))
        ->toBeFalse();
});

// ── saas.admin-panel ──────────────────────────────────────────────────────────

test('saas.admin-panel permite somente admin na entity SaaS', function () {
    createEntityUser($this->saasEntity, $this->user, 'admin');

    expect(Gate::forUser($this->user)->check(EntityGate::SaasAdminPanel->value, $this->saasEntity))
        ->toBeTrue();
});

test('saas.admin-panel nega support na entity SaaS', function () {
    createEntityUser($this->saasEntity, $this->user, 'support');

    expect(Gate::forUser($this->user)->check(EntityGate::SaasAdminPanel->value, $this->saasEntity))
        ->toBeFalse();
});

test('saas.admin-panel nega financial na entity SaaS', function () {
    createEntityUser($this->saasEntity, $this->user, 'financial');

    expect(Gate::forUser($this->user)->check(EntityGate::SaasAdminPanel->value, $this->saasEntity))
        ->toBeFalse();
});

// ── saas.support ─────────────────────────────────────────────────────────────

// Cenário 3: support na entity SaaS pode acessar painel interno
test('[cenário 3] saas.support permite support na entity SaaS', function () {
    createEntityUser($this->saasEntity, $this->user, 'support');

    expect(Gate::forUser($this->user)->check(EntityGate::SaasSupport->value, $this->saasEntity))
        ->toBeTrue();
});

test('[cenário 3] saas.support permite admin na entity SaaS', function () {
    createEntityUser($this->saasEntity, $this->user, 'admin');

    expect(Gate::forUser($this->user)->check(EntityGate::SaasSupport->value, $this->saasEntity))
        ->toBeTrue();
});

test('saas.support nega financial na entity SaaS', function () {
    createEntityUser($this->saasEntity, $this->user, 'financial');

    expect(Gate::forUser($this->user)->check(EntityGate::SaasSupport->value, $this->saasEntity))
        ->toBeFalse();
});

// Cenário 4: support não existe como role em entity cliente → Gate SaaS nega entity cliente
test('[cenário 4] saas.support nega entity cliente mesmo com role admin', function () {
    createEntityUser($this->clientEntity, $this->user, 'admin');

    expect(Gate::forUser($this->user)->check(EntityGate::SaasSupport->value, $this->clientEntity))
        ->toBeFalse();
});

// ── saas.financial ────────────────────────────────────────────────────────────

test('saas.financial permite financial na entity SaaS', function () {
    createEntityUser($this->saasEntity, $this->user, 'financial');

    expect(Gate::forUser($this->user)->check(EntityGate::SaasFinancial->value, $this->saasEntity))
        ->toBeTrue();
});

test('saas.financial permite admin na entity SaaS', function () {
    createEntityUser($this->saasEntity, $this->user, 'admin');

    expect(Gate::forUser($this->user)->check(EntityGate::SaasFinancial->value, $this->saasEntity))
        ->toBeTrue();
});

test('saas.financial nega support na entity SaaS', function () {
    createEntityUser($this->saasEntity, $this->user, 'support');

    expect(Gate::forUser($this->user)->check(EntityGate::SaasFinancial->value, $this->saasEntity))
        ->toBeFalse();
});

// =============================================================================
// Client entity Gates
// =============================================================================

// ── entity.access ─────────────────────────────────────────────────────────────

test('entity.access permite qualquer membro ativo da entity cliente', function () {
    createEntityUser($this->clientEntity, $this->user, 'user');

    expect(Gate::forUser($this->user)->check(EntityGate::EntityAccess->value, $this->clientEntity))
        ->toBeTrue();
});

// Cenário 7: sem vínculo
test('[cenário 7] entity.access nega usuário sem vínculo com a entity cliente', function () {
    expect(Gate::forUser($this->user)->check(EntityGate::EntityAccess->value, $this->clientEntity))
        ->toBeFalse();
});

test('entity.access nega entity SaaS', function () {
    createEntityUser($this->saasEntity, $this->user, 'admin');

    expect(Gate::forUser($this->user)->check(EntityGate::EntityAccess->value, $this->saasEntity))
        ->toBeFalse();
});

// ── entity.manage-users ───────────────────────────────────────────────────────

// Cenário 1: admin da entity cliente pode gerenciar usuários
test('[cenário 1] entity.manage-users permite admin da entity cliente', function () {
    createEntityUser($this->clientEntity, $this->user, 'admin');

    expect(Gate::forUser($this->user)->check(EntityGate::ManageUsers->value, $this->clientEntity))
        ->toBeTrue();
});

test('entity.manage-users nega doctor da entity cliente', function () {
    createEntityUser($this->clientEntity, $this->user, 'doctor');

    expect(Gate::forUser($this->user)->check(EntityGate::ManageUsers->value, $this->clientEntity))
        ->toBeFalse();
});

test('entity.manage-users nega secretary da entity cliente', function () {
    createEntityUser($this->clientEntity, $this->user, 'secretary');

    expect(Gate::forUser($this->user)->check(EntityGate::ManageUsers->value, $this->clientEntity))
        ->toBeFalse();
});

// ── entity.view-financial ─────────────────────────────────────────────────────

// Cenário 1: admin pode ver financeiro
test('[cenário 1] entity.view-financial permite admin da entity cliente', function () {
    createEntityUser($this->clientEntity, $this->user, 'admin');

    expect(Gate::forUser($this->user)->check(EntityGate::ViewFinancial->value, $this->clientEntity))
        ->toBeTrue();
});

test('entity.view-financial permite financial da entity cliente', function () {
    createEntityUser($this->clientEntity, $this->user, 'financial');

    expect(Gate::forUser($this->user)->check(EntityGate::ViewFinancial->value, $this->clientEntity))
        ->toBeTrue();
});

// Cenário 2: doctor não pode acessar financeiro
test('[cenário 2] entity.view-financial nega doctor da entity cliente', function () {
    createEntityUser($this->clientEntity, $this->user, 'doctor');

    expect(Gate::forUser($this->user)->check(EntityGate::ViewFinancial->value, $this->clientEntity))
        ->toBeFalse();
});

test('[cenário 2] entity.view-financial nega secretary da entity cliente', function () {
    createEntityUser($this->clientEntity, $this->user, 'secretary');

    expect(Gate::forUser($this->user)->check(EntityGate::ViewFinancial->value, $this->clientEntity))
        ->toBeFalse();
});

test('[cenário 2] entity.view-financial nega user comum da entity cliente', function () {
    createEntityUser($this->clientEntity, $this->user, 'user');

    expect(Gate::forUser($this->user)->check(EntityGate::ViewFinancial->value, $this->clientEntity))
        ->toBeFalse();
});

// ── entity.edit-schedule ──────────────────────────────────────────────────────

test('entity.edit-schedule permite admin da entity cliente', function () {
    createEntityUser($this->clientEntity, $this->user, 'admin');

    expect(Gate::forUser($this->user)->check(EntityGate::EditSchedule->value, $this->clientEntity))
        ->toBeTrue();
});

test('entity.edit-schedule permite doctor da entity cliente', function () {
    createEntityUser($this->clientEntity, $this->user, 'doctor');

    expect(Gate::forUser($this->user)->check(EntityGate::EditSchedule->value, $this->clientEntity))
        ->toBeTrue();
});

test('entity.edit-schedule permite secretary da entity cliente', function () {
    createEntityUser($this->clientEntity, $this->user, 'secretary');

    expect(Gate::forUser($this->user)->check(EntityGate::EditSchedule->value, $this->clientEntity))
        ->toBeTrue();
});

test('entity.edit-schedule nega user comum da entity cliente', function () {
    createEntityUser($this->clientEntity, $this->user, 'user');

    expect(Gate::forUser($this->user)->check(EntityGate::EditSchedule->value, $this->clientEntity))
        ->toBeFalse();
});

test('entity.edit-schedule nega financial da entity cliente', function () {
    createEntityUser($this->clientEntity, $this->user, 'financial');

    expect(Gate::forUser($this->user)->check(EntityGate::EditSchedule->value, $this->clientEntity))
        ->toBeFalse();
});

// ── entity.issue-report ───────────────────────────────────────────────────────

test('entity.issue-report permite somente doctor da entity cliente', function () {
    createEntityUser($this->clientEntity, $this->user, 'doctor');

    expect(Gate::forUser($this->user)->check(EntityGate::IssueReport->value, $this->clientEntity))
        ->toBeTrue();
});

test('entity.issue-report nega admin da entity cliente', function () {
    createEntityUser($this->clientEntity, $this->user, 'admin');

    expect(Gate::forUser($this->user)->check(EntityGate::IssueReport->value, $this->clientEntity))
        ->toBeFalse();
});

test('entity.issue-report nega secretary da entity cliente', function () {
    createEntityUser($this->clientEntity, $this->user, 'secretary');

    expect(Gate::forUser($this->user)->check(EntityGate::IssueReport->value, $this->clientEntity))
        ->toBeFalse();
});

// Cenário 5: doctor não existe como role na SaaS → Gate de client nega entity SaaS
test('[cenário 5] entity.issue-report nega entity SaaS mesmo com admin', function () {
    createEntityUser($this->saasEntity, $this->user, 'admin');

    expect(Gate::forUser($this->user)->check(EntityGate::IssueReport->value, $this->saasEntity))
        ->toBeFalse();
});

// ── entity.manage-settings ────────────────────────────────────────────────────

test('entity.manage-settings permite admin da entity cliente', function () {
    createEntityUser($this->clientEntity, $this->user, 'admin');

    expect(Gate::forUser($this->user)->check(EntityGate::ManageSettings->value, $this->clientEntity))
        ->toBeTrue();
});

test('entity.manage-settings nega doctor da entity cliente', function () {
    createEntityUser($this->clientEntity, $this->user, 'doctor');

    expect(Gate::forUser($this->user)->check(EntityGate::ManageSettings->value, $this->clientEntity))
        ->toBeFalse();
});

// =============================================================================
// Cenário 6: mesmo usuário, roles diferentes em entities diferentes
// =============================================================================

test('[cenário 6] mesmo usuário é doctor em entity cliente e admin em outra', function () {
    $otherClient = Entity::factory()->create(['is_client' => true]);

    createEntityUser($this->clientEntity, $this->user, 'doctor');
    createEntityUser($otherClient, $this->user, 'admin');

    // Na entity principal: pode emitir laudo, não pode ver financeiro
    expect(Gate::forUser($this->user)->check(EntityGate::IssueReport->value, $this->clientEntity))
        ->toBeTrue();
    expect(Gate::forUser($this->user)->check(EntityGate::ViewFinancial->value, $this->clientEntity))
        ->toBeFalse();

    // Na outra entity: pode ver financeiro, não pode emitir laudo
    expect(Gate::forUser($this->user)->check(EntityGate::ViewFinancial->value, $otherClient))
        ->toBeTrue();
    expect(Gate::forUser($this->user)->check(EntityGate::IssueReport->value, $otherClient))
        ->toBeFalse();
});

test('[cenário 6] mesmo usuário é support no SaaS e admin em entity cliente', function () {
    createEntityUser($this->saasEntity, $this->user, 'support');
    createEntityUser($this->clientEntity, $this->user, 'admin');

    // No SaaS: pode acessar suporte, não pode acessar admin panel
    expect(Gate::forUser($this->user)->check(EntityGate::SaasSupport->value, $this->saasEntity))
        ->toBeTrue();
    expect(Gate::forUser($this->user)->check(EntityGate::SaasAdminPanel->value, $this->saasEntity))
        ->toBeFalse();

    // No cliente: pode gerenciar usuários, mas Gate SaaS o nega
    expect(Gate::forUser($this->user)->check(EntityGate::ManageUsers->value, $this->clientEntity))
        ->toBeTrue();
    expect(Gate::forUser($this->user)->check(EntityGate::SaasSupport->value, $this->clientEntity))
        ->toBeFalse();
});

// =============================================================================
// Cenário 7: usuário sem vínculo — Gates negam
// =============================================================================

test('[cenário 7] todos os Gates negam usuário sem vínculo algum', function () {
    $gates = [
        EntityGate::SaasAccess->value     => $this->saasEntity,
        EntityGate::SaasAdminPanel->value => $this->saasEntity,
        EntityGate::SaasSupport->value    => $this->saasEntity,
        EntityGate::SaasFinancial->value  => $this->saasEntity,
        EntityGate::EntityAccess->value   => $this->clientEntity,
        EntityGate::ManageUsers->value    => $this->clientEntity,
        EntityGate::ViewFinancial->value  => $this->clientEntity,
        EntityGate::EditSchedule->value   => $this->clientEntity,
        EntityGate::IssueReport->value    => $this->clientEntity,
        EntityGate::ManageSettings->value => $this->clientEntity,
    ];

    foreach ($gates as $gate => $entity) {
        expect(Gate::forUser($this->user)->check($gate, $entity))
            ->toBeFalse("Gate [{$gate}] deveria negar usuário sem vínculo");
    }
});
