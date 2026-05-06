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

it('formata procedimento da entidade com tipo rotina', function () {
    $procedure = Procedure::factory()->create([
        'entity_id' => $this->entity->id,
        'name'      => 'Facoemulsificação',
    ]);

    $r = $this->postJson(route('panel.procedure-solicitation.format-line'), [
        'kind' => 'procedure',
        'id'   => $procedure->id,
        'type' => 'rotina',
    ]);

    $r->assertOk();
    expect($r->json('line'))->toBe('- Facoemulsificação (Rotina)');
});

it('formata procedimento global sem tipo', function () {
    $procedure = Procedure::factory()->create(['entity_id' => null, 'name' => 'Tonometria']);

    $r = $this->postJson(route('panel.procedure-solicitation.format-line'), [
        'kind' => 'procedure',
        'id'   => $procedure->id,
    ]);

    $r->assertOk();
    expect($r->json('line'))->toBe('- Tonometria');
});

it('formata indicação', function () {
    $indication = Indication::factory()->create([
        'entity_id'   => $this->entity->id,
        'description' => 'Suspeita de glaucoma',
    ]);

    $r = $this->postJson(route('panel.procedure-solicitation.format-line'), [
        'kind' => 'indication',
        'id'   => $indication->id,
    ]);

    $r->assertOk();
    expect($r->json('line'))->toBe('- Indicação: Suspeita de glaucoma');
});

it('rejeita kind inválido', function () {
    $r = $this->postJson(route('panel.procedure-solicitation.format-line'), [
        'kind' => 'foo',
        'id'   => fake()->uuid(),
    ]);

    $r->assertStatus(422)->assertJsonValidationErrors(['kind']);
});

it('rejeita type fora do enum', function () {
    $procedure = Procedure::factory()->create(['entity_id' => $this->entity->id]);

    $r = $this->postJson(route('panel.procedure-solicitation.format-line'), [
        'kind' => 'procedure',
        'id'   => $procedure->id,
        'type' => 'inexistente',
    ]);

    $r->assertStatus(422)->assertJsonValidationErrors(['type']);
});

it('retorna 404 quando procedimento pertence a outra entidade', function () {
    $entity2   = Entity::factory()->create(['is_client' => true]);
    $procedure = Procedure::factory()->create(['entity_id' => $entity2->id]);

    $r = $this->postJson(route('panel.procedure-solicitation.format-line'), [
        'kind' => 'procedure',
        'id'   => $procedure->id,
    ]);

    $r->assertNotFound();
});

it('retorna 404 quando indicação pertence a outra entidade', function () {
    $entity2    = Entity::factory()->create(['is_client' => true]);
    $indication = Indication::factory()->create(['entity_id' => $entity2->id]);

    $r = $this->postJson(route('panel.procedure-solicitation.format-line'), [
        'kind' => 'indication',
        'id'   => $indication->id,
    ]);

    $r->assertNotFound();
});

it('rejeita procedure inativo', function () {
    $procedure = Procedure::factory()->inactive()->create(['entity_id' => $this->entity->id]);

    $r = $this->postJson(route('panel.procedure-solicitation.format-line'), [
        'kind' => 'procedure',
        'id'   => $procedure->id,
    ]);

    $r->assertNotFound();
});
