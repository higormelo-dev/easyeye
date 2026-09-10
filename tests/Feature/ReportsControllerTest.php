<?php

declare(strict_types=1);

use App\Enums\ClientRule;
use App\Models\{Entity, User};
use Illuminate\Support\Facades\Route;

/**
 * App\Http\Controllers\ReportsController — sem cobertura nenhuma antes
 * desta revisão. Foco aqui é o GAP fechado: a página-hub "Relatórios"
 * (index(), Panel/Reports/Index.vue) foi removida — relatórios de
 * agendamento/absenteísmo viram filhos de "Agendas" no menu (ver
 * App\Support\PanelNavigation) — e a URL/nome de rota também migrou pra
 * baixo de `panel.schedules.*` (pedido explícito: URL precisa refletir o
 * dono do dado, não só o menu lateral). Breadcrumb de cada página reflete
 * isso, não aponta mais pra rota removida.
 */
beforeEach(function () {
    $this->entity          = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $this->adminUser       = User::factory()->create();
    $this->adminEntityUser = createEntityUser($this->entity, $this->adminUser, ClientRule::Admin->value);
});

it('[GAP] panel.reports.index não existe mais — página-hub removida', function () {
    expect(Route::has('panel.reports.index'))->toBeFalse();
});

it('[GAP] URL do relatório de produção vive sob /panel/schedules (não mais /panel/reports)', function () {
    expect(Route::has('panel.reports.schedules'))->toBeFalse()
        ->and(Route::has('panel.schedules.reports.production'))->toBeTrue()
        ->and(route('panel.schedules.reports.production'))->toContain('/panel/schedules/reports/production');
});

it('[GAP] URL do relatório de absenteísmo vive sob /panel/schedules (não mais /panel/reports)', function () {
    expect(Route::has('panel.reports.absenteeism'))->toBeFalse()
        ->and(Route::has('panel.schedules.reports.absenteeism'))->toBeTrue()
        ->and(route('panel.schedules.reports.absenteeism'))->toContain('/panel/schedules/reports/absenteeism');
});

it('panel.schedules.reports.production responde 200 com breadcrumb apontando pra Agendas (não mais pra hub removido)', function () {
    $res = $this->actingAs($this->adminUser)
        ->withSession(panelSession($this->adminEntityUser))
        ->get(route('panel.schedules.reports.production'));

    $res->assertOk();
    $res->assertInertia(fn ($page) => $page
        ->component('Panel/Reports/Schedules')
        ->where('breadcrumbs.1.label', 'Agendas')
        ->where('breadcrumbs.1.url', route('panel.schedules.index')));
});

it('panel.schedules.reports.absenteeism responde 200 com breadcrumb apontando pra Agendas (não mais pra hub removido)', function () {
    $res = $this->actingAs($this->adminUser)
        ->withSession(panelSession($this->adminEntityUser))
        ->get(route('panel.schedules.reports.absenteeism'));

    $res->assertOk();
    $res->assertInertia(fn ($page) => $page
        ->component('Panel/Reports/Absenteeism')
        ->where('breadcrumbs.1.label', 'Agendas')
        ->where('breadcrumbs.1.url', route('panel.schedules.index')));
});

it('[ACL] secretária sem financial.manage/admin/financial recebe 403 nos relatórios de agenda', function () {
    $secretary           = User::factory()->create();
    $secretaryEntityUser = createEntityUser($this->entity, $secretary, ClientRule::Secretary->value);

    $this->actingAs($secretary)->withSession(panelSession($secretaryEntityUser))
        ->get(route('panel.schedules.reports.production'), ['Accept' => 'application/json'])
        ->assertForbidden();
});
