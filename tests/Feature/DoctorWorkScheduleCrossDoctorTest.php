<?php

declare(strict_types=1);

/**
 * Regressão de segurança: doctors.work-schedule.sync (PUT), doctors.blocks.store
 * (POST) e doctors.blocks.destroy (DELETE) ficam no grupo de middleware
 * entity.role:admin,doctor,secretary — acessível por um médico comum, não só
 * admin/secretary. DoctorWorkScheduleController::findDoctor() só validava que
 * o {doctor} da rota pertence à entity da sessão, sem comparar com o próprio
 * registro de médico de quem está autenticado. Assim, qualquer médico da
 * clínica conseguia sobrescrever a agenda recorrente (sync faz delete+recreate
 * sem dono) ou fabricar/apagar bloqueios de ausência de um colega, bastando
 * informar o doctor_id dele (descobrível via GET /panel/waiting-list).
 */

use App\Enums\ClientRule;
use App\Models\{Doctor, DoctorWorkSchedule, Entity, People, ScheduleBlock, User};

beforeEach(function () {
    $this->entity = Entity::factory()->create(['is_client' => true]);

    // Dr. A (ator) e Dr. B (vítima), mesma entidade.
    $userA             = User::factory()->create();
    $this->entityUserA = createEntityUser($this->entity, $userA, ClientRule::Doctor->value);
    $this->doctorA     = Doctor::create([
        'entity_user_id' => $this->entityUserA->id,
        'person_id'      => People::factory()->create()->id,
        'record'         => 'A-1',
        'color'          => '#FF0000',
        'partner'        => false,
        'active'         => true,
    ]);

    $userB             = User::factory()->create();
    $this->entityUserB = createEntityUser($this->entity, $userB, ClientRule::Doctor->value);
    $this->doctorB     = Doctor::create([
        'entity_user_id' => $this->entityUserB->id,
        'person_id'      => People::factory()->create()->id,
        'record'         => 'B-1',
        'color'          => '#00FF00',
        'partner'        => false,
        'active'         => true,
    ]);

    $this->sessionDoctorA = array_merge(panelSession($this->entityUserA), [
        'user_rule' => ClientRule::Doctor->value,
    ]);
});

function workScheduleSyncPayload(): array
{
    return [
        'schedule_interval' => 15,
        'days'              => [
            [
                'day'    => 1,
                'active' => true,
                'ranges' => [['starts_at' => '08:00', 'ends_at' => '12:00']],
            ],
        ],
    ];
}

test('médico não pode sobrescrever a agenda de trabalho de outro médico da mesma clínica', function () {
    // Arrange: Dr. B já possui uma agenda configurada.
    DoctorWorkSchedule::create([
        'doctor_id'   => $this->doctorB->id,
        'day_of_week' => 2,
        'starts_at'   => '09:00:00',
        'ends_at'     => '11:00:00',
    ]);

    // Act: Dr. A tenta sobrescrever a agenda de Dr. B.
    $response = $this->actingAs($this->entityUserA->user)
        ->withSession($this->sessionDoctorA)
        ->putJson("/panel/doctors/{$this->doctorB->id}/work-schedule", workScheduleSyncPayload());

    // Assert: bloqueado, e a agenda original de Dr. B permanece intacta.
    $response->assertForbidden();
    expect(DoctorWorkSchedule::where('doctor_id', $this->doctorB->id)->where('day_of_week', 2)->exists())->toBeTrue();
    expect(DoctorWorkSchedule::where('doctor_id', $this->doctorB->id)->where('day_of_week', 1)->exists())->toBeFalse();
});

test('médico não pode criar bloqueio de ausência na agenda de outro médico', function () {
    $response = $this->actingAs($this->entityUserA->user)
        ->withSession($this->sessionDoctorA)
        ->postJson("/panel/doctors/{$this->doctorB->id}/blocks", [
            'starts_at' => now()->addDay()->format('Y-m-d H:i:s'),
            'ends_at'   => now()->addDay()->addHour()->format('Y-m-d H:i:s'),
            'reason'    => 'Sabotagem',
            'type'      => 'absence',
        ]);

    $response->assertForbidden();
    expect(ScheduleBlock::where('doctor_id', $this->doctorB->id)->count())->toBe(0);
});

