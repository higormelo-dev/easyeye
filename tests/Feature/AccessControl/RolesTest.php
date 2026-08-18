<?php

use App\Enums\{ClientRule, Permission as PermissionEnum};
use App\Http\Middleware\EnsureEntityPermission;
use App\Models\{Entity, PermissionRecord, Role, User};
use Database\Seeders\PermissionsSeeder;
use Illuminate\Http\Request;

/**
 * CRUD de Roles customizadas (RBAC granular ADITIVO) — App\Http\Controllers\AccessControl\RolesController.
 *
 * Prioridade #1 (mais crítica): anti-escalação de privilégio — a gestão do
 * próprio RBAC ("chave do cofre") é hardcoded `entity.role:admin`, NUNCA
 * delegável via Permission customizada, nem que a Role tenha todas as
 * permissions existentes atribuídas.
 *
 * Setup espelha tests/Feature/EyeImages/DiagnosisRegistrationTest.php
 * (helpers createEntityUser/panelSession de tests/Pest.php).
 */
beforeEach(function () {
    // Catálogo global `permissions` — RolesController/RoleRequest dependem
    // dele existir (Rule::exists('permissions', 'id')). PermissionsSeeder é
    // idempotente (firstOrCreate por key), mesma fonte usada em produção.
    $this->seed(PermissionsSeeder::class);

    $this->entity = Entity::factory()->create(['is_client' => true, 'active' => true]);

    $this->admin           = User::factory()->create();
    $this->adminEntityUser = createEntityUser($this->entity, $this->admin, ClientRule::Admin->value);

    $this->secretary           = User::factory()->create();
    $this->secretaryEntityUser = createEntityUser($this->entity, $this->secretary, ClientRule::Secretary->value);
});

/**
 * Cria uma Role diretamente no banco (sem factory dedicada — mesmo padrão
 * usado pelo próprio RolesController::store()).
 */
function createRole(Entity $entity, array $attrs = []): Role
{
    return Role::query()->create(array_merge([
        'entity_id'   => $entity->id,
        'name'        => 'Perfil ' . Str::random(10),
        'description' => null,
    ], $attrs));
}

// =============================================================================
// 1. ANTI-ESCALAÇÃO DE PRIVILÉGIO (CRÍTICO)
// =============================================================================

it('[CRÍTICO] não-admin com Role customizada contendo TODAS as permissions (inclusive settings.manage) continua recebendo 403 no CRUD de Roles — chave do cofre nunca delegável', function () {
    $allPermissionIds = PermissionRecord::query()->pluck('id')->all();
    expect($allPermissionIds)->not->toBeEmpty();

    $superRole = createRole($this->entity, ['name' => 'Super Perfil (todas as permissions)']);
    $superRole->permissions()->sync($allPermissionIds);
    $this->secretaryEntityUser->roles()->sync([$superRole->id]);

    $targetRole = createRole($this->entity, ['name' => 'Alvo']);

    // index
    $this->actingAs($this->secretary)->withSession(panelSession($this->secretaryEntityUser))
        ->getJson(route('panel.accesscontrol.roles.index'))
        ->assertForbidden();

    // store
    $this->actingAs($this->secretary)->withSession(panelSession($this->secretaryEntityUser))
        ->postJson(route('panel.accesscontrol.roles.store'), [
            'name'           => 'Tentativa de escalonamento',
            'permission_ids' => $allPermissionIds,
        ])
        ->assertForbidden();

    // update
    $this->actingAs($this->secretary)->withSession(panelSession($this->secretaryEntityUser))
        ->putJson(route('panel.accesscontrol.roles.update', $targetRole), ['name' => 'Hackeado'])
        ->assertForbidden();

    // destroy
    $this->actingAs($this->secretary)->withSession(panelSession($this->secretaryEntityUser))
        ->deleteJson(route('panel.accesscontrol.roles.destroy', $targetRole))
        ->assertForbidden();

    // nada mudou no banco
    expect(Role::where('name', 'Tentativa de escalonamento')->exists())->toBeFalse();
    expect(Role::find($targetRole->id)->name)->toBe('Alvo');
    expect(Role::find($targetRole->id))->not->toBeNull();
});

// =============================================================================
// 2. Admin cria Role com permissions vinculadas
// =============================================================================

