<?php

/**
 * F9 — equipamento (EIQ-) é numerado POR INTEGRADOR.
 *
 * Antes:
 *  - PatientExamService resolvia equipment_identifier na ENTIDADE inteira com
 *    first(): numa clínica com 2 PCs integradores, os dois têm EIQ-0000000001 e
 *    o exame do integrador B podia ficar com o equipamento do A;
 *  - EntityIntegratorEquipmentRequest buscava o id a ignorar na regra unique
 *    pelo code em TODOS os tenants: o PUT /equipments/{número} reenviando o
 *    próprio nome/MAC dava 422 "já em uso";
 *  - a numeração não tinha lock: POSTs simultâneos do mesmo integrador
 *    repetiam o código.
 */

use App\Models\{EntityIntegrator, EntityIntegratorEquipment, ExamType, Patient, PatientExam};
use Illuminate\Database\{Connection, QueryException};
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\{DB, Storage};

/** Segundo PC integrador da MESMA clínica do $ctx, com token próprio. */
function eiqSecondIntegrator(array $ctx): array
{
    $integrator = EntityIntegrator::factory()->create([
        'entity_user_integrator_id' => $ctx['integratorUser']->id,
        'active'                    => true,
    ]);

    $token = $ctx['integratorUser']->createToken(
        'integrator-token-b',
        ['integrator_id:' . $integrator->id],
        Carbon::now()->addDays(7),
    );

    return [
        'integrator' => $integrator,
        'headers'    => ['Authorization' => 'Bearer ' . $token->plainTextToken],
    ];
}

function eiqRivalConnection(): Connection
{
    config(['database.connections.pgsql_eiq_rival' => config('database.connections.pgsql')]);

    return DB::connection('pgsql_eiq_rival');
}

describe('equipment_identifier no envio de exame', function () {
    beforeEach(function () {
        Storage::fake('s3');

        $this->ctx      = setupIntegrator();
        $this->second   = eiqSecondIntegrator($this->ctx);
        $this->patient  = Patient::factory()->create(['entity_id' => $this->ctx['entity']->id]);
        $this->examType = ExamType::factory()->create(['entity_id' => null]);
        $this->schedule = createScheduleForEntity($this->ctx['entity'], ['patient_id' => $this->patient->id])['schedule'];

        // Integrador A cadastra primeiro; cada integrador tem seu EIQ-0000000001.
        $this->equipmentA = EntityIntegratorEquipment::factory()->create(['integrator_id' => $this->ctx['integrator']->id]);
        $this->equipmentB = EntityIntegratorEquipment::factory()->create(['integrator_id' => $this->second['integrator']->id]);
    });

    it('numera EIQ por integrador (os dois PCs da clinica tem EIQ-0000000001)', function () {
        expect($this->equipmentA->code)->toBe('EIQ-0000000001')
            ->and($this->equipmentB->code)->toBe('EIQ-0000000001');
    });

    it('POST /patients/{p}/exams do integrador B grava o equipamento DO B', function () {
        $this->postJson(
            "/api/integrators/v1/patients/{$this->patient->id}/exams",
            [
                'exam_identifier'      => $this->examType->code,
                'schedule_identifier'  => $this->schedule->id,
                'equipment_identifier' => '1',
                'archive'              => UploadedFile::fake()->image('exam.jpg'),
                'name'                 => 'Exame Equipamento B',
            ],
            $this->second['headers'],
        )->assertCreated();

        expect(PatientExam::where('name', 'Exame Equipamento B')->value('entity_integrator_equipment_id'))
            ->toBe($this->equipmentB->id);
    });

    it('POST /exams do integrador B grava o equipamento DO B', function () {
        $this->postJson(
            '/api/integrators/v1/exams',
            [
                'exam_identifier'      => $this->examType->code,
                'schedule_identifier'  => $this->schedule->id,
                'equipment_identifier' => $this->equipmentB->code,
                'archive'              => UploadedFile::fake()->image('exam.jpg'),
                'name'                 => 'Exame Exams Equipamento B',
            ],
            $this->second['headers'],
        )->assertCreated();

        expect(PatientExam::where('name', 'Exame Exams Equipamento B')->value('entity_integrator_equipment_id'))
            ->toBe($this->equipmentB->id);
    });

    it('recusa com 422 codigo EIQ duplicado no mesmo integrador em vez de escolher um', function () {
        $twin = EntityIntegratorEquipment::factory()->create(['integrator_id' => $this->second['integrator']->id]);
        $twin->forceFill(['code' => $this->equipmentB->code])->save();

        $this->postJson(
            "/api/integrators/v1/patients/{$this->patient->id}/exams",
            [
                'exam_identifier'      => $this->examType->code,
                'schedule_identifier'  => $this->schedule->id,
                'equipment_identifier' => '1',
                'archive'              => UploadedFile::fake()->image('exam.jpg'),
                'name'                 => 'Exame Equipamento Duplicado',
            ],
            $this->second['headers'],
        )->assertStatus(422)
            ->assertJsonPath('errors.equipment_identifier.0', __('record_codes.ambiguous_identifier.equipment'));

        expect(PatientExam::where('name', 'Exame Equipamento Duplicado')->exists())->toBeFalse();
    });

    it('POST /exams tambem recusa codigo EIQ duplicado (guard no service)', function () {
        $twin = EntityIntegratorEquipment::factory()->create(['integrator_id' => $this->second['integrator']->id]);
        $twin->forceFill(['code' => $this->equipmentB->code])->save();

        $this->postJson(
            '/api/integrators/v1/exams',
            [
                'exam_identifier'      => $this->examType->code,
                'schedule_identifier'  => $this->schedule->id,
                'equipment_identifier' => '1',
                'archive'              => UploadedFile::fake()->image('exam.jpg'),
                'name'                 => 'Exame Exams Duplicado',
            ],
            $this->second['headers'],
        )->assertStatus(422)
            ->assertJsonPath('errors.equipment_identifier.0', __('record_codes.ambiguous_identifier.equipment'));

        expect(PatientExam::where('name', 'Exame Exams Duplicado')->exists())->toBeFalse();
    });
});

