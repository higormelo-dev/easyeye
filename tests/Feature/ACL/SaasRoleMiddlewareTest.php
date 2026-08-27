<?php

/**
 * Testa o middleware EnsureSaasRole (saas.role) a nível HTTP.
 *
 * Contexto: `saas.admin` (EnsureSaasAdmin) valida apenas que a entity da
 * sessão NÃO é de clínica — nunca o papel do usuário nela. `saas.role`
 * fecha esse gap: exige um dos SaasRule listados (ou is_owner) na entity
 * SAAS resolvida EXCLUSIVAMENTE pela sessão — nunca pelo route param
 * `{entity}`, que no manager aponta para a clínica gerenciada.
 *
 * Duas camadas de cobertura:
 *  1. Semântica do middleware em rotas temporárias (mesmo padrão do
 *     AclMiddlewareTest) — sem dependência de controllers reais.
 *  2. Wiring nas rotas reais do manager — garante que os grupos de
 *     routes/manager.php realmente aplicam o middleware.
 */

use App\Models\{Entity, User};
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    $this->saasEntity   = Entity::factory()->create(['is_client' => false]);
    $this->clientEntity = Entity::factory()->create(['is_client' => true]);
    $this->user         = User::factory()->create();

    Route::middleware(['web', 'auth', 'saas.role:admin'])
        ->get('/_test/saas-admin-only', fn () => response()->json(['ok' => true]));

    Route::middleware(['web', 'auth', 'saas.role:admin,financial'])
        ->get('/_test/saas-financial', fn () => response()->json(['ok' => true]));

    Route::middleware(['web', 'auth', 'saas.role:admin,support'])
        ->get('/_test/saas-support', fn () => response()->json(['ok' => true]));

    // Misconfiguration: nenhum role — deve NEGAR (fail-closed).
    Route::middleware(['web', 'auth', 'saas.role'])
        ->get('/_test/saas-misconfigured', fn () => response()->json(['ok' => true]));
});

// =============================================================================
// Semântica do middleware (rotas temporárias)
// =============================================================================

test('saas.role:admin permite admin da entity SaaS', function () {
    createEntityUser($this->saasEntity, $this->user, 'admin');

    $this->actingAs($this->user)
        ->withSession(['selected_entity_id' => $this->saasEntity->id])
        ->getJson('/_test/saas-admin-only')
        ->assertOk();
});

test('saas.role:admin nega financial, support e user da entity SaaS', function (string $rule) {
    createEntityUser($this->saasEntity, $this->user, $rule);

    $this->actingAs($this->user)
        ->withSession(['selected_entity_id' => $this->saasEntity->id])
        ->getJson('/_test/saas-admin-only')
        ->assertForbidden();
})->with(['financial', 'support', 'user']);

test('saas.role:admin,financial permite financial e nega support', function () {
    createEntityUser($this->saasEntity, $this->user, 'financial');

    $this->actingAs($this->user)
        ->withSession(['selected_entity_id' => $this->saasEntity->id])
        ->getJson('/_test/saas-financial')
        ->assertOk();

    $this->actingAs($this->user)
        ->withSession(['selected_entity_id' => $this->saasEntity->id])
        ->getJson('/_test/saas-support')
        ->assertForbidden();
});

test('saas.role:admin,support permite support', function () {
    createEntityUser($this->saasEntity, $this->user, 'support');

    $this->actingAs($this->user)
        ->withSession(['selected_entity_id' => $this->saasEntity->id])
        ->getJson('/_test/saas-support')
        ->assertOk();
});

test('owner da entity SaaS passa mesmo sem o rule exigido', function () {
    // Dono do SaaS com rule 'user' — is_owner não pode se trancar para fora.
    createEntityUser($this->saasEntity, $this->user, 'user', isOwner: true);

    $this->actingAs($this->user)
        ->withSession(['selected_entity_id' => $this->saasEntity->id])
        ->getJson('/_test/saas-admin-only')
        ->assertOk();
});

