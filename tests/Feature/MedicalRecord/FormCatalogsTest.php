<?php

declare(strict_types=1);

use App\Enums\ClientRule;
use App\Models\{Covenant, Doctor, Entity, Patient, People, User, VisualAcuityType};
use Database\Seeders\VisualAcuityTypesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Catálogos do formulário do prontuário (buildFormProps → props.catalogs).
 *
 * Cobre os dois ajustes do ticket "Ajustes no Prontuário Médico":
 *  - acuidade visual ordenada da melhor (20/15) pra pior (20/400), depois os
 *    qualitativos (CONTA DEDOS → VULTOS → PL → SPL);
 * e o bug corrigido junto:
 *  - [SEGURANÇA] tipos customizados de uma clínica NÃO vazam pro dropdown
 *    de outra clínica (as queries de catálogo não filtravam entity_id).
 */
beforeEach(function () {
    $this->seed(VisualAcuityTypesSeeder::class);

    $this->entity  = Entity::factory()->create(['is_client' => true]);
    $this->user    = User::factory()->create();
    $covenant      = Covenant::factory()->create();
    $patientPerson = People::factory()->create();
    $this->patient = Patient::create([
        'entity_id'   => $this->entity->id,
        'person_id'   => $patientPerson->id,
        'covenant_id' => $covenant->id,
        'active'      => true,
    ]);

    $entityUser   = createEntityUser($this->entity, $this->user, ClientRule::Doctor->value);
    $doctorPerson = People::factory()->create();
    Doctor::create([
        'entity_user_id' => $entityUser->id,
        'person_id'      => $doctorPerson->id,
        'record'         => '12345',
        'color'          => '#FF0000',
        'partner'        => false,
        'active'         => true,
    ]);

    $this->actingAs($this->user);
    session(['selected_entity_id' => $this->entity->id]);
});

function acuityNamesFromCreatePage($test): array
{
    $response = $test->get(route('panel.patients.medicalrecords.create', $test->patient));
    $response->assertOk();

    $props = $response->viewData('page')['props'];

    return array_column($props['catalogs']['visual_acuity_types'], 'name');
}

it('acuidade visual vem ordenada da melhor pra pior, com 20/15 presente e qualitativos no fim', function () {
    $names = acuityNamesFromCreatePage($this);

    // 20/15 existe e vem antes de 20/20; 20/100 vem DEPOIS de 20/80 (ordem
    // clínica por scale, não alfabética — alfabética colocaria 20/100 antes).
    $pos = array_flip($names);

    expect($pos)->toHaveKeys(['20/15', '20/20', '20/80', '20/100', '20/400', 'CONTA DEDOS', 'VULTOS', 'PL', 'SPL'])
        ->and($pos['20/15'])->toBeLessThan($pos['20/20'])
        ->and($pos['20/20'])->toBeLessThan($pos['20/25'])
        ->and($pos['20/80'])->toBeLessThan($pos['20/100'])
        ->and($pos['20/100'])->toBeLessThan($pos['20/200'])
        ->and($pos['20/200'])->toBeLessThan($pos['20/400'])
        ->and($pos['20/400'])->toBeLessThan($pos['CONTA DEDOS'])
        ->and($pos['CONTA DEDOS'])->toBeLessThan($pos['VULTOS'])
        ->and($pos['VULTOS'])->toBeLessThan($pos['PL'])
        ->and($pos['PL'])->toBeLessThan($pos['SPL']);
});

it('[SEGURANÇA] tipo de acuidade customizado de OUTRA clínica não aparece no dropdown', function () {
    $otherEntity = Entity::factory()->create(['is_client' => true]);
    VisualAcuityType::create([
        'entity_id' => $otherEntity->id,
        'scale'     => 0,
        'name'      => 'CUSTOM DA OUTRA CLINICA',
        'active'    => true,
    ]);

    // Customizado da PRÓPRIA clínica aparece.
    VisualAcuityType::create([
        'entity_id' => $this->entity->id,
        'scale'     => 0,
        'name'      => 'CUSTOM DA MINHA CLINICA',
        'active'    => true,
    ]);

    $names = acuityNamesFromCreatePage($this);

    expect($names)->toContain('CUSTOM DA MINHA CLINICA')
        ->and($names)->not->toContain('CUSTOM DA OUTRA CLINICA');
});

it('reseed é idempotente e corrige scales antigos sem duplicar registros globais', function () {
    // Simula banco antigo: 20/20 com scale desatualizado (era 1 antes do 20/15).
    VisualAcuityType::whereNull('entity_id')->where('name', '20/20')->update(['scale' => 1]);

    $before = VisualAcuityType::whereNull('entity_id')->count();
    $this->seed(VisualAcuityTypesSeeder::class);

    expect(VisualAcuityType::whereNull('entity_id')->count())->toBe($before)
        ->and((int) VisualAcuityType::whereNull('entity_id')->where('name', '20/20')->value('scale'))->toBe(2)
        ->and((int) VisualAcuityType::whereNull('entity_id')->where('name', '20/15')->value('scale'))->toBe(1);
});
