<?php

/**
 * Perfis FIXOS da plataforma em banco (system_profiles) — Fases 2-3 do plano
 * "perfis pré-definidos pelo dono do SaaS".
 *
 * Garante:
 *  - a migration create_system_profiles_table seeda o catálogo completo
 *    (5 client + 4 saas) de forma idempotente;
 *  - as telas leem labels/descrições do BANCO (personalização do dono do
 *    SaaS aparece sem deploy) com fallback hardcoded quando a tabela está
 *    vazia;
 *  - as KEYS continuam ancoradas nos enums — linha órfã na tabela nunca
 *    vira opção de rule válida (EntityRoleService faz interseção).
 */

use App\Enums\{ClientRule, SaasRule};
use App\Models\{Entity, SystemProfile};
use App\Models\User;
use App\Services\EntityRoleService;

beforeEach(function () {
    SystemProfile::flushCache();
});

afterEach(function () {
    SystemProfile::flushCache();
});

test('migration seeda o catálogo completo de perfis', function () {
    expect(SystemProfile::query()->context(SystemProfile::CONTEXT_CLIENT)->pluck('key')->sort()->values()->all())
        ->toBe(collect(ClientRule::values())->sort()->values()->all());

    expect(SystemProfile::query()->context(SystemProfile::CONTEXT_SAAS)->pluck('key')->sort()->values()->all())
        ->toBe(collect(SaasRule::values())->sort()->values()->all());
});

test('labelMap lê do banco e reflete personalização do dono do SaaS', function () {
    SystemProfile::query()
        ->context(SystemProfile::CONTEXT_CLIENT)
        ->where('key', 'secretary')
        ->update(['label' => 'Recepção']);

    SystemProfile::flushCache();

    expect(SystemProfile::labelMap(SystemProfile::CONTEXT_CLIENT)['secretary'])->toBe('Recepção');
    expect(SystemProfile::labelFor(SystemProfile::CONTEXT_CLIENT, 'secretary'))->toBe('Recepção');
});

test('labelMap cai no catálogo hardcoded com tabela vazia', function () {
    SystemProfile::query()->delete();
    SystemProfile::flushCache();

    expect(SystemProfile::labelMap(SystemProfile::CONTEXT_CLIENT)['secretary'])->toBe('Secretária');
    expect(SystemProfile::labelMap(SystemProfile::CONTEXT_SAAS)['support'])->toBe('Suporte');
});

test('labelFor devolve a própria key quando desconhecida e vazio para null', function () {
    expect(SystemProfile::labelFor(SystemProfile::CONTEXT_CLIENT, 'ghost-role'))->toBe('ghost-role');
    expect(SystemProfile::labelFor(SystemProfile::CONTEXT_CLIENT, null))->toBe('');
});

test('EntityRoleService monta options do banco e ignora linha órfã', function () {
    // Linha órfã: key que NÃO existe no enum — não pode virar opção de select.
    SystemProfile::query()->create([
        'context'    => SystemProfile::CONTEXT_CLIENT,
        'key'        => 'hacker',
        'label'      => 'Hacker',
        'sort_order' => 99,
    ]);
    SystemProfile::flushCache();

    $clinic  = Entity::factory()->create(['is_client' => true]);
    $options = app(EntityRoleService::class)->validRuleOptions($clinic);

    expect(array_keys($options))->toBe(ClientRule::values());
    expect($options)->not->toHaveKey('hacker');
    expect($options['secretary'])->toBe('Secretária');
});

test('EntityRoleService::labelFor usa o banco para entity SaaS e cliente', function () {
    $saas   = Entity::factory()->create(['is_client' => false]);
    $clinic = Entity::factory()->create(['is_client' => true]);

    $service = app(EntityRoleService::class);

    expect($service->labelFor($saas, 'support'))->toBe('Suporte');
    expect($service->labelFor($clinic, 'doctor'))->toBe('Médico');
    // Rule inválida para o contexto: devolve o valor cru, nunca lança.
    expect($service->labelFor($saas, 'doctor'))->toBe('doctor');
});

test('tela de Perfis de acesso recebe systemProfiles do banco', function () {
    SystemProfile::query()
        ->context(SystemProfile::CONTEXT_CLIENT)
        ->where('key', 'financial')
        ->update(['description' => 'Somente cobrança e caixa.']);

    SystemProfile::flushCache();

    $clinic = Entity::factory()->create(['is_client' => true]);
    $admin  = User::factory()->create();
    createEntityUser($clinic, $admin, 'admin');

    $response = $this->actingAs($admin)
        ->withSession([
            'selected_entity_id'        => $clinic->id,
            'selected_entity_is_client' => true,
        ])
        ->get(route('panel.accesscontrol.roles.index'));

    $response->assertOk();

    $profiles = collect($response->viewData('page')['props']['systemProfiles']);

    expect($profiles)->toHaveCount(count(ClientRule::values()));
    expect($profiles->firstWhere('value', 'financial')['description'])
        ->toBe('Somente cobrança e caixa.');
});