it('admin cria Role com nome/descrição/permission_ids e a permission fica vinculada em role_permission', function () {
    $settingsPermission  = PermissionRecord::where('key', PermissionEnum::SettingsManage->value)->firstOrFail();
    $financialPermission = PermissionRecord::where('key', PermissionEnum::FinancialView->value)->firstOrFail();

    $response = $this->actingAs($this->admin)->withSession(panelSession($this->adminEntityUser))
        ->postJson(route('panel.accesscontrol.roles.store'), [
            'name'           => 'Financeiro Avançado',
            'description'    => 'Acesso a configurações e financeiro',
            'permission_ids' => [$settingsPermission->id, $financialPermission->id],
        ]);

    $response->assertOk();
    $roleId = $response->json('data.id');
    expect($roleId)->not->toBeNull();

    $role = Role::with('permissions')->findOrFail($roleId);

    expect((string) $role->entity_id)->toBe((string) $this->entity->id);
    expect($role->name)->toBe('Financeiro Avançado');
    expect($role->description)->toBe('Acesso a configurações e financeiro');
    expect($role->permissions->pluck('key')->sort()->values()->all())
        ->toBe([PermissionEnum::FinancialView->value, PermissionEnum::SettingsManage->value]);

    $this->assertDatabaseHas('role_permission', ['role_id' => $role->id, 'permission_id' => $settingsPermission->id]);
    $this->assertDatabaseHas('role_permission', ['role_id' => $role->id, 'permission_id' => $financialPermission->id]);
});

// =============================================================================
// 3. Admin edita/remove permissions de Role existente — sync substitui
// =============================================================================

it('admin edita Role existente e sync de permission_ids substitui, não soma', function () {
    $settingsPermission  = PermissionRecord::where('key', PermissionEnum::SettingsManage->value)->firstOrFail();
    $financialPermission = PermissionRecord::where('key', PermissionEnum::FinancialView->value)->firstOrFail();
    $examsPermission     = PermissionRecord::where('key', PermissionEnum::ExamsImport->value)->firstOrFail();

    $role = createRole($this->entity, ['name' => 'Perfil X']);
    $role->permissions()->sync([$settingsPermission->id, $financialPermission->id]);

    $this->actingAs($this->admin)->withSession(panelSession($this->adminEntityUser))
        ->putJson(route('panel.accesscontrol.roles.update', $role), [
            'name'           => 'Perfil X', // mesmo nome — ignore() do Rule::unique não deve barrar
            'permission_ids' => [$examsPermission->id],
        ])
        ->assertOk();

    $role->refresh()->load('permissions');

    expect($role->permissions->pluck('id')->all())->toBe([$examsPermission->id]);
    $this->assertDatabaseMissing('role_permission', ['role_id' => $role->id, 'permission_id' => $settingsPermission->id]);
    $this->assertDatabaseMissing('role_permission', ['role_id' => $role->id, 'permission_id' => $financialPermission->id]);
    $this->assertDatabaseHas('role_permission', ['role_id' => $role->id, 'permission_id' => $examsPermission->id]);

    // remover todas as permissions (sync vazio)
    $this->actingAs($this->admin)->withSession(panelSession($this->adminEntityUser))
        ->putJson(route('panel.accesscontrol.roles.update', $role), [
            'name'           => 'Perfil X',
            'permission_ids' => [],
        ])
        ->assertOk();

    expect($role->refresh()->permissions()->count())->toBe(0);
});

// =============================================================================
// 4. Admin exclui Role (soft delete) — usuários perdem a permission
// =============================================================================

it('admin exclui Role (soft delete) e usuários que tinham essa Role perdem a permission depois', function () {
    $settingsPermission = PermissionRecord::where('key', PermissionEnum::SettingsManage->value)->firstOrFail();

    $role = createRole($this->entity, ['name' => 'Perfil Y']);
    $role->permissions()->sync([$settingsPermission->id]);
    $this->secretaryEntityUser->roles()->sync([$role->id]);

    // pré-condição: secretary tem a permission via a Role customizada
    expect(User::find($this->secretary->id)->hasPermissionInEntity($this->entity, PermissionEnum::SettingsManage))
        ->toBeTrue();

    $this->actingAs($this->admin)->withSession(panelSession($this->adminEntityUser))
        ->deleteJson(route('panel.accesscontrol.roles.destroy', $role))
        ->assertOk();

    expect(Role::find($role->id))->toBeNull(); // invisível ao query padrão (soft-deleted)
    expect(Role::withTrashed()->find($role->id)->trashed())->toBeTrue();

    // vínculo entity_user_role removido explicitamente pelo controller
    $this->assertDatabaseMissing('entity_user_role', ['role_id' => $role->id]);

    // pós-condição: secretary perdeu a permission (fresh fetch — sem cache em memória)
    expect(User::find($this->secretary->id)->hasPermissionInEntity($this->entity, PermissionEnum::SettingsManage))
        ->toBeFalse();
});

