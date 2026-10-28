<?php

declare(strict_types=1);

use App\Enums\ClientRule;
use App\Models\{Entity, User};
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->entity = Entity::factory()->create(['is_client' => true]);
    $this->user   = User::factory()->create();
    createEntityUser($this->entity, $this->user, ClientRule::Doctor->value);

    $this->actingAs($this->user);
    session(['selected_entity_id' => $this->entity->id]);
});

it('retorna rules do StoreMedicalRecordRequest no modo store (default)', function () {
    $r = $this->getJson(route('panel.medical-records.validation-rules'));

    $r->assertOk()
        ->assertJsonPath('mode', 'store')
        ->assertJsonStructure(['mode', 'rules']);

    $rules = $r->json('rules');
    expect($rules)->toHaveKey('doctor_id')
        ->and($rules['doctor_id']['rules'])->toContain('required');
});

it('retorna rules do UpdateMedicalRecordRequest com mode=update', function () {
    $r = $this->getJson(route('panel.medical-records.validation-rules', ['mode' => 'update']));

    $r->assertOk()->assertJsonPath('mode', 'update');

    $rules = $r->json('rules');
    expect($rules['main_complaint']['rules'])->toContain('sometimes');
});

it('rejeita usuários não autenticados', function () {
    auth()->logout();

    $this->getJson(route('panel.medical-records.validation-rules'))
        ->assertUnauthorized(); // JSON → 401, não redirect
});

it('mode desconhecido cai em store', function () {
    $r = $this->getJson(route('panel.medical-records.validation-rules', ['mode' => 'foo']));

    $r->assertOk()->assertJsonPath('mode', 'store');
});

it('exclui regras server-only na resposta JSON', function () {
    $r = $this->getJson(route('panel.medical-records.validation-rules'));

    $rulesForDoctor = $r->json('rules.doctor_id.rules');
    expect($rulesForDoctor)->not->toContain('exists');
});