test('médico não pode apagar bloqueio de ausência de outro médico', function () {
    $block = ScheduleBlock::create([
        'doctor_id' => $this->doctorB->id,
        'starts_at' => now()->addDay(),
        'ends_at'   => now()->addDay()->addHour(),
        'reason'    => 'Congresso',
        'type'      => 'other',
    ]);

    $response = $this->actingAs($this->entityUserA->user)
        ->withSession($this->sessionDoctorA)
        ->deleteJson("/panel/doctors/{$this->doctorB->id}/blocks/{$block->id}");

    $response->assertForbidden();
    expect(ScheduleBlock::find($block->id))->not->toBeNull();
});

test('médico consegue gerenciar normalmente a própria agenda e os próprios bloqueios', function () {
    $syncResponse = $this->actingAs($this->entityUserA->user)
        ->withSession($this->sessionDoctorA)
        ->putJson("/panel/doctors/{$this->doctorA->id}/work-schedule", workScheduleSyncPayload());

    $syncResponse->assertOk();
    expect(DoctorWorkSchedule::where('doctor_id', $this->doctorA->id)->where('day_of_week', 1)->exists())->toBeTrue();

    $storeResponse = $this->actingAs($this->entityUserA->user)
        ->withSession($this->sessionDoctorA)
        ->postJson("/panel/doctors/{$this->doctorA->id}/blocks", [
            'starts_at' => now()->addDay()->format('Y-m-d H:i:s'),
            'ends_at'   => now()->addDay()->addHour()->format('Y-m-d H:i:s'),
            'reason'    => 'Consulta pessoal',
            'type'      => 'other',
        ]);

    $storeResponse->assertCreated();
    $blockId = $storeResponse->json('data.id');

    $destroyResponse = $this->actingAs($this->entityUserA->user)
        ->withSession($this->sessionDoctorA)
        ->deleteJson("/panel/doctors/{$this->doctorA->id}/blocks/{$blockId}");

    $destroyResponse->assertOk();
    expect(ScheduleBlock::find($blockId))->toBeNull();
});

test('admin da mesma entidade continua podendo gerenciar a agenda de qualquer médico', function () {
    $admin           = User::factory()->create();
    $adminEntityUser = createEntityUser($this->entity, $admin, ClientRule::Admin->value);
    $adminSession    = array_merge(panelSession($adminEntityUser), [
        'user_rule' => ClientRule::Admin->value,
    ]);

    $response = $this->actingAs($admin)
        ->withSession($adminSession)
        ->putJson("/panel/doctors/{$this->doctorB->id}/work-schedule", workScheduleSyncPayload());

    $response->assertOk();
    expect(DoctorWorkSchedule::where('doctor_id', $this->doctorB->id)->where('day_of_week', 1)->exists())->toBeTrue();
});

test('secretary da mesma entidade continua podendo gerenciar bloqueios de qualquer médico', function () {
    $secretary           = User::factory()->create();
    $secretaryEntityUser = createEntityUser($this->entity, $secretary, ClientRule::Secretary->value);
    $secretarySession    = array_merge(panelSession($secretaryEntityUser), [
        'user_rule' => ClientRule::Secretary->value,
    ]);

    $response = $this->actingAs($secretary)
        ->withSession($secretarySession)
        ->postJson("/panel/doctors/{$this->doctorB->id}/blocks", [
            'starts_at' => now()->addDay()->format('Y-m-d H:i:s'),
            'ends_at'   => now()->addDay()->addHour()->format('Y-m-d H:i:s'),
            'reason'    => 'Feriado municipal',
            'type'      => 'holiday',
        ]);

    $response->assertCreated();
    expect(ScheduleBlock::where('doctor_id', $this->doctorB->id)->count())->toBe(1);
});