test('admin de entity CLIENTE é negado — contexto SaaS exige entity não-cliente', function () {
    createEntityUser($this->clientEntity, $this->user, 'admin');

    $this->actingAs($this->user)
        ->withSession(['selected_entity_id' => $this->clientEntity->id])
        ->getJson('/_test/saas-admin-only')
        ->assertForbidden();
});

test('saas.role nega sem entity na sessão', function () {
    createEntityUser($this->saasEntity, $this->user, 'admin');

    $this->actingAs($this->user)
        ->getJson('/_test/saas-admin-only')
        ->assertForbidden();
});

test('saas.role nega vínculo soft-deleted', function () {
    $eu = createEntityUser($this->saasEntity, $this->user, 'admin');
    $eu->delete();

    $this->actingAs($this->user)
        ->withSession(['selected_entity_id' => $this->saasEntity->id])
        ->getJson('/_test/saas-admin-only')
        ->assertForbidden();
});

test('saas.role sem roles configurados nega acesso (fail-closed)', function () {
    createEntityUser($this->saasEntity, $this->user, 'admin');

    $this->actingAs($this->user)
        ->withSession(['selected_entity_id' => $this->saasEntity->id])
        ->getJson('/_test/saas-misconfigured')
        ->assertForbidden();
});

test('saas.role nega usuário não autenticado', function () {
    $this->getJson('/_test/saas-admin-only')
        ->assertUnauthorized();
});

// =============================================================================
// Wiring nas rotas reais do manager
// =============================================================================

/**
 * Sessão mínima para atravessar entity.selected + saas.admin + 2fa nas
 * rotas reais do manager.
 */
function managerSession(Entity $saasEntity, string $rule): array
{
    return [
        'selected_entity_id'        => $saasEntity->id,
        'selected_entity_is_client' => false,
        'selected_entity_user_rule' => $rule,
    ];
}

test('rota real: financial NÃO acessa listagem de empresas', function () {
    createEntityUser($this->saasEntity, $this->user, 'financial');

    $this->actingAs($this->user)
        ->withSession(managerSession($this->saasEntity, 'financial'))
        ->getJson(route('manager.entities.index'))
        ->assertForbidden();
});

test('rota real: support NÃO acessa gateways de pagamento', function () {
    createEntityUser($this->saasEntity, $this->user, 'support');

    $this->actingAs($this->user)
        ->withSession(managerSession($this->saasEntity, 'support'))
        ->getJson(route('manager.gateways.index'))
        ->assertForbidden();
});

test('rota real: user comum SaaS NÃO acessa dashboard do manager', function () {
    createEntityUser($this->saasEntity, $this->user, 'user');

    $this->actingAs($this->user)
        ->withSession(managerSession($this->saasEntity, 'user'))
        ->getJson(route('manager.dashboard'))
        ->assertForbidden();
});

test('rota real: financial NÃO acessa planos nem modelos de documento', function () {
    createEntityUser($this->saasEntity, $this->user, 'financial');

    $session = managerSession($this->saasEntity, 'financial');

    $this->actingAs($this->user)
        ->withSession($session)
        ->getJson(route('manager.plans.index'))
        ->assertForbidden();

    $this->actingAs($this->user)
        ->withSession($session)
        ->getJson(route('manager.report-settings.index'))
        ->assertForbidden();
});

test('rota real: support NÃO cancela assinaturas', function () {
    createEntityUser($this->saasEntity, $this->user, 'support');

    $this->actingAs($this->user)
        ->withSession(managerSession($this->saasEntity, 'support'))
        ->postJson(route('manager.subscriptions.cancel'))
        ->assertForbidden();
});

test('rota real: admin SaaS continua acessando listagem de empresas', function () {
    createEntityUser($this->saasEntity, $this->user, 'admin');

    $this->actingAs($this->user)
        ->withSession(managerSession($this->saasEntity, 'admin'))
        ->get(route('manager.entities.index'))
        ->assertOk();
});