// =============================================================================
// 5. Isolamento multi-tenant
// =============================================================================

it('isolamento multi-tenant: Role da Entity A não aparece na listagem da Entity B, e admin da Entity B recebe 404 ao editar/excluir via id direto', function () {
    $otherEntity          = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $otherAdmin           = User::factory()->create();
    $otherAdminEntityUser = createEntityUser($otherEntity, $otherAdmin, ClientRule::Admin->value);

    $roleA = createRole($this->entity, ['name' => 'Perfil da Entity A']);
    $roleB = createRole($otherEntity, ['name' => 'Perfil da Entity B']);

    // listagem da Entity B só mostra a Role da própria Entity B
    $indexRes = $this->actingAs($otherAdmin)->withSession(panelSession($otherAdminEntityUser))
        ->get(route('panel.accesscontrol.roles.index'));

    $indexRes->assertOk();
    // RoleResource::collection(...)->resolve() desembrulha o "data" (ver
    // RolesController::index() — sem ->resolve() a página quebrava em
    // branco no Vue, que espera um Array puro).
    $indexRes->assertInertia(fn ($page) => $page
        ->has('roles', 1)
        ->where('roles.0.id', $roleB->id));

    // update direto por id: 404 (não 403 — abort_unless(...404) no controller)
    $this->actingAs($otherAdmin)->withSession(panelSession($otherAdminEntityUser))
        ->putJson(route('panel.accesscontrol.roles.update', $roleA), ['name' => 'Tentativa cross-tenant'])
        ->assertNotFound();

    // destroy direto por id: 404
    $this->actingAs($otherAdmin)->withSession(panelSession($otherAdminEntityUser))
        ->deleteJson(route('panel.accesscontrol.roles.destroy', $roleA))
        ->assertNotFound();

    // nada mudou na Role da Entity A
    expect(Role::find($roleA->id)->name)->toBe('Perfil da Entity A');
});

// =============================================================================
// 6. Nome duplicado: mesma entity → 422; entities diferentes → permitido
// =============================================================================