describe('PUT /api/integrators/v1/equipments/{numero} — regra unique', function () {
    beforeEach(function () {
        // Outra clínica cadastrou o EIQ-0000000001 dela ANTES.
        $foreign = setupIntegrator();
        EntityIntegratorEquipment::factory()->create([
            'integrator_id' => $foreign['integrator']->id,
            'name'          => 'RETINOGRAFO',
        ]);

        $this->ctx       = setupIntegrator();
        $this->equipment = EntityIntegratorEquipment::factory()->create([
            'integrator_id' => $this->ctx['integrator']->id,
            'name'          => 'RETINOGRAFO',
            'mac'           => 'AA:BB:CC:DD:EE:01',
        ]);
    });

    it('reenviar o proprio nome/MAC pelo numero nao da 422 por causa do EIQ de outra clinica', function () {
        expect($this->equipment->code)->toBe('EIQ-0000000001');

        $this->putJson('/api/integrators/v1/equipments/1', [
            'name' => 'Retinografo',
            'mac'  => 'AA:BB:CC:DD:EE:01',
        ], $this->ctx['headers'])
            ->assertOk()
            ->assertJsonFragment(['name' => 'RETINOGRAFO']);
    });

    it('continua recusando nome de OUTRO equipamento do mesmo integrador ao editar pelo codigo', function () {
        EntityIntegratorEquipment::factory()->create([
            'integrator_id' => $this->ctx['integrator']->id,
            'name'          => 'TOPOGRAFO',
        ]);

        $this->putJson("/api/integrators/v1/equipments/{$this->equipment->code}", [
            'name' => 'Topografo',
        ], $this->ctx['headers'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');
    });
});

it('[CONCORRENCIA] serializa a numeracao EIQ do mesmo integrador ate o commit de quem esta criando', function () {
    $ctx    = setupIntegrator();
    $second = eiqSecondIntegrator($ctx);

    $rival = eiqRivalConnection();
    $rival->beginTransaction();
    $rival->select('select pg_advisory_xact_lock(?)', [EntityIntegratorEquipment::codeLockKey($ctx['integrator']->id)]);

    try {
        DB::statement("SET LOCAL lock_timeout = '300ms'");

        expect(fn () => EntityIntegratorEquipment::factory()->create(['integrator_id' => $ctx['integrator']->id]))
            ->toThrow(QueryException::class, 'lock timeout');

        // Outro integrador (mesma clínica) não é bloqueado.
        expect(EntityIntegratorEquipment::factory()->create(['integrator_id' => $second['integrator']->id])->code)
            ->toBe('EIQ-0000000001');
    } finally {
        $rival->rollBack();
        DB::purge('pgsql_eiq_rival');
        DB::statement('SET LOCAL lock_timeout = 0');
    }

    expect(EntityIntegratorEquipment::factory()->create(['integrator_id' => $ctx['integrator']->id])->code)
        ->toBe('EIQ-0000000001');
});
