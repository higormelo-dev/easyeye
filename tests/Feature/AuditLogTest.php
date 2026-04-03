<?php

namespace Tests\Feature;

use App\Models\{AuditLog, Covenant, Entity, Patient, People, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Entity $entity;

    private Covenant $covenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user     = User::factory()->create();
        $this->entity   = Entity::factory()->create(['is_client' => true]);
        $this->covenant = Covenant::factory()->create();

        $this->actingAs($this->user);

        // Simula a entidade ativa na sessão (multi-tenancy)
        session(['selected_entity_id' => $this->entity->id]);
    }

    public function test_cria_log_de_auditoria_ao_criar_patient(): void
    {
        $person  = People::factory()->create();
        $patient = Patient::create([
            'entity_id'   => $this->entity->id,
            'person_id'   => $person->id,
            'covenant_id' => $this->covenant->id,
            'active'      => true,
        ]);

        $log = AuditLog::where('auditable_type', Patient::class)
            ->where('auditable_id', $patient->id)
            ->where('event', 'created')
            ->first();

        $this->assertNotNull($log);
        $this->assertEquals($this->entity->id, $log->entity_id);
        $this->assertEquals($this->user->id, $log->user_id);
        $this->assertNull($log->old_values);
        $this->assertArrayHasKey('entity_id', $log->new_values);
    }

    public function test_cria_log_de_auditoria_ao_atualizar_patient(): void
    {
        $person  = People::factory()->create();
        $patient = Patient::create([
            'entity_id'   => $this->entity->id,
            'person_id'   => $person->id,
            'covenant_id' => $this->covenant->id,
            'active'      => true,
        ]);

        $patient->update(['active' => false]);

        $log = AuditLog::where('auditable_type', Patient::class)
            ->where('auditable_id', $patient->id)
            ->where('event', 'updated')
            ->first();

        $this->assertNotNull($log);
        $this->assertEquals(['active' => true], $log->old_values);
        $this->assertEquals(['active' => false], $log->new_values);
    }

    public function test_cria_log_de_auditoria_ao_deletar_patient(): void
    {
        $person  = People::factory()->create();
        $patient = Patient::create([
            'entity_id'   => $this->entity->id,
            'person_id'   => $person->id,
            'covenant_id' => $this->covenant->id,
            'active'      => true,
        ]);

        $patient->delete();

        $log = AuditLog::where('auditable_type', Patient::class)
            ->where('auditable_id', $patient->id)
            ->where('event', 'deleted')
            ->first();

        $this->assertNotNull($log);
        $this->assertNull($log->new_values);
    }

    public function test_campos_excluidos_nao_aparecem_no_log(): void
    {
        $person  = People::factory()->create();
        $patient = Patient::create([
            'entity_id'   => $this->entity->id,
            'person_id'   => $person->id,
            'covenant_id' => $this->covenant->id,
            'active'      => true,
        ]);

        $log = AuditLog::where('auditable_type', Patient::class)
            ->where('auditable_id', $patient->id)
            ->where('event', 'created')
            ->first();

        $this->assertArrayNotHasKey('created_at', $log->new_values ?? []);
        $this->assertArrayNotHasKey('updated_at', $log->new_values ?? []);
        $this->assertArrayNotHasKey('created_by', $log->new_values ?? []);
        $this->assertArrayNotHasKey('updated_by', $log->new_values ?? []);
    }

    public function test_entity_id_no_log_reflete_sessao_ativa(): void
    {
        $outraEntity = Entity::factory()->create(['is_client' => true]);
        session(['selected_entity_id' => $outraEntity->id]);

        $person  = People::factory()->create();
        $patient = Patient::create([
            'entity_id'   => $this->entity->id, // entity diferente no model
            'person_id'   => $person->id,
            'covenant_id' => $this->covenant->id,
            'active'      => true,
        ]);

        $log = AuditLog::where('auditable_type', Patient::class)
            ->where('auditable_id', $patient->id)
            ->where('event', 'created')
            ->first();

        // entity_id no log deve ser da sessão, não do model
        $this->assertEquals($outraEntity->id, $log->entity_id);
    }

    public function test_preenche_created_by_e_updated_by_automaticamente(): void
    {
        $person  = People::factory()->create();
        $patient = Patient::create([
            'entity_id'   => $this->entity->id,
            'person_id'   => $person->id,
            'covenant_id' => $this->covenant->id,
            'active'      => true,
        ]);

        $this->assertEquals($this->user->id, $patient->created_by);
        $this->assertEquals($this->user->id, $patient->updated_by);
    }

    public function test_atualiza_updated_by_ao_editar(): void
    {
        $outroUser = User::factory()->create();

        $person  = People::factory()->create();
        $patient = Patient::create([
            'entity_id'   => $this->entity->id,
            'person_id'   => $person->id,
            'covenant_id' => $this->covenant->id,
            'active'      => true,
        ]);

        $this->actingAs($outroUser);
        $patient->update(['active' => false]);

        $this->assertEquals($outroUser->id, $patient->fresh()->updated_by);
    }
}
