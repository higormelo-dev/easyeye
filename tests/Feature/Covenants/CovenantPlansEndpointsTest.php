<?php

use App\Enums\{CovenantSource, SaasRule};
use App\Models\{Covenant, CovenantPlan, Entity, Patient, User};
use Illuminate\Support\Facades\DB;

/**
 * Planos de saúde: endpoints do manager (planos do catálogo global), das
 * configurações da clínica (planos próprios) e a busca do cadastro do
 * paciente — com isolamento entre clínicas.
 */
function plansSaasSession(Entity $saas, string $rule = 'admin'): array
{
    return ['selected_entity_id' => $saas->id, 'selected_entity_is_client' => false, 'selected_entity_user_rule' => $rule];
}

function globalPlan(Covenant $covenant, array $attributes = []): CovenantPlan
{
    return CovenantPlan::withoutGlobalScopes()->forceCreate([
        'entity_id'   => null,
        'covenant_id' => $covenant->id,
        'name'        => 'PLANO ANS',
        'ans_code'    => '471234567',
        'ans_plan_id' => random_int(1, 9_999_999),
        'source'      => CovenantSource::Ans->value,
        'ans_status'  => 'active',
        'active'      => true,
        ...$attributes,
    ]);
}

beforeEach(function () {
    $this->saas  = Entity::factory()->create(['is_client' => false, 'active' => true]);
    $this->admin = User::factory()->create();
    createEntityUser($this->saas, $this->admin, SaasRule::Admin->value);

    $this->operator = Covenant::factory()->create(['name' => 'AMIL', 'ans_registry' => '326305', 'source' => CovenantSource::Ans]);

    $this->clinic = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $this->other  = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $this->user   = User::factory()->create();
    $this->eu     = createEntityUser($this->clinic, $this->user, 'admin');
});

function asPlansAdmin()
{
    return test()->actingAs(test()->admin)->withSession(plansSaasSession(test()->saas));
}

function asClinic()
{
    return test()->actingAs(test()->user)->withSession(panelSession(test()->eu));
}

// ── Manager ──────────────────────────────────────────────────────────────────

it('manager lista os planos globais do convênio com busca e contagem (sem planos de clínica)', function () {
    globalPlan($this->operator, ['name' => 'AMIL ONE S6500', 'ans_code' => '471000001']);
    globalPlan($this->operator, ['name' => 'AMIL S380', 'ans_code' => '472000002', 'active' => false, 'ans_status' => 'cancelled']);
    CovenantPlan::withoutGlobalScopes()->forceCreate([
        'entity_id' => $this->clinic->id, 'covenant_id' => $this->operator->id, 'name' => 'DA CLINICA', 'active' => true,
    ]);

    $response = asPlansAdmin()->getJson(route('manager.covenants.plans.index', $this->operator->id))->assertOk();

    expect(array_column($response->json('data'), 'name'))->toBe(['AMIL ONE S6500', 'AMIL S380'])
        ->and($response->json('counts'))->toBe(['active' => 1, 'total' => 2])
        ->and($response->json('data.0.label'))->toBe('AMIL ONE S6500 (Reg. ANS 471000001)')
        ->and($response->json('data.1.status_label'))->toBe(__('covenant_plans.status_cancelled'));

    expect(array_column(asPlansAdmin()->getJson(route('manager.covenants.plans.index', [$this->operator->id, 'search' => '472']))->json('data'), 'name'))
        ->toBe(['AMIL S380'])
        ->and(array_column(asPlansAdmin()->getJson(route('manager.covenants.plans.index', [$this->operator->id, 'status' => 'active']))->json('data'), 'name'))
        ->toBe(['AMIL ONE S6500']);
});

it('manager cadastra plano manual (global), edita e não mexe em plano da ANS', function () {
    $ans = globalPlan($this->operator);

    asPlansAdmin()->postJson(route('manager.covenants.plans.store', $this->operator->id), ['name' => 'especial', 'ans_code' => '12.345'])
        ->assertCreated();
    $manual = CovenantPlan::withoutGlobalScopes()->where('name', 'ESPECIAL')->sole();
    expect($manual->entity_id)->toBeNull()
        ->and($manual->source)->toBe(CovenantSource::Manual)
        ->and($manual->ans_code)->toBe('12345')
        ->and($manual->code)->toStartWith('PLP');

    asPlansAdmin()->postJson(route('manager.covenants.plans.store', $this->operator->id), ['name' => 'Especial'])
        ->assertJsonValidationErrors(['name' => __('covenant_plans.name_taken')]);

    asPlansAdmin()->putJson(route('manager.covenants.plans.update', $manual->id), ['active' => false])->assertOk();
    expect($manual->fresh()->active)->toBeFalse();

    asPlansAdmin()->putJson(route('manager.covenants.plans.update', $ans->id), ['name' => 'OUTRO'])->assertStatus(422);
    asPlansAdmin()->deleteJson(route('manager.covenants.plans.destroy', $ans->id), ['reason' => 'Tentando excluir plano oficial da ANS.'])
        ->assertStatus(422);
    expect($ans->fresh()->name)->toBe('PLANO ANS');
});

