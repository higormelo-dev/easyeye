<?php

/**
 * Política de acesso à gestão de MÉDICOS (decisão de produto 29/08/2026,
 * flagrada pelo E2E Cypress): o rule doctor NÃO lista/gerencia o cadastro de
 * médicos — gestão administrativa (admin/secretária/financeiro). O menu já
 * escondia; este teste garante que a ROTA nega (defesa real, não só UI).
 */

use App\Models\{Entity, User};

function clinicSession(Entity $entity, string $rule): array
{
    return [
        'selected_entity_id'        => $entity->id,
        'selected_entity_is_client' => true,
        'selected_entity_user_rule' => $rule,
    ];
}

beforeEach(function () {
    $this->clinic = Entity::factory()->create(['is_client' => true]);
});

test('doctor NÃO acessa a listagem de médicos', function () {
    $user = User::factory()->create();
    createEntityUser($this->clinic, $user, 'doctor');

    $this->actingAs($user)
        ->withSession(clinicSession($this->clinic, 'doctor'))
        ->get(route('panel.doctors.index'))
        ->assertForbidden();

    $this->actingAs($user)
        ->withSession(clinicSession($this->clinic, 'doctor'))
        ->getJson(route('panel.doctors.cards'))
        ->assertForbidden();
});

test('secretary, financial e admin seguem acessando médicos', function (string $rule) {
    $user = User::factory()->create();
    createEntityUser($this->clinic, $user, $rule);

    $this->actingAs($user)
        ->withSession(clinicSession($this->clinic, $rule))
        ->get(route('panel.doctors.index'))
        ->assertOk();
})->with(['secretary', 'financial', 'admin']);

test('doctor mantém acesso a pacientes (bloco separado intacto)', function () {
    $user = User::factory()->create();
    createEntityUser($this->clinic, $user, 'doctor');

    $this->actingAs($user)
        ->withSession(clinicSession($this->clinic, 'doctor'))
        ->get(route('panel.patients.index'))
        ->assertOk();
});
