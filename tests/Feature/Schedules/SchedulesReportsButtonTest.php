<?php

declare(strict_types=1);

use App\Enums\ClientRule;
use App\Models\{Entity, User};

/**
 * GAP fechado (revisão "relatório direto no módulo"): dropdown "Relatórios"
 * ao lado do botão "Novo" em Panel/Schedules/Index.vue —
 * App\Http\Controllers\SchedulesController::index() prop `reportsUrls`.
 * Mesmo gate (EntityGate::ViewFinancial) das rotas
 * panel.schedules.reports.production/absenteeism — ver
 * App\Http\Controllers\ReportsController.
 */
beforeEach(function () {
    $this->entity          = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $this->adminUser       = User::factory()->create();
    $this->adminEntityUser = createEntityUser($this->entity, $this->adminUser, ClientRule::Admin->value);
});

it('[GAP] admin recebe reportsUrls com as 2 rotas de relatório de agenda', function () {
    $res = $this->actingAs($this->adminUser)
        ->withSession(panelSession($this->adminEntityUser))
        ->get(route('panel.schedules.index'));

    $res->assertOk();
    $res->assertInertia(fn ($page) => $page
        ->component('Panel/Schedules/Index')
        ->where('reportsUrls.production', route('panel.schedules.reports.production'))
        ->where('reportsUrls.absenteeism', route('panel.schedules.reports.absenteeism')));
});

it('[GAP] secretária (sem acesso financeiro) recebe reportsUrls null — botão nem renderiza', function () {
    $secretary           = User::factory()->create();
    $secretaryEntityUser = createEntityUser($this->entity, $secretary, ClientRule::Secretary->value);

    $res = $this->actingAs($secretary)
        ->withSession(panelSession($secretaryEntityUser))
        ->get(route('panel.schedules.index'));

    $res->assertOk();
    $res->assertInertia(fn ($page) => $page
        ->component('Panel/Schedules/Index')
        ->where('reportsUrls', null));
});