it('manager não cria plano no PARTICULAR', function () {
    $particular = Covenant::withoutGlobalScopes()->whereNull('entity_id')->where('name', 'PARTICULAR')->sole();

    asPlansAdmin()->postJson(route('manager.covenants.plans.store', $particular->id), ['name' => 'PACOTE'])->assertStatus(422);

    expect(CovenantPlan::withoutGlobalScopes()->where('covenant_id', $particular->id)->exists())->toBeFalse();
});

it('[AUDITORIA] manager exclui plano manual sem uso com justificativa; em uso é recusado', function () {
    $free = CovenantPlan::withoutGlobalScopes()->forceCreate([
        'entity_id' => null, 'covenant_id' => $this->operator->id, 'name' => 'LIVRE', 'source' => 'manual', 'active' => true,
    ]);
    $used = CovenantPlan::withoutGlobalScopes()->forceCreate([
        'entity_id' => null, 'covenant_id' => $this->operator->id, 'name' => 'USADO', 'source' => 'manual', 'active' => true,
    ]);
    Patient::factory()->create(['entity_id' => $this->clinic->id, 'covenant_id' => $this->operator->id, 'covenant_plan_id' => $used->id]);

    asPlansAdmin()->deleteJson(route('manager.covenants.plans.destroy', $free->id))->assertJsonValidationErrors('reason');
    asPlansAdmin()->deleteJson(route('manager.covenants.plans.destroy', $used->id), ['reason' => 'Plano cadastrado em duplicidade.'])
        ->assertJsonValidationErrors(['reason' => __('covenant_plans.in_use')]);

    asPlansAdmin()->deleteJson(route('manager.covenants.plans.destroy', $free->id), ['reason' => 'Plano cadastrado em duplicidade.'])
        ->assertOk();

    expect($free->fresh()->trashed())->toBeTrue()
        ->and($used->fresh()->trashed())->toBeFalse()
        ->and(DB::table('audit_logs')->where('event', 'manager.covenant_plan.destroy')->value('auditable_id'))->toBe($free->id);
});

it('[SEGURANÇA] manager não alcança plano de clínica nem convênio de clínica; suporte não acessa', function () {
    $own = CovenantPlan::withoutGlobalScopes()->forceCreate([
        'entity_id' => $this->clinic->id, 'covenant_id' => $this->operator->id, 'name' => 'DA CLINICA', 'active' => true,
    ]);
    $ownCovenant = Covenant::factory()->create(['entity_id' => $this->clinic->id, 'name' => 'CONVENIO DA CLINICA']);

    asPlansAdmin()->putJson(route('manager.covenants.plans.update', $own->id), ['active' => false])->assertNotFound();
    asPlansAdmin()->getJson(route('manager.covenants.plans.index', $ownCovenant->id))->assertNotFound();
    asPlansAdmin()->postJson(route('manager.covenants.plans.store', $ownCovenant->id), ['name' => 'X'])->assertNotFound();

    $support = User::factory()->create();
    createEntityUser($this->saas, $support, SaasRule::Support->value);
    $this->actingAs($support)->withSession(plansSaasSession($this->saas, SaasRule::Support->value))
        ->getJson(route('manager.covenants.plans.index', $this->operator->id))->assertForbidden();

    expect($own->fresh()->active)->toBeTrue();
});

// ── Busca do cadastro do paciente ────────────────────────────────────────────

it('busca: planos ativos do convênio (globais + da clínica), por nome sem acento ou registro', function () {
    globalPlan($this->operator, ['name' => 'AMIL ONE SAÚDE', 'ans_code' => '471000001']);
    globalPlan($this->operator, ['name' => 'AMIL CANCELADO', 'ans_code' => '471000002', 'active' => false, 'ans_status' => 'cancelled']);
    CovenantPlan::withoutGlobalScopes()->forceCreate([
        'entity_id' => $this->clinic->id, 'covenant_id' => $this->operator->id, 'name' => 'AMIL DA CLINICA', 'active' => true,
    ]);
    CovenantPlan::withoutGlobalScopes()->forceCreate([
        'entity_id' => $this->other->id, 'covenant_id' => $this->operator->id, 'name' => 'AMIL DE OUTRA', 'active' => true,
    ]);

    $names = fn (array $q) => array_column(asClinic()->getJson(route('panel.covenant-plans.search', ['covenant_id' => $this->operator->id, ...$q]))
        ->assertOk()->json('data'), 'name');

    expect($names([]))->toBe(['AMIL DA CLINICA', 'AMIL ONE SAÚDE'])
        ->and($names(['q' => 'saude']))->toBe(['AMIL ONE SAÚDE'])
        ->and($names(['q' => '4710']))->toBe(['AMIL ONE SAÚDE'])
        ->and($names(['q' => '%']))->toBe([]);
});

