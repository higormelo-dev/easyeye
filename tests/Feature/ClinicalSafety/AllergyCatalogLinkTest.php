<?php

declare(strict_types=1);

use App\Enums\ClientRule;
use App\Models\{Covenant, Entity, EntityUser, MedicalRecord, MedicalRecordDocumentation, Medicine, Patient, PatientAllergy, People, User};
use Database\Seeders\{ReportSettingContentSeeder, ReportSettingSeeder, ReportSettingVariableSeeder};

/*
 * Fase 2 / G1 — alergia escolhida pelo CATÁLOGO (CMED), opcional, além da
 * substância digitada (que continua obrigatória e livre).
 *
 * O servidor relê o item: grava medicine_id + o princípio ativo DO CATÁLOGO
 * (nunca o que veio da tela), ignora item de outra clínica e esse princípio
 * ativo alimenta a checagem da receita — alergia à "Novalgina" (dipirona)
 * alerta numa receita de OUTRA marca de dipirona, que o nome digitado sozinho
 * não pegaria.
 */
beforeEach(function () {
    $this->entity = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $this->other  = Entity::factory()->create(['is_client' => true, 'active' => true]);

    $this->secretary = createEntityUser($this->entity, User::factory()->create(), ClientRule::Secretary->value);
    $this->docEu     = createEntityUser($this->entity, User::factory()->create(), ClientRule::Doctor->value);
    $this->doctor    = doctorRecordForEntityUser($this->docEu);

    $this->patient = Patient::create([
        'entity_id'   => $this->entity->id,
        'person_id'   => People::factory()->create(['full_name' => 'PACIENTE CATALOGO'])->id,
        'covenant_id' => Covenant::factory()->create()->id,
        'active'      => true,
    ]);

    // Catálogo global (CMED) + item próprio da clínica + item de OUTRA clínica.
    $this->novalgina = Medicine::query()->create(['name' => 'NOVALGINA', 'concentration' => '1 G', 'active_ingredient' => 'DIPIRONA MONOIDRATADA', 'active' => true]);
    $this->anador    = Medicine::query()->create(['name' => 'ANADOR', 'concentration' => '500 MG', 'active_ingredient' => 'DIPIRONA MONOIDRATADA', 'active' => true]);
    $this->ownItem   = Medicine::query()->create(['entity_id' => $this->entity->id, 'name' => 'FORMULA DA CASA', 'active_ingredient' => 'IODOPOVIDONA', 'active' => true]);
    $this->foreign   = Medicine::query()->create(['entity_id' => $this->other->id, 'name' => 'ITEM DE OUTRA CLINICA', 'active_ingredient' => 'DIPIRONA MONOIDRATADA', 'active' => true]);
});

function g1fase2As(object $test, EntityUser $eu): object
{
    return $test->actingAs($eu->user)->withSession(panelSession($eu));
}

