<?php

declare(strict_types=1);

use App\Enums\ClientRule;
use App\Models\{Covenant, Doctor, Entity, Lense, MedicalRecord, Patient, People, User};
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Prescrição de lente com múltiplas características (Multifocal +
 * Antirreflexo...): arrays lens_away_ids/lens_near_ids, single legado
 * sincronizado com o 1º item, e exibição "A + B" nos consumidores.
 */
beforeEach(function () {
    $this->entity  = Entity::factory()->create(['is_client' => true]);
    $this->user    = User::factory()->create();
    $covenant      = Covenant::factory()->create();
    $person        = People::factory()->create();
    $this->patient = Patient::create([
        'entity_id'   => $this->entity->id,
        'person_id'   => $person->id,
        'covenant_id' => $covenant->id,
        'active'      => true,
    ]);

    $this->entityUser = createEntityUser($this->entity, $this->user, ClientRule::Doctor->value);
    $entityUser       = $this->entityUser;
    $doctorPerson     = People::factory()->create();
    $this->doctor     = Doctor::create([
        'entity_user_id' => $entityUser->id,
        'person_id'      => $doctorPerson->id,
        'record'         => '12345',
        'color'          => '#FF0000',
        'partner'        => false,
        'active'         => true,
    ]);

    $this->multifocal  = Lense::create(['entity_id' => null, 'name' => 'Multifocal', 'active' => true]);
    $this->antireflexo = Lense::create(['entity_id' => null, 'name' => 'Antirreflexo', 'active' => true]);

    $this->actingAs($this->user);
    session(['selected_entity_id' => $this->entity->id]);
});

it('salva múltiplas características e sincroniza o single legado com o 1º item', function () {
    $response = $this->withSession(panelSession($this->entityUser))
        ->post(route('panel.patients.medicalrecords.store', $this->patient), [
            'doctor_id'      => $this->doctor->id,
            'main_complaint' => 'Consulta de rotina',
            'lens_away_ids'  => [$this->multifocal->id, $this->antireflexo->id],
            'lens_near_ids'  => [$this->antireflexo->id],
        ]);

    $response->assertRedirect();

    $record = MedicalRecord::query()->where('patient_id', $this->patient->id)->firstOrFail();

    expect($record->lens_away_ids)->toBe([$this->multifocal->id, $this->antireflexo->id])
        ->and($record->lens_away_id)->toBe($this->multifocal->id) // legado = 1º
        ->and($record->lens_near_ids)->toBe([$this->antireflexo->id])
        ->and($record->lens_near_id)->toBe($this->antireflexo->id)
        // exibição combinada na ordem escolhida
        ->and($record->lens_away_names_text)->toBe('MULTIFOCAL + ANTIRREFLEXO');
});

it('registro ANTIGO (só single) continua exibindo o nome via fallback', function () {
    $record = MedicalRecord::create([
        'entity_id'    => $this->entity->id,
        'patient_id'   => $this->patient->id,
        'doctor_id'    => $this->doctor->id,
        'lens_away_id' => $this->multifocal->id,
    ]);

    expect($record->fresh()->lens_away_names_text)->toBe('MULTIFOCAL');
});

it('edit expõe os arrays (com fallback do single antigo) para o form', function () {
    $record = MedicalRecord::create([
        'entity_id'    => $this->entity->id,
        'patient_id'   => $this->patient->id,
        'doctor_id'    => $this->doctor->id,
        'lens_away_id' => $this->multifocal->id,
    ]);

    $response = $this->get(route('panel.patients.medicalrecords.edit', [$this->patient, $record]));
    $response->assertOk();

    $props = $response->viewData('page')['props']['medicalrecord'];

    expect($props['lens_away_ids'])->toBe([$this->multifocal->id])
        ->and($props['lens_near_ids'])->toBe([]);
});

it('valida: id de lente inexistente é rejeitado', function () {
    $response = $this->withSession(panelSession($this->entityUser))
        ->post(route('panel.patients.medicalrecords.store', $this->patient), [
            'doctor_id'      => $this->doctor->id,
            'main_complaint' => 'Consulta de rotina',
            'lens_away_ids'  => ['00000000-0000-0000-0000-000000000000'],
        ]);

    $response->assertSessionHasErrors('lens_away_ids.0');
});