it('[SEGURANÇA] busca não revela convênio de outra clínica e valida o pedido', function () {
    $foreign = Covenant::factory()->create(['entity_id' => $this->other->id, 'name' => 'SO DA OUTRA']);
    CovenantPlan::withoutGlobalScopes()->forceCreate([
        'entity_id' => $this->other->id, 'covenant_id' => $foreign->id, 'name' => 'SECRETO', 'active' => true,
    ]);

    expect(asClinic()->getJson(route('panel.covenant-plans.search', ['covenant_id' => $foreign->id]))->assertOk()->json('data'))->toBe([]);
    asClinic()->getJson(route('panel.covenant-plans.search', ['covenant_id' => 'nao-e-uuid']))->assertJsonValidationErrors('covenant_id');
});

// ── Configurações da clínica (planos próprios) ───────────────────────────────

it('clínica cadastra plano próprio; lista só os dela (não os 66 mil da ANS)', function () {
    globalPlan($this->operator, ['name' => 'DA ANS']);

    asClinic()->postJson(route('panel.setting.covenant-plans.store'), [
        'covenant_id' => $this->operator->id, 'name' => 'plano empresa x', 'ans_code' => '123',
    ])->assertSuccessful();

    $plan = CovenantPlan::withoutGlobalScopes()->where('name', 'PLANO EMPRESA X')->sole();
    expect($plan->entity_id)->toBe($this->clinic->id)
        ->and($plan->covenant_id)->toBe($this->operator->id)
        ->and($plan->code)->toStartWith('PL-');

    asClinic()->get(route('panel.setting.covenant-plans.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('items.data', fn ($rows) => collect($rows)->pluck('name')->all() === ['PLANO EMPRESA X'])
            ->where('items.data.0.covenant_name', 'AMIL')
            ->where('meta.total', 1));
});

it('mesmo nome em convênios diferentes não se sobrescreve; convênio do plano não muda', function () {
    $other = Covenant::factory()->create(['name' => 'BRADESCO', 'ans_registry' => '005711', 'source' => CovenantSource::Ans]);

    asClinic()->postJson(route('panel.setting.covenant-plans.store'), ['covenant_id' => $this->operator->id, 'name' => 'BÁSICO'])->assertSuccessful();
    asClinic()->postJson(route('panel.setting.covenant-plans.store'), ['covenant_id' => $other->id, 'name' => 'BÁSICO'])->assertSuccessful();

    $plans = CovenantPlan::withoutGlobalScopes()->where('name', 'BÁSICO')->get();
    expect($plans->pluck('covenant_id')->sort()->values()->all())->toBe(collect([$this->operator->id, $other->id])->sort()->values()->all());

    $amil = $plans->firstWhere('covenant_id', $this->operator->id);
    asClinic()->putJson(route('panel.setting.covenant-plans.update', $amil->id), [
        'covenant_id' => $other->id, 'name' => 'BÁSICO', 'active' => true,
    ])->assertJsonValidationErrors(['covenant_id' => __('covenant_plans.covenant_locked')]);

    asClinic()->putJson(route('panel.setting.covenant-plans.update', $amil->id), [
        'covenant_id' => $this->operator->id, 'name' => 'BÁSICO PLUS', 'active' => false,
    ])->assertSuccessful();
    expect($amil->fresh()->name)->toBe('BÁSICO PLUS')->and($amil->fresh()->active)->toBeFalse();
});

it('[SEGURANÇA] clínica não usa convênio de outra, não edita plano global nem de outra clínica', function () {
    $foreign      = Covenant::factory()->create(['entity_id' => $this->other->id, 'name' => 'SO DA OUTRA']);
    $global       = globalPlan($this->operator);
    $otherClinics = CovenantPlan::withoutGlobalScopes()->forceCreate([
        'entity_id' => $this->other->id, 'covenant_id' => $this->operator->id, 'name' => 'DA OUTRA', 'active' => true,
    ]);

    asClinic()->postJson(route('panel.setting.covenant-plans.store'), ['covenant_id' => $foreign->id, 'name' => 'X'])
        ->assertJsonValidationErrors('covenant_id');
    asClinic()->putJson(route('panel.setting.covenant-plans.update', $global->id), ['name' => 'HACK'])->assertNotFound();
    asClinic()->deleteJson(route('panel.setting.covenant-plans.destroy', $global->id))->assertNotFound();
    asClinic()->putJson(route('panel.setting.covenant-plans.update', $otherClinics->id), ['name' => 'HACK'])->assertNotFound();

    expect($global->fresh()->name)->toBe('PLANO ANS')->and($otherClinics->fresh()->name)->toBe('DA OUTRA');
});
