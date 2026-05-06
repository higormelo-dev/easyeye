<?php

declare(strict_types=1);

use App\Enums\ClientRule;
use App\Models\{Entity, Medicine, MedicinePresentation, User};
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * F5 — autocomplete de medicamentos para o builder de receita.
 */
beforeEach(function () {
    $this->entity = Entity::factory()->create(['is_client' => true]);
    $this->user   = User::factory()->create();
    createEntityUser($this->entity, $this->user, ClientRule::Doctor->value);

    $this->actingAs($this->user);
    session(['selected_entity_id' => $this->entity->id]);
});

it('retorna [] quando query < 2 chars', function () {
    Medicine::create(['entity_id' => $this->entity->id, 'name' => 'Tobramicina', 'active' => true]);

    $r = $this->getJson(route('panel.medicines.search', ['q' => 'a']));

    $r->assertOk();
    expect($r->json())->toBe([]);
});

it('busca medicines da entidade e globais por nome', function () {
    $entity2 = Entity::factory()->create(['is_client' => true]);

    $own   = Medicine::create(['entity_id' => $this->entity->id, 'name' => 'Dipirona', 'active' => true]);
    $glob  = Medicine::create(['entity_id' => null, 'name' => 'Diclofenaco', 'active' => true]);
    $other = Medicine::create(['entity_id' => $entity2->id, 'name' => 'Diquerex', 'active' => true]); // outra entidade

    $r = $this->getJson(route('panel.medicines.search', ['q' => 'di']));

    $r->assertOk();
    $names = collect($r->json())->pluck('name')->all();

    expect($names)->toContain('Dipirona')
        ->and($names)->toContain('Diclofenaco')
        ->and($names)->not->toContain('Diquerex');
});

it('ignora medicines inativos', function () {
    Medicine::create(['entity_id' => $this->entity->id, 'name' => 'Inativo', 'active' => false]);

    $r = $this->getJson(route('panel.medicines.search', ['q' => 'inat']));

    expect($r->json())->toBe([]);
});

it('inclui apresentação quando existe', function () {
    $pres = MedicinePresentation::create(['name' => 'Frasco 5ml', 'active' => true]);
    Medicine::create([
        'entity_id'                => $this->entity->id,
        'name'                     => 'Brimonidina',
        'medicine_presentation_id' => $pres->id,
        'active'                   => true,
    ]);

    $r = $this->getJson(route('panel.medicines.search', ['q' => 'brim']));

    $r->assertOk();
    expect($r->json(0)['presentation'])->toBe('Frasco 5ml');
});

it('limita a 20 resultados', function () {
    for ($i = 0; $i < 25; $i++) {
        Medicine::create(['entity_id' => $this->entity->id, 'name' => 'Teste' . $i, 'active' => true]);
    }

    $r = $this->getJson(route('panel.medicines.search', ['q' => 'test']));

    expect(count($r->json()))->toBe(20);
});
