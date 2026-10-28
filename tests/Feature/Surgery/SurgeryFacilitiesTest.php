<?php

declare(strict_types=1);

use App\Enums\Surgery\SurgicalCaseStatus;
use App\Models\ClinicResource;
use App\Models\Surgery\{SurgeryFacility, SurgeryRoom, SurgicalCase};
use App\Services\Surgery\SurgicalCaseService;

/*
 * Módulo cirúrgico (Fase 4) / M0 — locais e salas cirúrgicas: perfis, recurso
 * do plano, isolamento entre clínicas, nome único, vínculo com a sala da
 * Agenda e a regra "em uso por caso aberto não sai".
 */

function sfFacility(object $w, array $over = []): SurgeryFacility
{
    return SurgeryFacility::query()->create([
        'entity_id' => $w->entity->id,
        'name'      => 'Hospital São Lucas',
        'type'      => 'hospital',
        ...$over,
    ]);
}

function sfRoom(SurgeryFacility $facility, array $over = []): SurgeryRoom
{
    return $facility->rooms()->create(['entity_id' => $facility->entity_id, 'name' => 'Sala 1', ...$over]);
}

function sfCaseAt(object $w, SurgeryFacility $facility, ?SurgeryRoom $room = null, SurgicalCaseStatus $status = SurgicalCaseStatus::Authorized): SurgicalCase
{
    $case = app(SurgicalCaseService::class)->create((string) $w->entity->id, [
        'patient_id'          => (string) $w->patient->id,
        'surgeon_doctor_id'   => (string) $w->doctor->id,
        'title'               => 'Catarata',
        'eye'                 => 'od',
        'surgery_facility_id' => (string) $facility->id,
    ]);

    $case->forceFill(['status' => $status, 'surgery_room_id' => $room?->id])->save();

    return $case;
}

beforeEach(function () {
    $this->w = surgeryWorld();
});

it('só admin (settings.manage) abre as configurações, e só com o recurso do plano', function () {
    surgeryAs($this->w, 'admin')->get(route('panel.setting.surgery-facilities.index'))->assertOk();
    surgeryAs($this->w, 'secretary')->get(route('panel.setting.surgery-facilities.index'))->assertForbidden();
    surgeryAs($this->w, 'doctor')->get(route('panel.setting.surgery-facilities.index'))->assertForbidden();

    $without = surgeryWorld(withModule: false);
    surgeryAs($without, 'admin')->getJson(route('panel.setting.surgery-facilities.index'))->assertForbidden();
});

it('cria o local com validação de CNES e UF', function () {
    surgeryAs($this->w, 'admin')->postJson(route('panel.setting.surgery-facilities.store'), [
        'name' => 'X', 'type' => 'hospital', 'cnes' => '123', 'state' => 'SAO',
    ])->assertStatus(422)->assertJsonValidationErrors(['name', 'cnes', 'state']);

    surgeryAs($this->w, 'admin')->post(route('panel.setting.surgery-facilities.store'), [
        'name' => 'Hospital São Lucas', 'type' => 'hospital', 'cnes' => '2077485', 'operator_code' => 'H-77', 'state' => 'sp',
    ])->assertRedirect();

    $facility = SurgeryFacility::query()->firstOrFail();

    expect($facility->name)->toBe('Hospital São Lucas')
        ->and($facility->cnes)->toBe('2077485')
        ->and($facility->entity_id)->toBe($this->w->entity->id)
        ->and($facility->active)->toBeTrue();
});

it('a página entrega só os locais da clínica da sessão', function () {
    sfFacility($this->w);
    SurgeryFacility::query()->create(['entity_id' => $this->w->other->id, 'name' => 'De outra clínica', 'type' => 'hospital']);

    surgeryAs($this->w, 'admin')->get(route('panel.setting.surgery-facilities.index'))
        ->assertInertia(fn ($page) => $page->has('facilities', 1)->where('facilities.0.name', 'Hospital São Lucas'));
});

it('local de outra clínica é 404 (editar, excluir, criar sala)', function () {
    $foreign = SurgeryFacility::query()->create(['entity_id' => $this->w->other->id, 'name' => 'Alheio', 'type' => 'hospital']);

    surgeryAs($this->w, 'admin')->putJson(route('panel.setting.surgery-facilities.update', $foreign), ['name' => 'Invadido'])->assertNotFound();
    surgeryAs($this->w, 'admin')->deleteJson(route('panel.setting.surgery-facilities.destroy', $foreign))->assertNotFound();
    surgeryAs($this->w, 'admin')->postJson(route('panel.setting.surgery-facilities.rooms.store', $foreign), ['name' => 'Sala'])->assertNotFound();

    expect($foreign->refresh()->name)->toBe('Alheio');
});

it('nome de sala é único por local, sem diferenciar maiúsculas', function () {
    $facility = sfFacility($this->w);
    sfRoom($facility, ['name' => 'Sala 1']);
    $other = sfFacility($this->w, ['name' => 'Outro hospital']);

    surgeryAs($this->w, 'admin')->postJson(route('panel.setting.surgery-facilities.rooms.store', $facility), ['name' => 'sala 1'])
        ->assertStatus(422)->assertJsonValidationErrors('name');

    surgeryAs($this->w, 'admin')->post(route('panel.setting.surgery-facilities.rooms.store', $other), ['name' => 'Sala 1'])->assertRedirect();

    expect(SurgeryRoom::query()->count())->toBe(2);
});

