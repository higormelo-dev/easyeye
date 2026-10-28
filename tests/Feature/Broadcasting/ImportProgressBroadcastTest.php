<?php

use App\Broadcasting\{ClinicImportChannel, ManagerMedicineImportChannel};
use App\Enums\{ClientRule, ImportStatus, SaasRule};
use App\Events\ImportProgressUpdated;
use App\Models\{Entity, MedicineImport, PatientImport, ScheduleImport, User};
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\{ShouldBroadcastNow, ShouldRescue};
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Support\Facades\{Event, Route};

/**
 * Progresso das importações via WebSocket (Reverb): evento disparado pelos
 * models de importação + autorização dos canais privados. Sem endpoint HTTP
 * de status.
 */
function progressClinic(): Entity
{
    return Entity::factory()->create(['is_client' => true, 'active' => true]);
}

function progressPatientImport(Entity $entity, array $attributes = []): PatientImport
{
    return PatientImport::query()->create(array_merge([
        'entity_id'     => $entity->id,
        'user_id'       => User::factory()->create()->id,
        'status'        => ImportStatus::Processing,
        'file_path'     => 'imports/patients/x.csv',
        'original_name' => 'pacientes.csv',
        'total_rows'    => 100,
    ], $attributes));
}

it('evento: canal privado, nome import.progress, WebSocket nunca derruba a importação', function () {
    $event = new ImportProgressUpdated('imports.patients.abc', ['id' => 'abc', 'progress' => 50]);

    expect($event)->toBeInstanceOf(ShouldBroadcastNow::class)
        ->and($event)->toBeInstanceOf(ShouldRescue::class)
        ->and($event)->toBeInstanceOf(ShouldDispatchAfterCommit::class)
        ->and($event->broadcastAs())->toBe('import.progress')
        ->and($event->broadcastWith())->toBe(['id' => 'abc', 'progress' => 50])
        ->and($event->broadcastOn()[0])->toBeInstanceOf(PrivateChannel::class)
        ->and($event->broadcastOn()[0]->name)->toBe('private-imports.patients.abc');
});

it('atualizar o progresso da importação transmite o estado da barra', function () {
    Event::fake([ImportProgressUpdated::class]);
    $import = progressPatientImport(progressClinic());

    $import->update(['processed_rows' => 50, 'imported_rows' => 48]);

    Event::assertDispatched(ImportProgressUpdated::class, fn (ImportProgressUpdated $e) => $e->channel === "imports.patients.{$import->id}"
        && $e->payload['processed_rows'] === 50
        && $e->payload['progress'] === 50
        && $e->payload['channel'] === "imports.patients.{$import->id}");
});

it('avanço de linhas é limitado (no máximo 2/s); mudança de status sempre transmite', function () {
    Event::fake([ImportProgressUpdated::class]);
    $import = progressPatientImport(progressClinic());

    $import->update(['processed_rows' => 25]);
    $import->update(['processed_rows' => 50]); // < 0,5 s depois: não transmite
    $import->update(['status' => ImportStatus::Done, 'processed_rows' => 100]);

    Event::assertDispatchedTimes(ImportProgressUpdated::class, 2);
    Event::assertDispatched(ImportProgressUpdated::class, fn ($e) => $e->payload['is_done'] === true && $e->payload['progress'] === 100);
});

it('não existem mais endpoints HTTP de status das importações', function () {
    foreach (['panel.patients.import.status', 'panel.doctors.import.status', 'panel.schedules.import.status', 'manager.medicines.imports.status'] as $name) {
        expect(Route::has($name))->toBeFalse();
    }
});

