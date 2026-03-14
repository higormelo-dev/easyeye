<?php

/**
 * Testa os middlewares EnsureUserBelongsToEntity (entity.member)
 * e EnsureEntityRole (entity.role) a nível HTTP.
 *
 * A entity é resolvida pela sessão (selected_entity_id) para evitar
 * dependência de route model binding nas rotas temporárias de teste.
 *
 * Cobertura dos cenários:
 *  - cenário 1: admin da entity cliente acessa rota protegida → 200
 *  - cenário 2: doctor não pode acessar rota restrita a financial → 403
 *  - cenário 3: support acessa rota do SaaS → 200
 *  - cenário 6: mesmo usuário, roles diferentes → autorizado conforme entity
 *  - cenário 7: usuário sem vínculo → 403
 */

use App\Models\{Entity, User};
use Illuminate\Support\Facades\Route;

// ── fixtures ─────────────────────────────────────────────────────────────────

beforeEach(function () {
    $this->saasEntity   = Entity::factory()->create(['is_client' => false]);
    $this->clientEntity = Entity::factory()->create(['is_client' => true]);
    $this->user         = User::factory()->create();

    // Rotas temporárias para os testes — registradas antes do request
    Route::middleware(['web', 'auth', 'entity.member'])
        ->get('/_test/member-only', fn () => response()->json(['ok' => true]));

    Route::middleware(['web', 'auth', 'entity.role:admin'])
        ->get('/_test/admin-only', fn () => response()->json(['ok' => true]));

    Route::middleware(['web', 'auth', 'entity.role:admin,financial'])
        ->get('/_test/financial-or-admin', fn () => response()->json(['ok' => true]));

    Route::middleware(['web', 'auth', 'entity.role:admin,support'])
        ->get('/_test/saas-support', fn () => response()->json(['ok' => true]));

    Route::middleware(['web', 'auth', 'entity.role:doctor'])
        ->get('/_test/doctor-only', fn () => response()->json(['ok' => true]));
});

// =============================================================================
// EnsureUserBelongsToEntity (entity.member)
// =============================================================================

// Cenário 1: admin da entity cliente é membro ativo → 200
test('[cenário 1] entity.member permite membro ativo da entity', function () {
    createEntityUser($this->clientEntity, $this->user, 'admin');

    $this->actingAs($this->user)
        ->withSession(['selected_entity_id' => $this->clientEntity->id])
        ->getJson('/_test/member-only')
        ->assertOk();
});

// Cenário 7: sem vínculo → 403
test('[cenário 7] entity.member nega usuário sem vínculo com a entity', function () {
    $this->actingAs($this->user)
        ->withSession(['selected_entity_id' => $this->clientEntity->id])
        ->getJson('/_test/member-only')
        ->assertForbidden();
});

test('entity.member nega membro inativo', function () {
    createEntityUser($this->clientEntity, $this->user, 'admin', active: false);

    $this->actingAs($this->user)
        ->withSession(['selected_entity_id' => $this->clientEntity->id])
        ->getJson('/_test/member-only')
        ->assertForbidden();
});

test('entity.member nega quando não há entity_id na sessão', function () {
    createEntityUser($this->clientEntity, $this->user, 'admin');

    $this->actingAs($this->user)
        ->getJson('/_test/member-only')
        ->assertForbidden();
});

test('entity.member nega usuário não autenticado', function () {
    $this->getJson('/_test/member-only')
        ->assertUnauthorized();
});

test('entity.member nega vínculo soft-deleted', function () {
    $eu = createEntityUser($this->clientEntity, $this->user, 'admin');
    $eu->delete();

    $this->actingAs($this->user)
        ->withSession(['selected_entity_id' => $this->clientEntity->id])
        ->getJson('/_test/member-only')
        ->assertForbidden();
});

// =============================================================================
// EnsureEntityRole (entity.role)
// =============================================================================

// Cenário 1: admin da entity cliente acessa rota admin-only → 200
test('[cenário 1] entity.role:admin permite admin da entity cliente', function () {
    createEntityUser($this->clientEntity, $this->user, 'admin');

    $this->actingAs($this->user)
        ->withSession(['selected_entity_id' => $this->clientEntity->id])
        ->getJson('/_test/admin-only')
        ->assertOk();
});

// Cenário 2: doctor não pode acessar rota de financeiro
test('[cenário 2] entity.role:admin,financial nega doctor da entity cliente', function () {
    createEntityUser($this->clientEntity, $this->user, 'doctor');

    $this->actingAs($this->user)
        ->withSession(['selected_entity_id' => $this->clientEntity->id])
        ->getJson('/_test/financial-or-admin')
        ->assertForbidden();
});

test('[cenário 2] entity.role:admin,financial permite financial da entity cliente', function () {
    createEntityUser($this->clientEntity, $this->user, 'financial');

    $this->actingAs($this->user)
        ->withSession(['selected_entity_id' => $this->clientEntity->id])
        ->getJson('/_test/financial-or-admin')
        ->assertOk();
});

test('[cenário 2] entity.role:admin,financial permite admin da entity cliente', function () {
    createEntityUser($this->clientEntity, $this->user, 'admin');

    $this->actingAs($this->user)
        ->withSession(['selected_entity_id' => $this->clientEntity->id])
        ->getJson('/_test/financial-or-admin')
        ->assertOk();
});

