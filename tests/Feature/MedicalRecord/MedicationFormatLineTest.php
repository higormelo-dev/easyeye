<?php

declare(strict_types=1);

use App\Enums\ClientRule;
use App\Models\{Entity, Medicine, MedicinePresentation, User};
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * F5 — endpoint format-line: gera linha formatada do medicamento p/ append no
 * textarea do builder.
 */
beforeEach(function () {
    $this->entity = Entity::factory()->create(['is_client' => true]);
    $this->user   = User::factory()->create();
    createEntityUser($this->entity, $this->user, ClientRule::Doctor->value);

    $this->actingAs($this->user);
    session(['selected_entity_id' => $this->entity->id]);
});

it('formata linha completa com presentation, dosage, frequency, duration, instructions', function () {
    $pres     = MedicinePresentation::create(['name' => 'Frasco 10ml', 'active' => true]);
    $medicine = Medicine::create([
        'entity_id'                => $this->entity->id,
        'medicine_presentation_id' => $pres->id,
        'name'                     => 'Tobramicina',
        'dosage'                   => '1 gota',
        'frequency'                => '4x ao dia',
        'duration'                 => '7 dias',
        'instructions'             => 'Pingar nos dois olhos',
        'active'                   => true,
    ]);

    $r = $this->postJson(route('panel.medication-prescription.format-line'), [
        'medicine_id' => $medicine->id,
    ]);

    $r->assertOk();
    expect($r->json('line'))
        ->toContain('- Tobramicina (Frasco 10ml)')
        ->toContain('1 gota 4x ao dia por 7 dias')
        ->toContain('Obs: Pingar nos dois olhos');
});

it('rejeita medicine_id inválido', function () {
    $r = $this->postJson(route('panel.medication-prescription.format-line'), [
        'medicine_id' => 'not-a-uuid',
    ]);
    $r->assertStatus(422);
});

it('404 para medicine de outra entidade', function () {
    $other = Entity::factory()->create(['is_client' => true]);
    $med   = Medicine::create([
        'entity_id' => $other->id,
        'name'      => 'Outro',
        'active'    => true,
    ]);

    $r = $this->postJson(route('panel.medication-prescription.format-line'), [
        'medicine_id' => $med->id,
    ]);
    $r->assertNotFound();
});

it('aceita medicines globais (sem entity_id)', function () {
    $med = Medicine::create([
        'entity_id' => null,
        'name'      => 'Soro Fisiológico Global',
        'active'    => true,
    ]);

    $r = $this->postJson(route('panel.medication-prescription.format-line'), [
        'medicine_id' => $med->id,
    ]);

    $r->assertOk();
    expect($r->json('line'))->toContain('Soro Fisiológico Global');
});
