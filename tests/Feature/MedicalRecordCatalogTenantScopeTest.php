<?php

declare(strict_types=1);

use App\Enums\ClientRule;
use App\Models\{Covenant, Doctor, Entity, MedicalRecord, Patient, People, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Achado de segurança (auditoria panel.* IDOR, rodada 2 — ID via request
 * body): os catálogos clínicos do prontuário (visual_acuity_types, lenses,
 * addition_types, etc.) têm entity_id NULLABLE (registro global OU
 * customizado por clínica) e EntityScope global no model — mas o scope só
 * protege LEITURA via Eloquent, nunca a regra de validação 'exists:<tabela>,id'
 * crua. Sem escopar por tenant na validação, um ID de OUTRA clínica era
 * aceito e gravado no prontuário. Cobre StoreMedicalRecordRequest e
 * UpdateMedicalRecordRequest para 3 dos 13 campos afetados.
 */
beforeEach(function () {
    $this->entity      = Entity::factory()->create(['is_client' => true]);
    $this->otherEntity = Entity::factory()->create(['is_client' => true]);

    $this->user       = User::factory()->create();
    $this->entityUser = createEntityUser($this->entity, $this->user, ClientRule::Doctor->value);

    $doctorPerson = People::factory()->create();
    $this->doctor = Doctor::create([
        'entity_user_id' => $this->entityUser->id,
        'person_id'      => $doctorPerson->id,
        'record'         => '12345',
        'color'          => '#FF0000',
        'partner'        => false,
        'active'         => true,
    ]);

    $covenant      = Covenant::factory()->create();
    $patientPerson = People::factory()->create();
    $this->patient = Patient::create([
        'entity_id'   => $this->entity->id,
        'person_id'   => $patientPerson->id,
        'covenant_id' => $covenant->id,
        'active'      => true,
    ]);

    $this->actingAs($this->user);
    session([
        'selected_entity_id'        => $this->entity->id,
        'selected_entity_user_id'   => $this->entityUser->id,
        'selected_entity_user_rule' => ClientRule::Doctor->value,
        'user_rule'                 => ClientRule::Doctor->value,
    ]);

    $this->payload = fn (array $extra = []) => array_merge([
        'doctor_id'      => $this->doctor->id,
        'main_complaint' => 'Baixa acuidade visual',
    ], $extra);

    $this->makeRecord = fn () => MedicalRecord::create([
        'entity_id'      => $this->entity->id,
        'patient_id'     => $this->patient->id,
        'doctor_id'      => $this->doctor->id,
        'main_complaint' => 'Baixa acuidade visual',
    ]);

    // Insert direto na tabela — evita depender do fillable/traits específicos
    // de cada model de catálogo, já que só o que a regra Rule::exists() lê
    // (entity_id, code, deleted_at) importa aqui.
    $this->makeCatalogRow = function (string $table, ?string $entityId, array $extra = []) {
        $id = (string) Str::uuid();

        DB::table($table)->insert(array_merge([
            'id'         => $id,
            'entity_id'  => $entityId,
            'code'       => strtoupper(Str::random(10)),
            'name'       => 'Catálogo ' . $id,
            'active'     => true,
            'created_at' => now(),
            'updated_at' => now(),
        ], $extra));

        return $id;
    };
});

dataset('catalog_fields', [
    'visual_acuity_type_id (visual_acuity_types)' => ['visual_acuity_type_id', 'visual_acuity_types', []],
    'lens_away_id (lenses)'                       => ['lens_away_id', 'lenses', ['away' => true, 'near' => false]],
    'addition_type_id (addition_types)'           => ['addition_type_id', 'addition_types', []],
]);

describe('Prontuário — catálogos clínicos escopados por tenant', function () {
    it('[SEGURANÇA] rejeita ID de OUTRA clínica no store', function (string $field, string $table, array $extra) {
        $foreignId = ($this->makeCatalogRow)($table, $this->otherEntity->id, $extra);

        $this->from(route('panel.patients.medicalrecords.create', $this->patient))
            ->post(
                route('panel.patients.medicalrecords.store', $this->patient),
                ($this->payload)([$field => $foreignId]),
            )
            ->assertSessionHasErrors($field);

        expect(MedicalRecord::where('patient_id', $this->patient->id)->exists())->toBeFalse();
    })->with('catalog_fields');

    it('[SEGURANÇA] rejeita ID de OUTRA clínica no update', function (string $field, string $table, array $extra) {
        $record    = ($this->makeRecord)();
        $foreignId = ($this->makeCatalogRow)($table, $this->otherEntity->id, $extra);

        $this->from(route('panel.patients.medicalrecords.edit', [$this->patient, $record]))
            ->put(
                route('panel.patients.medicalrecords.update', [$this->patient, $record]),
                ($this->payload)([$field => $foreignId]),
            )
            ->assertSessionHasErrors($field);

        expect($record->fresh()->{$field})->toBeNull();
    })->with('catalog_fields');

    it('aceita ID da PRÓPRIA clínica no store e no update', function (string $field, string $table, array $extra) {
        $ownId = ($this->makeCatalogRow)($table, $this->entity->id, $extra);

        $this->post(
            route('panel.patients.medicalrecords.store', $this->patient),
            ($this->payload)([$field => $ownId]),
        )->assertSessionDoesntHaveErrors($field);

        $record = MedicalRecord::where('patient_id', $this->patient->id)->firstOrFail();
        expect($record->{$field})->toBe($ownId);

        $this->put(
            route('panel.patients.medicalrecords.update', [$this->patient, $record]),
            ($this->payload)([$field => $ownId]),
        )->assertSessionDoesntHaveErrors($field);

        expect($record->fresh()->{$field})->toBe($ownId);
    })->with('catalog_fields');

    it('aceita ID GLOBAL (entity_id null, catálogo padrão da plataforma) no store e no update', function (string $field, string $table, array $extra) {
        $globalId = ($this->makeCatalogRow)($table, null, $extra);

        $this->post(
            route('panel.patients.medicalrecords.store', $this->patient),
            ($this->payload)([$field => $globalId]),
        )->assertSessionDoesntHaveErrors($field);

        $record = MedicalRecord::where('patient_id', $this->patient->id)->firstOrFail();
        expect($record->{$field})->toBe($globalId);

        $this->put(
            route('panel.patients.medicalrecords.update', [$this->patient, $record]),
            ($this->payload)([$field => $globalId]),
        )->assertSessionDoesntHaveErrors($field);

        expect($record->fresh()->{$field})->toBe($globalId);
    })->with('catalog_fields');
});