it('nome de Role duplicado na mesma entity retorna 422; o mesmo nome em entities diferentes é permitido', function () {
    createRole($this->entity, ['name' => 'Recepção']);

    $this->actingAs($this->admin)->withSession(panelSession($this->adminEntityUser))
        ->postJson(route('panel.accesscontrol.roles.store'), ['name' => 'Recepção'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('name');

    $otherEntity          = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $otherAdmin           = User::factory()->create();
    $otherAdminEntityUser = createEntityUser($otherEntity, $otherAdmin, ClientRule::Admin->value);

    $this->actingAs($otherAdmin)->withSession(panelSession($otherAdminEntityUser))
        ->postJson(route('panel.accesscontrol.roles.store'), ['name' => 'Recepção'])
        ->assertOk();

    expect(Role::where('name', 'Recepção')->count())->toBe(2);
});

// =============================================================================
// 7. hasPermissionInEntity()
// =============================================================================

it('hasPermissionInEntity: usuário Admin sempre retorna true por bypass, mesmo sem Role customizada', function () {
    foreach (PermissionEnum::cases() as $permission) {
        expect($this->admin->hasPermissionInEntity($this->entity, $permission))->toBeTrue();
    }
});

it('hasPermissionInEntity: usuário não-admin sem Role customizada retorna false para tudo', function () {
    foreach (PermissionEnum::cases() as $permission) {
        expect($this->secretary->hasPermissionInEntity($this->entity, $permission))->toBeFalse();
    }
});

it('hasPermissionInEntity: não-admin com Role customizada contendo Permission::SettingsManage retorna true só pra essa permission', function () {
    $settingsPermission = PermissionRecord::where('key', PermissionEnum::SettingsManage->value)->firstOrFail();

    $role = createRole($this->entity, ['name' => 'Gestor de Configurações']);
    $role->permissions()->sync([$settingsPermission->id]);
    $this->secretaryEntityUser->roles()->sync([$role->id]);

    $freshSecretary = User::find($this->secretary->id);

    expect($freshSecretary->hasPermissionInEntity($this->entity, PermissionEnum::SettingsManage))->toBeTrue();

    foreach (PermissionEnum::cases() as $permission) {
        if ($permission === PermissionEnum::SettingsManage) {
            continue;
        }

        expect($freshSecretary->hasPermissionInEntity($this->entity, $permission))
            ->toBeFalse("Permission [{$permission->value}] deveria ser false");
    }
});

// =============================================================================
// 8. Middleware permission:settings.manage (piloto nos 11 catálogos)
// =============================================================================

it('middleware permission:settings.manage — admin acessa normal, secretary sem Role customizada recebe 403, secretary com Role tendo settings.manage acessa', function () {
    // admin (bypass) acessa normal
    $this->actingAs($this->admin)->withSession(panelSession($this->adminEntityUser))
        ->getJson(route('panel.setting.covenants.index'))
        ->assertOk();

    // secretary sem Role customizada → 403
    $this->actingAs($this->secretary)->withSession(panelSession($this->secretaryEntityUser))
        ->getJson(route('panel.setting.covenants.index'))
        ->assertForbidden();

    // secretary COM Role customizada tendo settings.manage → acessa
    $settingsPermission = PermissionRecord::where('key', PermissionEnum::SettingsManage->value)->firstOrFail();
    $role               = createRole($this->entity, ['name' => 'Gestor de Convênios']);
    $role->permissions()->sync([$settingsPermission->id]);
    $this->secretaryEntityUser->roles()->sync([$role->id]);

    // hasPermissionInEntity() cacheia o resultado em memória por instância de
    // User (ver App\Traits\HasEntityRoles) — em produção cada request HTTP
    // resolve um User novo a partir da sessão, então o cache nunca vaza entre
    // requests reais. actingAs() em teste reaproveita a MESMA instância PHP
    // entre chamadas simuladas dentro do mesmo teste, então buscamos um User
    // fresco aqui para simular corretamente uma nova requisição real.
    $this->actingAs(User::find($this->secretary->id))->withSession(panelSession($this->secretaryEntityUser))
        ->getJson(route('panel.setting.covenants.index'))
        ->assertOk();
});

// =============================================================================
// 9. Atribuição de Role a usuário via PATCH accesscontrol.users.roles.update
// =============================================================================

it('PATCH accesscontrol.users.roles.update: admin atribui/desatribui Roles (sync) e só aceita Roles da mesma entity', function () {
    $roleA = createRole($this->entity, ['name' => 'Perfil A']);
    $roleB = createRole($this->entity, ['name' => 'Perfil B']);

    // atribuir duas Roles
    $this->actingAs($this->admin)->withSession(panelSession($this->adminEntityUser))
        ->patchJson(route('panel.accesscontrol.users.roles.update', $this->secretaryEntityUser->id), [
            'role_ids' => [$roleA->id, $roleB->id],
        ])
        ->assertOk();

    expect($this->secretaryEntityUser->roles()->pluck('roles.id')->sort()->values()->all())
        ->toBe(collect([$roleA->id, $roleB->id])->sort()->values()->all());

    // desatribuir tudo (sync vazio)
    $this->actingAs($this->admin)->withSession(panelSession($this->adminEntityUser))
        ->patchJson(route('panel.accesscontrol.users.roles.update', $this->secretaryEntityUser->id), [
            'role_ids' => [],
        ])
        ->assertOk();

    expect($this->secretaryEntityUser->roles()->count())->toBe(0);

    // Role de outra entity não pode ser atribuída (Rule::exists escopado à entity da sessão)
    $otherEntity = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $foreignRole = createRole($otherEntity, ['name' => 'Perfil de outra clínica']);

    $this->actingAs($this->admin)->withSession(panelSession($this->adminEntityUser))
        ->patchJson(route('panel.accesscontrol.users.roles.update', $this->secretaryEntityUser->id), [
            'role_ids' => [$foreignRole->id],
        ])
        ->assertStatus(422);

    expect($this->secretaryEntityUser->roles()->count())->toBe(0);
});

// =============================================================================
// 10. Permission::from() com key inválida no middleware não derruba a app
// =============================================================================

it('EnsureEntityPermission trata Permission::from() com key inválida graciosamente (403, sem 500)', function () {
    $middleware = new EnsureEntityPermission();

    session(['selected_entity_id' => $this->entity->id]);

    $request = Request::create('/fake-permission-route', 'GET');
    $request->headers->set('Accept', 'application/json');
    $request->setUserResolver(fn () => $this->admin);

    $response = $middleware->handle($request, fn ($req) => response()->json(['ok' => true]), 'chave.que.nao.existe');

    expect($response->getStatusCode())->toBe(403);
    expect($response->headers->get('Content-Type'))->toContain('application/json');
});
