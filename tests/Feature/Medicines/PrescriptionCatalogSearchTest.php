<?php

use App\Enums\{ClientRule, MedicineSource};
use App\Models\{Entity, Medicine, MedicinePresentation, User};

/**
 * Busca do receituário no catálogo global (curados + CMED/Anvisa):
 * nome comercial OU genérico, palavra a palavra, oftálmicos primeiro.
 */
function catalogItem(array $attributes): Medicine
{
    return Medicine::withoutGlobalScopes()->create(array_merge([
        'entity_id' => null,
        'active'    => true,
        'source'    => MedicineSource::Cmed,
    ], $attributes));
}

beforeEach(function () {
    $this->entity = Entity::factory()->create(['is_client' => true]);
    $this->user   = User::factory()->create();
    $eu           = createEntityUser($this->entity, $this->user, ClientRule::Doctor->value);

    $this->actingAs($this->user)->withSession(panelSession($eu));

    $this->predoptic = catalogItem([
        'name'                => 'PREDOPTIC', 'active_ingredient' => 'acetato de prednisolona', 'concentration' => '10 MG/ML',
        'pharmaceutical_form' => 'susp_oft', 'presentation_detail' => '10 MG/ML SUS OFT CT FR GOT X 5 ML',
        'laboratory'          => 'GEOLAB', 'regulatory_category' => 'Similar', 'is_ophthalmic' => true, 'source_code' => '1',
    ]);
    $this->oral = catalogItem([
        'name'                => 'PREDNISOLONA', 'active_ingredient' => 'prednisolona', 'concentration' => '20 MG',
        'pharmaceutical_form' => 'com', 'source_code' => '2',
    ]);
});

function searchCatalog(string $q): array
{
    return test()->getJson(route('panel.medicines.search', ['q' => $q]))->assertOk()->json();
}

it('acha o nome comercial digitando o genérico (sem acento)', function () {
    expect(collect(searchCatalog('acetato prednis'))->pluck('name')->all())->toBe(['PREDOPTIC']);
});

it('cada palavra filtra: "pred colirio" só traz a forma oftálmica', function () {
    expect(collect(searchCatalog('pred colirio'))->pluck('id')->all())->toBe([$this->predoptic->id]);
});

it('oftálmico vem antes do sistêmico', function () {
    expect(collect(searchCatalog('pred'))->pluck('id')->all())->toBe([$this->predoptic->id, $this->oral->id]);
});

it('curado (com posologia sugerida) vem primeiro', function () {
    $curated = catalogItem(['name' => 'PREDNISOLONA 1%', 'source' => MedicineSource::Manual, 'dosage' => '1 gota']);

    expect(searchCatalog('pred')[0]['id'])->toBe($curated->id);
});

it('item curado é achado pela apresentação ("colirio")', function () {
    $colirio = MedicinePresentation::withoutGlobalScopes()->create(['name' => 'Colírio', 'active' => true]);
    $curated = catalogItem([
        'name'                     => 'LATANOPROSTA 0,005%',
        'source'                   => MedicineSource::Manual,
        'medicine_presentation_id' => $colirio->id,
    ]);

    expect(collect(searchCatalog('colirio latano'))->pluck('id')->all())->toBe([$curated->id]);
});

it('devolve genérico, apresentação, laboratório e tipo pra diferenciar apresentações', function () {
    $item = collect(searchCatalog('predoptic'))->first();

    expect($item)->toMatchArray([
        'name'                => 'PREDOPTIC',
        'active_ingredient'   => 'acetato de prednisolona',
        'concentration'       => '10 MG/ML',
        'presentation'        => __('medicine_forms.susp_oft'),
        'presentation_detail' => '10 MG/ML SUS OFT CT FR GOT X 5 ML',
        'laboratory'          => 'GEOLAB',
        'category'            => 'Similar',
        'is_ophthalmic'       => true,
    ]);
});

it('não traz inativo (saiu da lista CMED)', function () {
    $this->predoptic->update(['active' => false]);

    expect(searchCatalog('predoptic'))->toBe([]);
});

it('linha da receita de item CMED leva concentração, forma e genérico', function () {
    $line = $this->postJson(route('panel.medication-prescription.format-line'), [
        'medicine_id' => $this->predoptic->id,
        'posology'    => '1 gota de 6/6h por 7 dias',
    ])->assertOk()->json('line');

    expect($line)->toBe("- PREDOPTIC 10 MG/ML suspensão oftálmica (acetato de prednisolona)\n  1 gota de 6/6h por 7 dias\n\n");
});

it('genérico com nome igual ao princípio ativo não repete o nome', function () {
    $line = $this->postJson(route('panel.medication-prescription.format-line'), [
        'medicine_id' => $this->oral->id,
    ])->assertOk()->json('line');

    expect($line)->toBe("- PREDNISOLONA 20 MG comprimido\n\n");
});
