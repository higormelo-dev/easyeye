<?php

declare(strict_types=1);

use App\Enums\{ClientRule, Permission};
use App\Models\{Entity, Patient, PermissionRecord, Role, User};
use Database\Seeders\PermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Dashboard: os atalhos (indicadores, atalhos de módulos, "ver agenda",
 * "ver pacientes", boas-vindas) só viram link quando o usuário pode abrir a
 * tela. A prop `access` precisa bater com o middleware REAL de cada rota —
 * o teste compara com a resposta HTTP (sem acesso = 403).
 */
beforeEach(function () {
    $this->entity = Entity::factory()->create(['is_client' => true, 'active' => true]);
});

/** Rota de destino de cada chave de `access`. */
const DASHBOARD_ACCESS_ROUTES = [
    'schedules' => 'panel.schedules.index',
    'patients'  => 'panel.patients.index',
    'doctors'   => 'panel.doctors.index',
    'financial' => 'panel.financial.cash-flow.index',
];

/** Papel customizado da clínica só com as permissões informadas. */
function giveCustomRole($entity, $entityUser, Permission ...$permissions): void
{
    $ids  = PermissionRecord::whereIn('key', array_map(fn (Permission $p) => $p->value, $permissions))->pluck('id');
    $role = Role::query()->create(['entity_id' => $entity->id, 'name' => 'Customizado']);
    $role->permissions()->sync($ids);
    $entityUser->roles()->sync([$role->id]);
}

/** Código HTTP de cada rota de destino, para comparar com `access`. */
function dashboardRouteStatuses($test, User $user, $entityUser, array $routes): array
{
    $statuses = [];

    foreach ($routes as $key => $routeName) {
        $statuses[$key] = $test->actingAs(User::find($user->id))
            ->withSession(panelSession($entityUser))
            ->get(route($routeName))
            ->getStatusCode();
    }

    return $statuses;
}

function dashboardAccessFor($test, User $user, $entityUser): array
{
    $access = null;

    $test->actingAs($user)
        ->withSession(panelSession($entityUser))
        ->get(route('panel.dashboard'))
        ->assertOk()
        ->assertInertia(function ($page) use (&$access) {
            $access = $page->toArray()['props']['access'];
        });

    return $access;
}

it('cada perfil recebe o acesso esperado e ele bate com a resposta real das rotas', function (string $rule, array $expected) {
    $user       = User::factory()->create();
    $entityUser = createEntityUser($this->entity, $user, $rule);

    $access = dashboardAccessFor($this, $user, $entityUser);

    expect($access)->toBe($expected);

    foreach (dashboardRouteStatuses($this, $user, $entityUser, DASHBOARD_ACCESS_ROUTES) as $key => $status) {
        expect($status === 403)->toBe(! $access[$key], "{$rule} → " . DASHBOARD_ACCESS_ROUTES[$key] . " respondeu {$status} com access.{$key}=" . var_export($access[$key], true));
    }
})->with([
    'admin'      => [ClientRule::Admin->value, ['schedules' => true, 'patients' => true, 'doctors' => true, 'financial' => true]],
    'médico'     => [ClientRule::Doctor->value, ['schedules' => true, 'patients' => true, 'doctors' => false, 'financial' => false]],
    'secretária' => [ClientRule::Secretary->value, ['schedules' => true, 'patients' => true, 'doctors' => true, 'financial' => false]],
    'financeiro' => [ClientRule::Financial->value, ['schedules' => false, 'patients' => true, 'doctors' => true, 'financial' => true]],
    'usuário'    => [ClientRule::User->value, ['schedules' => false, 'patients' => false, 'doctors' => false, 'financial' => false]],
]);

it('perfil usuário com a permissão patients.manage passa a abrir Pacientes e Médicos pelo Dashboard', function () {
    $this->seed(PermissionsSeeder::class);

    $user       = User::factory()->create();
    $entityUser = createEntityUser($this->entity, $user, ClientRule::User->value);

    giveCustomRole($this->entity, $entityUser, Permission::PatientsManage);

    $access = dashboardAccessFor($this, User::find($user->id), $entityUser);

    expect($access)->toBe(['schedules' => false, 'patients' => true, 'doctors' => true, 'financial' => false]);

    foreach (dashboardRouteStatuses($this, $user, $entityUser, DASHBOARD_ACCESS_ROUTES) as $key => $status) {
        expect($status === 403)->toBe(! $access[$key], 'patients.manage → ' . DASHBOARD_ACCESS_ROUTES[$key] . " respondeu {$status}");
    }
});

it('permissão financial.manage sem perfil financeiro: sem atalhos do financeiro, porque caixa e faturamento TISS ainda exigem o perfil (403)', function (string $rule) {
    $this->seed(PermissionsSeeder::class);

    $user       = User::factory()->create();
    $entityUser = createEntityUser($this->entity, $user, $rule);
    giveCustomRole($this->entity, $entityUser, Permission::FinancialManage);

    expect(dashboardAccessFor($this, User::find($user->id), $entityUser)['financial'])->toBeFalse();

    $statuses = dashboardRouteStatuses($this, $user, $entityUser, [
        'cash_flow' => 'panel.financial.cash-flow.index',
        'billing'   => 'panel.financial.billing.index',
    ]);

    expect($statuses)->toBe(['cash_flow' => 403, 'billing' => 403]);
})->with([
    'secretária' => [ClientRule::Secretary->value],
    'usuário'    => [ClientRule::User->value],
]);

it('"Ver" dos pacientes recentes abre o cadastro na lista (?open=), só com pacientes da clínica atual', function () {
    $user       = User::factory()->create();
    $entityUser = createEntityUser($this->entity, $user, ClientRule::Admin->value);
    $mine       = Patient::factory()->create(['entity_id' => $this->entity->id, 'active' => true]);
    $other      = Patient::factory()->create(['entity_id' => Entity::factory()->create(['is_client' => true])->id, 'active' => true]);

    $this->actingAs($user)
        ->withSession(panelSession($entityUser))
        ->get(route('panel.dashboard'))
        ->assertOk()
        ->assertInertia(function ($page) use ($mine, $other) {
            $recent = collect($page->toArray()['props']['recentPatients']);

            expect($recent->pluck('id')->all())->toContain($mine->id)->not->toContain($other->id)
                ->and($recent->firstWhere('id', $mine->id)['url'])->toBe(route('panel.patients.index', ['open' => $mine->id]));
        });
});
