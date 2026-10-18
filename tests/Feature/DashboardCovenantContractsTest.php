<?php

declare(strict_types=1);

use App\Enums\{ClientRule, Permission};
use App\Http\Controllers\PanelDashboardController;
use App\Models\{Covenant, Entity, EntityUser, PermissionRecord, Role, User};
use Database\Seeders\PermissionsSeeder;
use Inertia\Testing\AssertableInertia;

/**
 * Aviso de contratos de convênio vencidos ou a vencer (prop `covenantContracts`
 * do Dashboard): administração e financeiro recebem; demais perfis não.
 * Só convênios ATIVOS da PRÓPRIA clínica — nunca os globais (ANS) nem os de outra.
 * Relógio fixo ao meio-dia: "hoje" não vira na virada do dia durante o teste.
 */
beforeEach(function () {
    $this->travelTo(now()->setTime(12, 0));

    $this->entity = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $this->other  = Entity::factory()->create(['is_client' => true, 'active' => true]);

    $this->admin       = User::factory()->create();
    $this->adminMember = createEntityUser($this->entity, $this->admin, ClientRule::Admin->value);
});

function dccCovenant(Entity|null $entity, string $name, ?string $endsAt, array $extra = []): Covenant
{
    return Covenant::factory()->create(array_merge([
        'entity_id'        => $entity?->id,
        'name'             => $name,
        'color'            => sprintf('#%06x', random_int(0, 0xFFFFFF)),
        'contract_ends_at' => $endsAt,
    ], $extra));
}

function dccDashboard(User $user, EntityUser $member)
{
    return test()->actingAs(User::find($user->id))
        ->withSession(panelSession($member))
        ->get(route('panel.dashboard'));
}

