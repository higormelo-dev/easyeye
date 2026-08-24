<?php

declare(strict_types=1);

use App\Enums\ClientRule;
use App\Models\{Covenant, Doctor, Entity, MedicalRecord, Patient, People, User};
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Ticket "Simplificar Texto Livre + limpar histórico":
 *  - queixa opcional quando a evolução livre (observation_general) vem preenchida;
 *  - preferência free_text_template (modelo pessoal de texto livre);
 *  - show(): refração toda em default (0.00/0°) some do histórico; bloco com
 *    valor real permanece.
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
    $docPerson        = People::factory()->create();
    $this->doctor     = Doctor::create([
        'entity_user_id' => $this->entityUser->id,
        'person_id'      => $docPerson->id,
        'record'         => '12345',
        'color'          => '#FF0000',
        'partner'        => false,
        'active'         => true,
    ]);

    $this->actingAs($this->user);
    session(['selected_entity_id' => $this->entity->id]);
});

it('texto livre: salva SEM queixa principal quando a evolução livre está preenchida', function () {
    $response = $this->withSession(panelSession($this->entityUser))
        ->post(route('panel.patients.medicalrecords.store', $this->patient), [
            'doctor_id'           => $this->doctor->id,
            'observation_general' => "HDA: paciente relata...\nAP: sem antecedentes\nHD: miopia",
        ]);

    $response->assertRedirect()->assertSessionDoesntHaveErrors('main_complaint');

    $record = MedicalRecord::query()->where('patient_id', $this->patient->id)->firstOrFail();
    expect($record->observation_general)->toContain("HDA: paciente relata...\nAP:");
});

it('modo estruturado continua exigindo a queixa principal', function () {
    $this->withSession(panelSession($this->entityUser))
        ->post(route('panel.patients.medicalrecords.store', $this->patient), [
            'doctor_id' => $this->doctor->id,
        ])
        ->assertSessionHasErrors('main_complaint');
});

it('free_text_template persiste no bag de preferências do médico', function () {
    $template = "HDA:\nAP:\nAV:\nBIO:\nFO:\nHD:\nCD:";

    $this->withSession(panelSession($this->entityUser))
        ->patchJson(route('panel.preferences.update'), ['free_text_template' => $template])
        ->assertOk()
        ->assertJsonPath('data.free_text_template', $template);
});

it('show(): refração TODA em default (0.00/0°) vira null; bloco preenchido permanece', function () {
    $record = MedicalRecord::create([
        'entity_id'      => $this->entity->id,
        'patient_id'     => $this->patient->id,
        'doctor_id'      => $this->doctor->id,
        'main_complaint' => 'Consulta',
        // dinâmica REAL
        'dynamic_spherical_right' => '-1,50', 'dynamic_cylindrical_right' => '-0,50', 'dynamic_axis_right' => '180º',
        'dynamic_spherical_left'  => '0.00', 'dynamic_cylindrical_left' => '0.00', 'dynamic_axis_left' => '0º',
        // estática só default
        'static_spherical_right' => '0.00', 'static_cylindrical_right' => '0.00', 'static_axis_right' => '0º',
        'static_spherical_left'  => '0,00', 'static_cylindrical_left' => '0.00', 'static_axis_left' => '0°',
    ]);

    $json = $this->withSession(panelSession($this->entityUser))
        ->getJson(route('panel.patients.medicalrecords.show', [$this->patient, $record]))
        ->assertOk()
        ->json();

    // dinâmica tem valor real → bloco íntegro
    expect($json['dynamic_spherical_right'])->toBe('-1,50');
    // estática toda default → oculta
    expect($json['static_spherical_right'])->toBeNull()
        ->and($json['static_cylindrical_left'])->toBeNull()
        ->and($json['static_axis_left'])->toBeNull();
});
