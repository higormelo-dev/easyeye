<?php

declare(strict_types=1);

use App\Enums\ClientRule;
use App\Models\{Entity, Indication, Procedure, User};
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->entity = Entity::factory()->create(['is_client' => true]);
    $this->user   = User::factory()->create();
    createEntityUser($this->entity, $this->user, ClientRule::Doctor->value);

    $this->actingAs($this->user);
    session(['selected_entity_id' => $this->entity->id]);
});

// ── Procedure search ─────────────────────────────────────────────────────────

it('procedures.search retorna [] com query < 2 chars', function () {
    Procedure::factory()->create(['entity_id' => $this->entity->id, 'name' => 'Facoemulsificação']);

    $r = $this->getJson(route('panel.procedures.search', ['q' => 'f']));

    $r->assertOk();
    expect($r->json())->toBe([]);
});

it('procedures.search busca da entidade + globais por name e code', function () {
    $entity2 = Entity::factory()->create(['is_client' => true]);

    Procedure::factory()->create(['entity_id' => $this->entity->id, 'name' => 'Facoemulsificação']);
    Procedure::factory()->create(['entity_id' => null, 'name' => 'Faciotomia']);
    Procedure::factory()->create(['entity_id' => $entity2->id, 'name' => 'Facectomia']); // outra entidade

    $r = $this->getJson(route('panel.procedures.search', ['q' => 'fac']));

    $names = collect($r->json())->pluck('name')->all();

    expect($names)->toContain('Facoemulsificação')
        ->and($names)->toContain('Faciotomia')
        ->and($names)->not->toContain('Facectomia');
});

it('procedures.search ignora inativos', function () {
    Procedure::factory()->inactive()->create(['entity_id' => $this->entity->id, 'name' => 'Inativo XYZ']);

    $r = $this->getJson(route('panel.procedures.search', ['q' => 'inat']));

    expect($r->json())->toBe([]);
});

it('procedures.search limita 20 resultados', function () {
    for ($i = 0; $i < 25; $i++) {
        Procedure::factory()->create(['entity_id' => $this->entity->id, 'name' => "Teste{$i}"]);
    }

    $r = $this->getJson(route('panel.procedures.search', ['q' => 'test']));

    expect(count($r->json()))->toBe(20);
});

// ── Indication search ────────────────────────────────────────────────────────

it('indications.search retorna [] com query < 2 chars', function () {
    Indication::factory()->create(['entity_id' => $this->entity->id, 'description' => 'Glaucoma']);

    $r = $this->getJson(route('panel.indications.search', ['q' => 'g']));

    expect($r->json())->toBe([]);
});

it('indications.search busca por description (entidade + globais)', function () {
    $entity2 = Entity::factory()->create(['is_client' => true]);

    Indication::factory()->create(['entity_id' => $this->entity->id, 'description' => 'Suspeita glaucoma']);
    Indication::factory()->create(['entity_id' => null, 'description' => 'Glaucoma agudo']);
    Indication::factory()->create(['entity_id' => $entity2->id, 'description' => 'Glaucoma neovascular']); // fora

    $r = $this->getJson(route('panel.indications.search', ['q' => 'glauc']));

    $descs = collect($r->json())->pluck('description')->all();

    expect($descs)->toContain('Suspeita glaucoma')
        ->and($descs)->toContain('Glaucoma agudo')
        ->and($descs)->not->toContain('Glaucoma neovascular');
});

it('indications.search ignora inativas', function () {
    Indication::factory()->inactive()->create(['entity_id' => $this->entity->id, 'description' => 'Desativada']);

    $r = $this->getJson(route('panel.indications.search', ['q' => 'desa']));

    expect($r->json())->toBe([]);
});
