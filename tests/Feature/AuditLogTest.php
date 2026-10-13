<?php

namespace Tests\Feature;

use App\Enums\{ClientRule, DocumentationType};
use App\Models\{AuditLog, Covenant, Entity, MedicalRecord, MedicalRecordDocumentation, Patient, People, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
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

    /**
     * [GAP] Laudo de imagem embutida: data_uri (bytes congelados, até
     * ~27 MiB em base64) nunca duplica em audit_logs.new_values — vira
     * hash/tamanho; o resto do metadata (indicação clínica, enabled/
     * columns) continua intacto. Achado via revisão adversarial Codex:
     * ComplianceController::exportAuditLogs() nunca usa essas colunas, mas
     * carregava 500 linhas inteiras por chunk e esgotava o memory_limit
     * conforme laudos de imagem se acumulam em audit_logs.
     */
    public function test_laudo_com_imagem_embutida_nunca_duplica_data_uri_na_auditoria(): void
    {
        $entityUser = createEntityUser($this->entity, $this->user, ClientRule::Doctor->value);
        $doctor     = doctorRecordForEntityUser($entityUser);

        $person  = People::factory()->create();
        $patient = Patient::create([
            'entity_id' => $this->entity->id, 'person_id' => $person->id, 'covenant_id' => $this->covenant->id, 'active' => true,
        ]);
        $record = MedicalRecord::create(['entity_id' => $this->entity->id, 'patient_id' => $patient->id, 'doctor_id' => $doctor->id]);

        $dataUri = 'data:image/jpeg;base64,' . str_repeat('A', 2000);
        $doc     = MedicalRecordDocumentation::create([
            'medical_record_id' => $record->id,
            'patient_id'        => $patient->id,
            'doctor_id'         => $doctor->id,
            'type'              => DocumentationType::Report->value,
            'title'             => 'Laudo com imagem embutida',
            'content'           => '<p>Achados.</p>',
            'metadata'          => [
                'clinical_indication' => 'Suspeita de glaucoma',
                'image_report'        => [
                    'enabled' => true, 'columns' => 2,
                    'images'  => [['data_uri' => $dataUri, 'caption' => 'OD']],
                ],
            ],
        ]);

        $log = AuditLog::where('auditable_type', MedicalRecordDocumentation::class)
            ->where('auditable_id', $doc->id)
            ->where('event', 'created')
            ->first();

        $this->assertNotNull($log);
        // new_values['metadata'] continua string JSON crua (mesmo formato
        // que getAttributes() sempre devolveu pra colunas com cast array —
        // AuditLog.new_values só decodifica o nível de fora).
        $loggedMetadata = json_decode($log->new_values['metadata'], true);
        $loggedImage    = $loggedMetadata['image_report']['images'][0];

        // Resto do metadata continua intacto — a correção nunca pode
        // esconder informação clínica real, só o peso dos bytes da imagem.
        $this->assertEquals('Suspeita de glaucoma', $loggedMetadata['clinical_indication']);
        $this->assertEquals('OD', $loggedImage['caption']);
        $this->assertStringNotContainsString($dataUri, json_encode($log->new_values));
        $this->assertStringContainsString((string) strlen($dataUri), $loggedImage['data_uri']);
        $this->assertStringContainsString(hash('sha256', $dataUri), $loggedImage['data_uri']);
    }

    /**
     * [GAP] Mesma redação do teste acima, agora pra metadata.inline_images
     * (referência <img data-exam-image-id> inserida no MEIO do texto via
     * botão "Inserir imagem" — ReportImageBlockBuilder::
     * freezeInlineImages(), distinto do bloco no final do laudo). Content
     * em si nunca grava o byte (fica só a referência); o byte congelado
     * vive em metadata.inline_images e precisa da MESMA redação.
     */
    public function test_imagem_inline_embutida_nunca_duplica_data_uri_na_auditoria(): void
    {
        $entityUser = createEntityUser($this->entity, $this->user, ClientRule::Doctor->value);
        $doctor     = doctorRecordForEntityUser($entityUser);

        $person  = People::factory()->create();
        $patient = Patient::create([
            'entity_id' => $this->entity->id, 'person_id' => $person->id, 'covenant_id' => $this->covenant->id, 'active' => true,
        ]);
        $record = MedicalRecord::create(['entity_id' => $this->entity->id, 'patient_id' => $patient->id, 'doctor_id' => $doctor->id]);

        $dataUri = 'data:image/jpeg;base64,' . str_repeat('A', 2000);
        $examId  = (string) Str::uuid();
        $doc     = MedicalRecordDocumentation::create([
            'medical_record_id' => $record->id,
            'patient_id'        => $patient->id,
            'doctor_id'         => $doctor->id,
            'type'              => DocumentationType::Report->value,
            'title'             => 'Laudo com imagem inline',
            'content'           => '<p>Achados.</p><p><img data-exam-image-id="' . $examId . '" alt="OD"></p>',
            'metadata'          => [
                'clinical_indication' => 'Suspeita de glaucoma',
                'inline_images'       => [$examId => $dataUri],
            ],
        ]);

        $log = AuditLog::where('auditable_type', MedicalRecordDocumentation::class)
            ->where('auditable_id', $doc->id)
            ->where('event', 'created')
            ->first();

        $this->assertNotNull($log);
        $loggedMetadata = json_decode($log->new_values['metadata'], true);

        $this->assertEquals('Suspeita de glaucoma', $loggedMetadata['clinical_indication']);
        $this->assertStringNotContainsString($dataUri, json_encode($log->new_values));
        $this->assertStringContainsString((string) strlen($dataUri), $loggedMetadata['inline_images'][$examId]);
        $this->assertStringContainsString(hash('sha256', $dataUri), $loggedMetadata['inline_images'][$examId]);
    }
}