// Cenário 3: support da entity SaaS acessa painel interno
test('[cenário 3] entity.role:admin,support permite support da entity SaaS', function () {
    createEntityUser($this->saasEntity, $this->user, 'support');

    $this->actingAs($this->user)
        ->withSession(['selected_entity_id' => $this->saasEntity->id])
        ->getJson('/_test/saas-support')
        ->assertOk();
});

// Cenário 4: support não existe em entity cliente → 403 (role não é 'admin' nem 'support')
// (O middleware não valida tipos de entity, apenas o valor do role armazenado)
test('[cenário 4] entity.role:admin,support nega doctor (sem o role exigido)', function () {
    createEntityUser($this->clientEntity, $this->user, 'doctor');

    $this->actingAs($this->user)
        ->withSession(['selected_entity_id' => $this->clientEntity->id])
        ->getJson('/_test/saas-support')
        ->assertForbidden();
});

// Cenário 5: doctor não é role SaaS → se tentar acessar rota doctor-only com entity SaaS
// (O role 'doctor' simplesmente não está em entity_users para entity SaaS)
test('[cenário 5] entity.role:doctor nega usuário sem role doctor', function () {
    createEntityUser($this->saasEntity, $this->user, 'admin');

    $this->actingAs($this->user)
        ->withSession(['selected_entity_id' => $this->saasEntity->id])
        ->getJson('/_test/doctor-only')
        ->assertForbidden();
});

test('entity.role:doctor permite doctor na entity cliente', function () {
    createEntityUser($this->clientEntity, $this->user, 'doctor');

    $this->actingAs($this->user)
        ->withSession(['selected_entity_id' => $this->clientEntity->id])
        ->getJson('/_test/doctor-only')
        ->assertOk();
});

// Cenário 6: mesmo usuário, roles diferentes → autorizado conforme entity ativa na sessão
test('[cenário 6] mesmo usuário é doctor em uma entity e admin em outra', function () {
    $otherClient = Entity::factory()->create(['is_client' => true]);
    createEntityUser($this->clientEntity, $this->user, 'doctor');
    createEntityUser($otherClient, $this->user, 'admin');

    // Na entity onde é doctor: acessa rota doctor, não acessa admin
    $this->actingAs($this->user)
        ->withSession(['selected_entity_id' => $this->clientEntity->id])
        ->getJson('/_test/doctor-only')
        ->assertOk();

    $this->actingAs($this->user)
        ->withSession(['selected_entity_id' => $this->clientEntity->id])
        ->getJson('/_test/admin-only')
        ->assertForbidden();

    // Na outra entity onde é admin: acessa rota admin, não acessa rota doctor
    $this->actingAs($this->user)
        ->withSession(['selected_entity_id' => $otherClient->id])
        ->getJson('/_test/admin-only')
        ->assertOk();

    $this->actingAs($this->user)
        ->withSession(['selected_entity_id' => $otherClient->id])
        ->getJson('/_test/doctor-only')
        ->assertForbidden();
});

test('[cenário 6] mesmo usuário é support no SaaS e doctor no cliente', function () {
    createEntityUser($this->saasEntity, $this->user, 'support');
    createEntityUser($this->clientEntity, $this->user, 'doctor');

    // No SaaS: acessa suporte, não acessa doctor
    $this->actingAs($this->user)
        ->withSession(['selected_entity_id' => $this->saasEntity->id])
        ->getJson('/_test/saas-support')
        ->assertOk();

    $this->actingAs($this->user)
        ->withSession(['selected_entity_id' => $this->saasEntity->id])
        ->getJson('/_test/doctor-only')
        ->assertForbidden();

    // No cliente: acessa doctor, não acessa saas-support (role não bate)
    $this->actingAs($this->user)
        ->withSession(['selected_entity_id' => $this->clientEntity->id])
        ->getJson('/_test/doctor-only')
        ->assertOk();

    $this->actingAs($this->user)
        ->withSession(['selected_entity_id' => $this->clientEntity->id])
        ->getJson('/_test/saas-support')
        ->assertForbidden();
});

// Cenário 7: sem vínculo → middleware nega antes de verificar role
test('[cenário 7] entity.role nega usuário sem vínculo com a entity', function () {
    $this->actingAs($this->user)
        ->withSession(['selected_entity_id' => $this->clientEntity->id])
        ->getJson('/_test/admin-only')
        ->assertForbidden();
});

// =============================================================================
// Respostas web (não JSON)
// =============================================================================

test('entity.member retorna 403 para requisição web sem vínculo', function () {
    $this->actingAs($this->user)
        ->withSession(['selected_entity_id' => $this->clientEntity->id])
        ->get('/_test/member-only')
        ->assertForbidden();
});

test('entity.role retorna 403 para requisição web com role incorreto', function () {
    createEntityUser($this->clientEntity, $this->user, 'doctor');

    $this->actingAs($this->user)
        ->withSession(['selected_entity_id' => $this->clientEntity->id])
        ->get('/_test/admin-only')
        ->assertForbidden();
});