it('vincula a sala da clínica à sala da Agenda, uma vez só e só da própria clínica', function () {
    $own       = sfFacility($this->w, ['name' => 'Clínica', 'type' => 'own_clinic']);
    $resource  = ClinicResource::query()->create(['entity_id' => $this->w->entity->id, 'code' => 'R1', 'name' => 'Centro cirúrgico', 'type' => 'room', 'active' => true]);
    $equipment = ClinicResource::query()->create(['entity_id' => $this->w->entity->id, 'code' => 'E1', 'name' => 'Faco', 'type' => 'equipment', 'active' => true]);
    $foreign   = ClinicResource::query()->create(['entity_id' => $this->w->other->id, 'code' => 'R9', 'name' => 'Alheia', 'type' => 'room', 'active' => true]);

    surgeryAs($this->w, 'admin')->post(route('panel.setting.surgery-facilities.rooms.store', $own), ['name' => 'Sala A', 'clinic_resource_id' => (string) $resource->id])->assertRedirect();
    expect(SurgeryRoom::query()->where('name', 'Sala A')->firstOrFail()->clinic_resource_id)->toBe($resource->id);

    // Já ligada a outra sala cirúrgica.
    surgeryAs($this->w, 'admin')->postJson(route('panel.setting.surgery-facilities.rooms.store', $own), ['name' => 'Sala B', 'clinic_resource_id' => (string) $resource->id])->assertStatus(409);
    // Equipamento não é sala; recurso de outra clínica não existe aqui.
    surgeryAs($this->w, 'admin')->postJson(route('panel.setting.surgery-facilities.rooms.store', $own), ['name' => 'Sala C', 'clinic_resource_id' => (string) $equipment->id])->assertStatus(422);
    surgeryAs($this->w, 'admin')->postJson(route('panel.setting.surgery-facilities.rooms.store', $own), ['name' => 'Sala D', 'clinic_resource_id' => (string) $foreign->id])->assertStatus(422);

    expect(SurgeryRoom::query()->count())->toBe(1);
});

it('editar a própria sala mantendo o vínculo não conflita consigo mesma', function () {
    $own      = sfFacility($this->w, ['type' => 'own_clinic']);
    $resource = ClinicResource::query()->create(['entity_id' => $this->w->entity->id, 'code' => 'R1', 'name' => 'Centro', 'type' => 'room', 'active' => true]);
    $room     = sfRoom($own, ['clinic_resource_id' => $resource->id]);

    surgeryAs($this->w, 'admin')->put(route('panel.setting.surgery-facilities.rooms.update', $room), ['name' => 'Sala 1 renomeada', 'clinic_resource_id' => (string) $resource->id])->assertRedirect();

    expect($room->refresh()->name)->toBe('Sala 1 renomeada');
});

it('local e sala em uso por caso aberto não saem; depois do caso encerrado, saem', function () {
    $facility = sfFacility($this->w);
    $room     = sfRoom($facility);
    $case     = sfCaseAt($this->w, $facility, $room);

    surgeryAs($this->w, 'admin')->putJson(route('panel.setting.surgery-facilities.update', $facility), ['active' => false])->assertStatus(409);
    surgeryAs($this->w, 'admin')->deleteJson(route('panel.setting.surgery-facilities.destroy', $facility))->assertStatus(409);
    surgeryAs($this->w, 'admin')->putJson(route('panel.setting.surgery-facilities.rooms.update', $room), ['active' => false])->assertStatus(409);
    surgeryAs($this->w, 'admin')->deleteJson(route('panel.setting.surgery-facilities.rooms.destroy', $room))->assertStatus(409);

    expect($facility->refresh()->active)->toBeTrue()->and(SurgeryRoom::query()->count())->toBe(1);

    $case->forceFill(['status' => SurgicalCaseStatus::Discharged])->save();

    surgeryAs($this->w, 'admin')->put(route('panel.setting.surgery-facilities.rooms.update', $room), ['active' => false])->assertRedirect();
    surgeryAs($this->w, 'admin')->delete(route('panel.setting.surgery-facilities.destroy', $facility))->assertRedirect();

    expect(SurgeryFacility::query()->count())->toBe(0)->and(SurgeryRoom::query()->count())->toBe(0);
});

it('local inativo some das opções do pedido e é recusado no cadastro do caso', function () {
    $facility = sfFacility($this->w, ['active' => false]);

    surgeryAs($this->w, 'secretary')->get(route('panel.surgery.index'))
        ->assertInertia(fn ($page) => $page->has('options.facilities', 0));

    surgeryAs($this->w, 'secretary')->postJson(route('panel.surgery.store'), [
        'patient_id' => (string) $this->w->patient->id, 'surgeon_doctor_id' => (string) $this->w->doctor->id,
        'title'      => 'Catarata', 'eye' => 'od', 'surgery_facility_id' => (string) $facility->id,
    ])->assertStatus(422);
});
