<?php

/**
 * Testa integridade/ownership em rotas aninhadas do Manager SaaS.
 *
 * Contexto: o admin SaaS já tem autoridade sobre todas as clínicas — não há
 * vazamento cross-tenant clássico. O risco é resolver o recurso ERRADO quando
 * os segmentos de uma rota aninhada não são validados como pertencentes uns
 * aos outros (ex.: {integrator} de uma clínica, {userIntegrator} de outra).
 *
 * Cobre os 3 fixes:
 *  1. EntityIntegratorsController::show — {userIntegrator} precisa pertencer
 *     a {entity} antes de resolver {integrator}.
 *  2. EntityIntegratorEquipmentsController::show — mesma cadeia
 *     Entity -> EntityUserIntegrator -> EntityIntegrator.
 *  3. PartnersController::advanceLead — {lead} precisa pertencer a {partner}.
 */

use App\Enums\{PartnerLeadStatus, PartnerType};
use App\Models\{Entity, EntityIntegrator, EntityIntegratorEquipment, EntityUserIntegrator, Partner, PartnerLead, User};
use Illuminate\Support\Str;

beforeEach(function () {
    // Entity SaaS (não-cliente) que o admin usa para operar o painel manager.
    $this->saas  = Entity::factory()->create(['is_client' => false, 'active' => true]);
    $this->admin = User::factory()->create();
    createEntityUser($this->saas, $this->admin, 'admin');
});

/**
 * Sessão mínima para atravessar entity.selected + saas.admin + saas.role nas
 * rotas reais do manager (mesmo padrão de tests/Feature/ACL/SaasRoleMiddlewareTest.php).
 */
function nestedOwnershipAdminSession(Entity $saas): array
{
    return [
        'selected_entity_id'        => $saas->id,
        'selected_entity_is_client' => false,
        'selected_entity_user_rule' => 'admin',
    ];
}

function makePartner(array $overrides = []): Partner
{
    return Partner::create(array_merge([
        'name'            => fake()->company(),
        'email'           => fake()->unique()->companyEmail(),
        'type'            => PartnerType::Distributor->value,
        'commission_rate' => 10.00,
        'token'           => Str::random(32),
        'status'          => 'active',
    ], $overrides));
}

function makePartnerLead(Partner $partner, array $overrides = []): PartnerLead
{
    return PartnerLead::create(array_merge([
        'partner_id' => $partner->id,
        'name'       => fake()->name(),
        'email'      => fake()->unique()->safeEmail(),
        'status'     => PartnerLeadStatus::New->value,
    ], $overrides));
}

test('EntityIntegratorsController::show retorna 404 quando userIntegrator não pertence à entity da URL', function () {
    $entityA = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $entityB = Entity::factory()->create(['is_client' => true, 'active' => true]);

    // userIntegrator + integrator pertencem à entity B.
    $userIntegratorB = EntityUserIntegrator::factory()->create(['entity_id' => $entityB->id]);
    $integratorB     = EntityIntegrator::factory()->create(['entity_user_integrator_id' => $userIntegratorB->id]);

    // URL usa entity A, mas os IDs de userIntegrator/integrator são da entity B.
    $url = route('manager.entities.user-integrators.integrators.show', [
        $entityA->id, $userIntegratorB->id, $integratorB->id,
    ]);

    $this->actingAs($this->admin)
        ->withSession(nestedOwnershipAdminSession($this->saas))
        ->getJson($url)
        ->assertNotFound();
});

test('EntityIntegratorsController::update retorna 404 quando userIntegrator não pertence à entity da URL', function () {
    $entityA = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $entityB = Entity::factory()->create(['is_client' => true, 'active' => true]);

    $userIntegratorB = EntityUserIntegrator::factory()->create(['entity_id' => $entityB->id]);
    $integratorB     = EntityIntegrator::factory()->create(['entity_user_integrator_id' => $userIntegratorB->id]);

    $url = route('manager.entities.user-integrators.integrators.update', [
        $entityA->id, $userIntegratorB->id, $integratorB->id,
    ]);

    $this->actingAs($this->admin)
        ->withSession(nestedOwnershipAdminSession($this->saas))
        ->putJson($url, ['name' => 'Nome Forjado', 'ip' => '10.0.0.1', 'mac' => '00:11:22:33:44:55', 'active' => true])
        ->assertNotFound();
});

test('EntityIntegratorEquipmentsController::show retorna 404 quando userIntegrator não pertence à entity da URL', function () {
    $entityA = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $entityB = Entity::factory()->create(['is_client' => true, 'active' => true]);

    $userIntegratorB = EntityUserIntegrator::factory()->create(['entity_id' => $entityB->id]);
    $integratorB     = EntityIntegrator::factory()->create(['entity_user_integrator_id' => $userIntegratorB->id]);
    $equipmentB      = EntityIntegratorEquipment::factory()->create(['integrator_id' => $integratorB->id]);

    // URL usa entity A, mas userIntegrator/integrator/equipment pertencem à cadeia da entity B.
    $url = route('manager.entities.user-integrators.integrators.equipments.show', [
        $entityA->id, $userIntegratorB->id, $integratorB->id, $equipmentB->id,
    ]);

    $this->actingAs($this->admin)
        ->withSession(nestedOwnershipAdminSession($this->saas))
        ->getJson($url)
        ->assertNotFound();
});

test('PartnersController::advanceLead retorna 404 quando lead pertence a outro partner', function () {
    $partnerA = makePartner();
    $partnerB = makePartner();
    $leadOfB  = makePartnerLead($partnerB);

    $url = route('manager.partners.leads.advance', [$partnerA->id, $leadOfB->id]);

    $this->actingAs($this->admin)
        ->withSession(nestedOwnershipAdminSession($this->saas))
        ->patchJson($url, ['status' => PartnerLeadStatus::Contacted->value])
        ->assertNotFound();

    // Nada deve ter mudado no lead da entity errada.
    expect($leadOfB->fresh()->status)->toBe(PartnerLeadStatus::New);
});

test('PartnersController::advanceLead funciona normalmente quando lead pertence ao partner correto', function () {
    $partner = makePartner();
    $lead    = makePartnerLead($partner);

    $url = route('manager.partners.leads.advance', [$partner->id, $lead->id]);

    $this->actingAs($this->admin)
        ->withSession(nestedOwnershipAdminSession($this->saas))
        ->patchJson($url, ['status' => PartnerLeadStatus::Contacted->value])
        ->assertOk();

    expect($lead->fresh()->status)->toBe(PartnerLeadStatus::Contacted);
});