describe('Dashboard — contratos de convênio', function () {
    it('administrador vê vencido, vencendo e a janela de 30 dias, ordenados pela data de fim', function () {
        dccCovenant($this->entity, 'VENCIDO ONTEM', now()->subDay()->toDateString());
        dccCovenant($this->entity, 'VENCE HOJE', now()->toDateString());
        dccCovenant($this->entity, 'VENCE EM 10', now()->addDays(10)->toDateString());
        dccCovenant($this->entity, 'VENCE EM 30', now()->addDays(30)->toDateString());

        dccDashboard($this->admin, $this->adminMember)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Panel/Dashboard')
                ->where('covenantContracts.window_days', 30)
                ->where('covenantContracts.can_manage', true)
                ->where('covenantContracts.url', route('panel.setting.covenants.index'))
                ->where('covenantContracts.overflow', 0)
                ->has('covenantContracts.items', 4)
                ->where('covenantContracts.items.0.name', 'VENCIDO ONTEM')
                ->where('covenantContracts.items.0.expired', true)
                ->where('covenantContracts.items.0.days_until', -1)
                ->where('covenantContracts.items.1.name', 'VENCE HOJE')
                ->where('covenantContracts.items.1.expired', false)
                ->where('covenantContracts.items.1.days_until', 0)
                ->where('covenantContracts.items.2.days_until', 10)
                ->where('covenantContracts.items.2.ends_at', now()->addDays(10)->toDateString())
                ->where('covenantContracts.items.3.days_until', 30));
    });

    it('deixa de fora o que vence depois da janela, inativo, sem data, excluído, global e de outra clínica', function () {
        dccCovenant($this->entity, 'DENTRO', now()->addDays(5)->toDateString());
        dccCovenant($this->entity, 'FORA DA JANELA', now()->addDays(45)->toDateString());
        dccCovenant($this->entity, 'LIMITE MAIS UM', now()->addDays(31)->toDateString());
        dccCovenant($this->entity, 'INATIVO', now()->subDays(3)->toDateString(), ['active' => false]);
        dccCovenant($this->entity, 'SEM DATA', null);
        dccCovenant($this->entity, 'EXCLUIDO', now()->subDays(3)->toDateString())->delete();
        dccCovenant(null, 'GLOBAL ANS', now()->subDays(3)->toDateString());
        dccCovenant($this->other, 'DE OUTRA CLINICA', now()->subDays(3)->toDateString());

        dccDashboard($this->admin, $this->adminMember)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('covenantContracts.items', 1)
                ->where('covenantContracts.items.0.name', 'DENTRO'));
    });

    it('sem contratos a avisar, a prop é null (o card some)', function () {
        dccCovenant($this->entity, 'LONGE', now()->addDays(90)->toDateString());
        dccCovenant($this->entity, 'SEM DATA', null);

        dccDashboard($this->admin, $this->adminMember)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('covenantContracts', null));
    });

    it('limita a lista a 10 itens e informa o excedente', function () {
        foreach (range(1, 12) as $n) {
            dccCovenant($this->entity, sprintf('CONVENIO %02d', $n), now()->subDays($n)->toDateString());
        }

        dccDashboard($this->admin, $this->adminMember)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('covenantContracts.items', PanelDashboardController::COVENANT_CONTRACT_LIMIT)
                ->where('covenantContracts.overflow', 2)
                // vencidos há mais tempo primeiro
                ->where('covenantContracts.items.0.name', 'CONVENIO 12'));
    });

    it('formata a data pelo idioma da sessão (pt_BR e en)', function () {
        $this->travelTo(now()->setDate(2026, 11, 25)->setTime(12, 0));
        dccCovenant($this->entity, 'VENCE EM 10', '2026-12-05');

        foreach (['pt_BR' => '5 de dezembro de 2026', 'en' => 'December 5, 2026'] as $locale => $label) {
            $this->actingAs(User::find($this->admin->id))
                ->withSession(panelSession($this->adminMember) + ['locale' => $locale])
                ->get(route('panel.dashboard'))
                ->assertOk()
                ->assertInertia(fn (AssertableInertia $page) => $page
                    ->where('covenantContracts.items.0.days_until', 10)
                    ->where('covenantContracts.items.0.ends_at_label', $label));
        }
    });

    it('financeiro vê o aviso, mas sem link quando não tem settings.manage', function () {
        dccCovenant($this->entity, 'VENCIDO', now()->subDay()->toDateString());

        $financial = User::factory()->create();
        $member    = createEntityUser($this->entity, $financial, ClientRule::Financial->value);

        dccDashboard($financial, $member)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('covenantContracts.can_manage', false)
                ->where('covenantContracts.url', null)
                ->has('covenantContracts.items', 1)
                ->where('covenantContracts.items.0.name', 'VENCIDO'));
    });

    it('financeiro com settings.manage recebe o link para Convênios', function () {
        $this->seed(PermissionsSeeder::class);
        dccCovenant($this->entity, 'VENCIDO', now()->subDay()->toDateString());

        $financial = User::factory()->create();
        $member    = createEntityUser($this->entity, $financial, ClientRule::Financial->value);

        $permission = PermissionRecord::where('key', Permission::SettingsManage->value)->firstOrFail();
        $role       = Role::query()->create(['entity_id' => $this->entity->id, 'name' => 'Financeiro com Config']);
        $role->permissions()->sync([$permission->id]);
        $member->roles()->sync([$role->id]);

        dccDashboard($financial, $member)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('covenantContracts.can_manage', true)
                ->where('covenantContracts.url', route('panel.setting.covenants.index')));
    });

    it('médico, secretária e usuário não recebem o aviso', function () {
        dccCovenant($this->entity, 'VENCIDO', now()->subDay()->toDateString());

        foreach ([ClientRule::Doctor, ClientRule::Secretary, ClientRule::User] as $rule) {
            $user   = User::factory()->create();
            $member = createEntityUser($this->entity, $user, $rule->value);

            dccDashboard($user, $member)
                ->assertOk()
                ->assertInertia(fn (AssertableInertia $page) => $page->where('covenantContracts', null));
        }
    });

    it('o aviso só enxerga convênios da clínica da sessão', function () {
        dccCovenant($this->other, 'ALHEIO VENCIDO', now()->subDay()->toDateString());

        $otherAdmin  = User::factory()->create();
        $otherMember = createEntityUser($this->other, $otherAdmin, ClientRule::Admin->value);

        dccDashboard($this->admin, $this->adminMember)
            ->assertInertia(fn (AssertableInertia $page) => $page->where('covenantContracts', null));

        dccDashboard($otherAdmin, $otherMember)
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('covenantContracts.items', 1)
                ->where('covenantContracts.items.0.name', 'ALHEIO VENCIDO'));
    });
});
