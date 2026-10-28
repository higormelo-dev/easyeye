<?php

declare(strict_types=1);

use App\Enums\ClientRule;
use App\Models\{DoctorMedicationPreset, Entity, Medicine, User};
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Receituário — busca inteligente + posologia:
 *  - presets por médico (minha posologia, favoritos, recentes/uso);
 *  - format-line com posologia editada pelo médico (override);
 *  - escopo: preset é do entity_user; prescrição é ato médico (role doctor).
 */
beforeEach(function () {
    $this->entity     = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $this->user       = User::factory()->create();
    $this->entityUser = createEntityUser($this->entity, $this->user, ClientRule::Doctor->value);

    $this->medicine = Medicine::create([
        'entity_id' => null, // catálogo global
        'name'      => 'PREDNISOLONA COLÍRIO',
        'dosage'    => '1 gota',
        'frequency' => 'de 6/6h',
        'duration'  => '7 dias',
        'active'    => true,
    ]);
});

function asDoctor($test)
{
    return $test->actingAs($test->user)->withSession(panelSession($test->entityUser));
}

it('salvar "minha posologia" e recuperá-la na busca e nas listas', function () {
    asDoctor($this)
        ->postJson(route('panel.medication-presets.posology'), [
            'medicine_id' => $this->medicine->id,
            'posology'    => "1 gota de 4/4h por 10 dias\nObs: agitar antes de usar",
        ])
        ->assertOk();

    // Busca devolve a posologia pessoal junto do medicamento
    $search = asDoctor($this)->getJson(route('panel.medicines.search', ['q' => 'predni']));
    $search->assertOk();
    expect($search->json('0.my_posology'))->toContain('4/4h');

    // Registrar uso alimenta a aba Recentes
    asDoctor($this)->postJson(route('panel.medication-presets.use'), ['medicine_id' => $this->medicine->id])->assertOk();

    $lists = asDoctor($this)->getJson(route('panel.medication-presets.index'));
    $lists->assertOk();
    expect($lists->json('recents.0.name'))->toBe('PREDNISOLONA COLÍRIO')
        ->and($lists->json('recents.0.my_posology'))->toContain('4/4h');
});

it('favoritar alimenta a aba Favoritos; desfavoritar remove', function () {
    asDoctor($this)->postJson(route('panel.medication-presets.favorite'), [
        'medicine_id' => $this->medicine->id, 'favorite' => true,
    ])->assertOk();

    expect(asDoctor($this)->getJson(route('panel.medication-presets.index'))->json('favorites'))->toHaveCount(1);

    asDoctor($this)->postJson(route('panel.medication-presets.favorite'), [
        'medicine_id' => $this->medicine->id, 'favorite' => false,
    ])->assertOk();

    expect(asDoctor($this)->getJson(route('panel.medication-presets.index'))->json('favorites'))->toHaveCount(0);
});

it('format-line usa a posologia EDITADA pelo médico no lugar da genérica', function () {
    $response = asDoctor($this)->postJson(route('panel.medication-prescription.format-line'), [
        'medicine_id' => $this->medicine->id,
        'posology'    => "1 gota de 8/8h por 5 dias\nObs: no olho direito apenas",
    ]);

    $response->assertOk();
    $line = $response->json('line');

    expect($line)->toContain('PREDNISOLONA COLÍRIO')
        ->and($line)->toContain('1 gota de 8/8h por 5 dias')
        ->and($line)->toContain('Obs: no olho direito apenas')
        ->and($line)->not->toContain('6/6h'); // genérica substituída
});

it('format-line SEM override mantém a posologia genérica da base', function () {
    $response = asDoctor($this)->postJson(route('panel.medication-prescription.format-line'), [
        'medicine_id' => $this->medicine->id,
    ]);

    expect($response->json('line'))->toContain('1 gota de 6/6h por 7 dias');
});

it('[SEGURANÇA] preset de um médico não aparece para outro', function () {
    asDoctor($this)->postJson(route('panel.medication-presets.posology'), [
        'medicine_id' => $this->medicine->id, 'posology' => 'posologia do primeiro médico',
    ])->assertOk();

    $otherUser = User::factory()->create();
    $otherEu   = createEntityUser($this->entity, $otherUser, ClientRule::Doctor->value);

    $search = $this->actingAs($otherUser)->withSession(panelSession($otherEu))
        ->getJson(route('panel.medicines.search', ['q' => 'predni']));

    expect($search->json('0.my_posology'))->toBeNull();

    $lists = $this->actingAs($otherUser)->withSession(panelSession($otherEu))
        ->getJson(route('panel.medication-presets.index'));
    expect($lists->json('recents'))->toHaveCount(0);
});

it('[SEGURANÇA] secretária não acessa presets (prescrição é ato médico)', function () {
    $secretary   = User::factory()->create();
    $secretaryEu = createEntityUser($this->entity, $secretary, ClientRule::Secretary->value);

    $this->actingAs($secretary)->withSession(panelSession($secretaryEu))
        ->getJson(route('panel.medication-presets.index'))
        ->assertForbidden();
});

it('[SEGURANÇA] medicamento de outra clínica não upserta preset', function () {
    $otherEntity   = Entity::factory()->create(['is_client' => true]);
    $otherMedicine = Medicine::create([
        'entity_id' => $otherEntity->id,
        'name'      => 'EXCLUSIVO DE OUTRA CLINICA',
        'active'    => true,
    ]);

    asDoctor($this)->postJson(route('panel.medication-presets.favorite'), [
        'medicine_id' => $otherMedicine->id, 'favorite' => true,
    ])->assertNotFound();

    expect(DoctorMedicationPreset::count())->toBe(0);
});
