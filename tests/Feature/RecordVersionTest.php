<?php

namespace Tests\Feature;

use App\Models\{Covenant, Doctor, Entity, EntityUser, MedicalRecord, Patient, People, RecordVersion, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecordVersionTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Entity $entity;

    private Patient $patient;

    private Doctor $doctor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user   = User::factory()->create();
        $this->entity = Entity::factory()->create(['is_client' => true]);

        $this->actingAs($this->user);
        session(['selected_entity_id' => $this->entity->id]);

        $covenant = Covenant::factory()->create();

        $person        = People::factory()->create();
        $this->patient = Patient::create([
            'entity_id'   => $this->entity->id,
            'person_id'   => $person->id,
            'covenant_id' => $covenant->id,
            'active'      => true,
        ]);

        $doctorUser = User::factory()->create();
        $entityUser = EntityUser::create([
            'entity_id' => $this->entity->id,
            'user_id'   => $doctorUser->id,
            'active'    => true,
            'rule'      => 'doctor',
        ]);
        $doctorPerson = People::factory()->create();
        $this->doctor = Doctor::create([
            'entity_user_id' => $entityUser->id,
            'person_id'      => $doctorPerson->id,
            'record'         => '12345',
            'color'          => '#FF0000',
            'partner'        => false,
            'active'         => true,
        ]);
    }

    private function makeMedicalRecord(array $overrides = []): MedicalRecord
    {
        return MedicalRecord::create(array_merge([
            'patient_id'     => $this->patient->id,
            'doctor_id'      => $this->doctor->id,
            'main_complaint' => 'Visão embaçada',
        ], $overrides));
    }

    public function test_cria_snapshot_ao_atualizar_medical_record(): void
    {
        $record = $this->makeMedicalRecord();

        $record->update(['main_complaint' => 'Dor ocular']);

        $versions = RecordVersion::where('versionable_type', MedicalRecord::class)
            ->where('versionable_id', $record->id)
            ->get();

        $this->assertCount(1, $versions);
        $this->assertEquals(1, $versions->first()->version);
        $this->assertEquals('Visão embaçada', $versions->first()->data['main_complaint']);
    }

    public function test_incrementa_versao_a_cada_atualizacao(): void
    {
        $record = $this->makeMedicalRecord();

        $record->update(['main_complaint' => 'Dor ocular']);
        $record->update(['main_complaint' => 'Visão dupla']);

        $versions = RecordVersion::where('versionable_type', MedicalRecord::class)
            ->where('versionable_id', $record->id)
            ->orderBy('version')
            ->get();

        $this->assertCount(2, $versions);
        $this->assertEquals(1, $versions[0]->version);
        $this->assertEquals(2, $versions[1]->version);

        // Cada snapshot guarda o estado ANTES da alteração
        $this->assertEquals('Visão embaçada', $versions[0]->data['main_complaint']);
        $this->assertEquals('Dor ocular', $versions[1]->data['main_complaint']);
    }

    public function test_nao_cria_versao_ao_criar_record(): void
    {
        $record = $this->makeMedicalRecord();

        $count = RecordVersion::where('versionable_type', MedicalRecord::class)
            ->where('versionable_id', $record->id)
            ->count();

        $this->assertEquals(0, $count);
    }

    public function test_snapshot_registra_entity_id_e_user_id(): void
    {
        $record = $this->makeMedicalRecord();
        $record->update(['main_complaint' => 'Dor ocular']);

        $version = RecordVersion::where('versionable_type', MedicalRecord::class)
            ->where('versionable_id', $record->id)
            ->first();

        $this->assertEquals($this->entity->id, $version->entity_id);
        $this->assertEquals($this->user->id, $version->user_id);
    }

    public function test_versoes_sao_imutaveis_e_preservam_estado_anterior(): void
    {
        $record = $this->makeMedicalRecord(['main_complaint' => 'Estado inicial']);

        $record->update(['main_complaint' => 'Estado 2']);
        $record->update(['main_complaint' => 'Estado 3']);

        // Força refresh
        $record->update(['main_complaint' => 'Estado 4']);

        $versions = RecordVersion::where('versionable_type', MedicalRecord::class)
            ->where('versionable_id', $record->id)
            ->orderBy('version')
            ->pluck('data')
            ->map(fn ($d) => $d['main_complaint'])
            ->toArray();

        $this->assertEquals(['Estado inicial', 'Estado 2', 'Estado 3'], $versions);
    }

    public function test_relacionamento_versions_no_model(): void
    {
        $record = $this->makeMedicalRecord();
        $record->update(['main_complaint' => 'Primeira alteração']);

        $this->assertCount(1, $record->versions);
        $this->assertInstanceOf(RecordVersion::class, $record->versions->first());
    }
}