describe('item do catálogo na alergia', function () {
    it('grava medicine_id e o princípio ativo DO CATÁLOGO — mesmo que a tela mande outro', function () {
        $res = g1fase2As($this, $this->secretary)->postJson(route('panel.patients.allergies.store', $this->patient), [
            'category'          => 'medication',
            'substance'         => 'Novalgina',
            'medicine_id'       => $this->novalgina->id,
            'active_ingredient' => 'paracetamol',
        ])->assertCreated();

        $allergy = PatientAllergy::query()->sole();

        expect($allergy->substance)->toBe('Novalgina')
            ->and($allergy->medicine_id)->toBe($this->novalgina->id)
            ->and($allergy->active_ingredient)->toBe('DIPIRONA MONOIDRATADA')
            ->and($res->json('data.allergies.0.medicine_label'))->toBe('NOVALGINA 1 G')
            ->and($res->json('data.allergies.0.medicine_id'))->toBe($this->novalgina->id);
    });

    it('item da própria clínica vale; item de OUTRA clínica é ignorado (sem vínculo, sem o princípio ativo dele)', function () {
        g1fase2As($this, $this->secretary)->postJson(route('panel.patients.allergies.store', $this->patient), [
            'category' => 'antiseptic', 'substance' => 'Iodo da casa', 'medicine_id' => $this->ownItem->id,
        ])->assertCreated();

        g1fase2As($this, $this->secretary)->postJson(route('panel.patients.allergies.store', $this->patient), [
            'category' => 'medication', 'substance' => 'Remédio X', 'medicine_id' => $this->foreign->id,
        ])->assertCreated();

        $own     = PatientAllergy::query()->where('substance', 'Iodo da casa')->sole();
        $foreign = PatientAllergy::query()->where('substance', 'Remédio X')->sole();

        expect($own->medicine_id)->toBe($this->ownItem->id)
            ->and($own->active_ingredient)->toBe('IODOPOVIDONA')
            ->and($foreign->medicine_id)->toBeNull()
            ->and($foreign->active_ingredient)->toBeNull();
    });

    it('sem item do catálogo continua valendo a substância/princípio ativo digitados', function () {
        g1fase2As($this, $this->secretary)->postJson(route('panel.patients.allergies.store', $this->patient), [
            'category' => 'medication', 'substance' => 'Colírio X', 'active_ingredient' => 'tobramicina',
        ])->assertCreated();

        expect(PatientAllergy::query()->sole())
            ->medicine_id->toBeNull()
            ->active_ingredient->toBe('tobramicina');
    });

    it('paciente novo: a alergia escolhida no catálogo vai junto com o cadastro (modo pendente)', function () {
        $covenant = Covenant::factory()->create(['entity_id' => $this->entity->id]);

        g1fase2As($this, $this->secretary)->postJson(route('panel.patients.store'), [
            'covenant_id' => $covenant->id, 'name' => 'NOVO CATALOGO', 'national_registry' => '52998224725', 'birth_date' => '1980-01-01',
            'gender'      => 1, 'marital_status' => 1, 'email' => 'novo.catalogo@example.test', 'cellphone' => '61999990000', 'whatsapp' => false,
            'allergies'   => [
                ['category' => 'medication', 'substance' => 'Novalgina', 'medicine_id' => $this->novalgina->id, 'medicine_label' => 'NOVALGINA 1 G', '_key' => 'p-1'],
                ['category' => 'medication', 'substance' => 'Outra', 'medicine_id' => $this->foreign->id, '_key' => 'p-2'],
            ],
        ])->assertOk();

        $patient = Patient::query()->whereHas('person', fn ($q) => $q->where('full_name', 'NOVO CATALOGO'))->sole();

        expect($patient->allergies()->where('substance', 'Novalgina')->sole())
            ->medicine_id->toBe($this->novalgina->id)
            ->active_ingredient->toBe('DIPIRONA MONOIDRATADA')
            ->and($patient->allergies()->where('substance', 'Outra')->sole()->medicine_id)->toBeNull();
    });
});

describe('ponta a ponta: alergia pelo catálogo → receita', function () {
    beforeEach(function () {
        $this->seed([ReportSettingSeeder::class, ReportSettingContentSeeder::class, ReportSettingVariableSeeder::class]);

        $this->record = MedicalRecord::create([
            'entity_id' => $this->entity->id, 'patient_id' => $this->patient->id, 'doctor_id' => $this->doctor->id, 'main_complaint' => 'Dor',
        ]);

        // Recepção registra "Novalgina" escolhendo o item do catálogo.
        g1fase2As($this, $this->secretary)->postJson(route('panel.patients.allergies.store', $this->patient), [
            'category' => 'medication', 'substance' => 'Novalgina', 'reaction' => 'Urticária', 'medicine_id' => $this->novalgina->id,
        ])->assertCreated();
    });

    it('OUTRA marca do mesmo princípio ativo → 422 sem motivo (o nome digitado sozinho não pegaria)', function () {
        $res = g1fase2As($this, $this->docEu)->postJson(
            route('panel.patients.medicalrecords.quick-actions.issue', [$this->patient, $this->record, 'medication-prescription']),
            ['content' => 'ANADOR 500 MG — 1 comprimido se dor', 'items' => [['medicine_id' => $this->anador->id]]],
        )->assertUnprocessable()->assertJsonValidationErrors('allergy_override_reason');

        expect($res->json('allergy_conflicts.0'))->toMatchArray(['substance' => 'Novalgina', 'match' => 'ingredient'])
            ->and(MedicalRecordDocumentation::query()->count())->toBe(0);
    });

    it('com motivo → 201 e o motivo fica no documento', function () {
        $res = g1fase2As($this, $this->docEu)->postJson(
            route('panel.patients.medicalrecords.quick-actions.issue', [$this->patient, $this->record, 'medication-prescription']),
            [
                'content'                 => 'ANADOR 500 MG — 1 comprimido se dor',
                'items'                   => [['medicine_id' => $this->anador->id]],
                'allergy_override_reason' => 'Reação descartada em teste de provocação',
            ],
        )->assertCreated();

        expect(MedicalRecordDocumentation::query()->findOrFail($res->json('id'))->safety_override['reason'])
            ->toBe('Reação descartada em teste de provocação');
    });
});