describe('autorização do canal imports.{type}.{id} (clínica)', function () {
    beforeEach(function () {
        $this->clinic = progressClinic();
        $this->import = progressPatientImport($this->clinic);
    });

    function joinClinicChannel(User $user, string $type, string $id, ?string $sessionEntity): bool
    {
        session(['selected_entity_id' => $sessionEntity]);

        return app(ClinicImportChannel::class)->join($user, $type, $id);
    }

    it('admin da clínica dona, com a clínica na sessão, entra', function () {
        $user = User::factory()->create();
        createEntityUser($this->clinic, $user, ClientRule::Admin->value);

        expect(joinClinicChannel($user, 'patients', $this->import->id, $this->clinic->id))->toBeTrue();
    });

    it('[SEGURANÇA] usuário de OUTRA clínica não entra', function () {
        $other = progressClinic();
        $user  = User::factory()->create();
        createEntityUser($other, $user, ClientRule::Admin->value);

        expect(joinClinicChannel($user, 'patients', $this->import->id, $other->id))->toBeFalse();
    });

    it('[SEGURANÇA] membro da clínica mas com outra clínica na sessão não entra', function () {
        $user = User::factory()->create();
        createEntityUser($this->clinic, $user, ClientRule::Admin->value);

        expect(joinClinicChannel($user, 'patients', $this->import->id, progressClinic()->id))->toBeFalse();
    });

    it('[SEGURANÇA] papel sem acesso à tela não entra (financeiro na agenda)', function () {
        $schedule = ScheduleImport::query()->create([
            'entity_id' => $this->clinic->id, 'user_id' => User::factory()->create()->id,
            'status'    => ImportStatus::Processing, 'file_path' => 'x.csv', 'original_name' => 'agenda.csv',
        ]);
        $user = User::factory()->create();
        createEntityUser($this->clinic, $user, ClientRule::Financial->value);

        expect(joinClinicChannel($user, 'schedules', $schedule->id, $this->clinic->id))->toBeFalse();
    });

    it('[SEGURANÇA] tipo desconhecido ou id inválido não entra', function () {
        $user = User::factory()->create();
        createEntityUser($this->clinic, $user, ClientRule::Admin->value);

        expect(joinClinicChannel($user, 'invoices', $this->import->id, $this->clinic->id))->toBeFalse()
            ->and(joinClinicChannel($user, 'patients', 'nao-e-uuid', $this->clinic->id))->toBeFalse();
    });
});

describe('autorização do canal manager.imports.medicines.{id}', function () {
    beforeEach(function () {
        $this->saas   = Entity::factory()->create(['is_client' => false, 'active' => true]);
        $this->import = MedicineImport::query()->create([
            'status' => ImportStatus::Processing, 'cmed_file_path' => 'x', 'cmed_original_name' => 'x.xlsx',
        ]);
    });

    function joinMedicineChannel(User $user, Entity $entity, string $id): bool
    {
        session(['selected_entity_id' => $entity->id, 'selected_entity_is_client' => $entity->isClient()]);

        return app(ManagerMedicineImportChannel::class)->join($user, $id);
    }

    it('admin SaaS entra', function () {
        $user = User::factory()->create();
        createEntityUser($this->saas, $user, SaasRule::Admin->value);

        expect(joinMedicineChannel($user, $this->saas, $this->import->id))->toBeTrue();
    });

    it('[SEGURANÇA] suporte SaaS não entra', function () {
        $user = User::factory()->create();
        createEntityUser($this->saas, $user, SaasRule::Support->value);

        expect(joinMedicineChannel($user, $this->saas, $this->import->id))->toBeFalse();
    });

    it('[SEGURANÇA] usuário de clínica não entra', function () {
        $clinic = progressClinic();
        $user   = User::factory()->create();
        createEntityUser($clinic, $user, ClientRule::Admin->value);

        expect(joinMedicineChannel($user, $clinic, $this->import->id))->toBeFalse();
    });
});

it('rota /broadcasting/auth assina o canal autorizado e recusa o não autorizado', function () {
    config([
        'broadcasting.default'                   => 'reverb',
        'broadcasting.connections.reverb.key'    => 'test-key',
        'broadcasting.connections.reverb.secret' => 'test-secret',
        'broadcasting.connections.reverb.app_id' => '1',
    ]);
    // Canais são registrados no boot, no broadcaster padrão dos testes
    // (null) — registra de novo no reverb (em produção ele já é o padrão).
    require base_path('routes/channels.php');

    $clinic = progressClinic();
    $import = progressPatientImport($clinic);
    $user   = User::factory()->create();
    $eu     = createEntityUser($clinic, $user, ClientRule::Admin->value);

    $this->actingAs($user)->withSession(panelSession($eu))
        ->post('/broadcasting/auth', ['socket_id' => '123.456', 'channel_name' => "private-imports.patients.{$import->id}"])
        ->assertOk()
        ->assertJsonStructure(['auth']);

    $outsider = User::factory()->create();
    $other    = createEntityUser(progressClinic(), $outsider, ClientRule::Admin->value);

    $this->actingAs($outsider)->withSession(panelSession($other))
        ->post('/broadcasting/auth', ['socket_id' => '123.456', 'channel_name' => "private-imports.patients.{$import->id}"])
        ->assertForbidden();
});
