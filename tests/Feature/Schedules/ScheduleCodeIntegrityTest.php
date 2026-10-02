<?php

/**
 * F3 — código SDL- por clínica.
 *
 * Antes: Schedule::booted() lia o último código e somava 1 sem lock nem
 * transação, e schedules.code não tem índice único — dois agendamentos
 * simultâneos na mesma clínica gravavam o MESMO código em silêncio. Agora a
 * numeração é serializada por clínica (advisory lock de transação, mantido até
 * o COMMIT do INSERT).
 *
 * Também cobre a distinção de violações de unicidade: só o índice de horário
 * do médico vira "horário ocupado" (store/update do painel e import).
 */

use App\Enums\{ClientRule, ImportStatus, ScheduleSituation};
use App\Models\{Doctor, Entity, Schedule, ScheduleImport, User};
use App\Services\ScheduleImportService;
use Illuminate\Database\{Connection, QueryException, UniqueConstraintViolationException};
use Illuminate\Support\Facades\{DB, Storage};

/** Segunda sessão PostgreSQL (fora da transação do teste) para disputar o lock da numeração. */
function sdlRivalConnection(): Connection
{
    config(['database.connections.pgsql_sdl_rival' => config('database.connections.pgsql')]);

    return DB::connection('pgsql_sdl_rival');
}

/** @param array<string, mixed> $overrides */
function sdlScheduleAttributes(Entity $entity, Doctor $doctor, array $overrides = []): array
{
    return array_merge([
        'entity_id' => $entity->id,
        'doctor_id' => $doctor->id,
        'full_name' => 'Paciente Codigo',
        'date_time' => now()->addDays(random_int(1, 300))->setTime(random_int(7, 18), 0),
        'situation' => ScheduleSituation::Scheduled->value,
        'active'    => true,
    ], $overrides);
}

/** Violação de unicidade como o driver pgsql entrega (errorInfo[2] = mensagem do servidor, sem SQL). */
function sdlUniqueViolation(string $constraint, string $sql = 'insert into "schedules" ("code") values (?)', array $bindings = []): UniqueConstraintViolationException
{
    $serverMessage  = sprintf('ERROR:  duplicate key value violates unique constraint "%s"', $constraint);
    $pdo            = new PDOException('SQLSTATE[23505]: Unique violation: 7 ' . $serverMessage);
    $pdo->errorInfo = ['23505', 7, $serverMessage];

    return new UniqueConstraintViolationException('pgsql', $sql, $bindings, $pdo);
}

beforeEach(function () {
    $this->entity = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $this->doctor = createDoctorForEntity($this->entity);
});

it('numera SDL em sequência por clínica, sem reaproveitar código de agendamento excluído', function () {
    $first  = Schedule::create(sdlScheduleAttributes($this->entity, $this->doctor));
    $second = Schedule::create(sdlScheduleAttributes($this->entity, $this->doctor));
    $second->delete();
    $third = Schedule::create(sdlScheduleAttributes($this->entity, $this->doctor));

    $otherEntity   = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $otherSchedule = Schedule::create(sdlScheduleAttributes($otherEntity, createDoctorForEntity($otherEntity)));

    expect($first->code)->toBe('SDL-0000000001')
        ->and($second->code)->toBe('SDL-0000000002')
        ->and($third->code)->toBe('SDL-0000000003')
        ->and($otherSchedule->code)->toBe('SDL-0000000001');
});

it('[CONCORRENCIA] serializa a numeracao SDL da mesma clinica ate o commit de quem esta criando', function () {
    $otherEntity = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $otherDoctor = createDoctorForEntity($otherEntity);

    // Outra sessão = outro agendamento da MESMA clínica sendo criado agora
    // (entre calcular o código e dar COMMIT).
    $rival = sdlRivalConnection();
    $rival->beginTransaction();
    $rival->select('select pg_advisory_xact_lock(?)', [Schedule::codeLockKey($this->entity->id)]);

    try {
        DB::statement("SET LOCAL lock_timeout = '300ms'");

        // Mesma clínica: precisa ESPERAR (aqui estoura o lock_timeout). Antes
        // não esperava — lia o mesmo "último código" e repetia o número.
        expect(fn () => Schedule::create(sdlScheduleAttributes($this->entity, $this->doctor)))
            ->toThrow(QueryException::class, 'lock timeout');

        // Outra clínica: não é bloqueada (lock por clínica, não global).
        $otherSchedule = Schedule::create(sdlScheduleAttributes($otherEntity, $otherDoctor));
        expect($otherSchedule->code)->toBe('SDL-0000000001');
    } finally {
        $rival->rollBack();
        DB::purge('pgsql_sdl_rival');
        DB::statement('SET LOCAL lock_timeout = 0');
    }

    // Timeout desfez só o savepoint do INSERT: a transação segue válida e,
    // liberado o lock, a numeração continua sem buraco nem repetição.
    $schedule = Schedule::create(sdlScheduleAttributes($this->entity, $this->doctor));

    expect($schedule->code)->toBe('SDL-0000000001')
        ->and(Schedule::where('entity_id', $this->entity->id)->count())->toBe(1);
});

it('so trata como conflito de horario a violacao do indice de horario do medico', function () {
    expect(Schedule::isDoctorSlotConflict(sdlUniqueViolation(Schedule::DOCTOR_SLOT_UNIQUE_INDEX)))->toBeTrue()
        ->and(Schedule::isDoctorSlotConflict(sdlUniqueViolation('schedules_entity_id_import_code_unique')))->toBeFalse()
        ->and(Schedule::isDoctorSlotConflict(sdlUniqueViolation('schedules_entity_code_unique')))->toBeFalse();

    // Nome do índice só nos BINDINGS (texto digitado pelo usuário) não conta —
    // a decisão olha a mensagem do driver, não o SQL formatado.
    $viaBindings = sdlUniqueViolation(
        'schedules_entity_code_unique',
        'insert into "schedules" ("notes") values (?)',
        [Schedule::DOCTOR_SLOT_UNIQUE_INDEX],
    );
    expect($viaBindings->getMessage())->toContain(Schedule::DOCTOR_SLOT_UNIQUE_INDEX)
        ->and(Schedule::isDoctorSlotConflict($viaBindings))->toBeFalse();

    // SQLite não traz o nome do índice, só as colunas.
    $sqlitePdo            = new PDOException('SQLSTATE[23000]: Integrity constraint violation: 19 UNIQUE constraint failed: schedules.doctor_id, schedules.date_time');
    $sqlitePdo->errorInfo = ['23000', 19, 'UNIQUE constraint failed: schedules.doctor_id, schedules.date_time'];

    expect(Schedule::isDoctorSlotConflict(new UniqueConstraintViolationException('sqlite', 'insert into "schedules"', [], $sqlitePdo)))->toBeTrue();
});

it('POST /panel/schedules: violacao unica que NAO e de horario nao vira "horario ocupado"', function () {
    $user       = User::factory()->create();
    $entityUser = createEntityUser($this->entity, $user, ClientRule::Admin->value);

    Schedule::creating(function (): void {
        throw sdlUniqueViolation('schedules_entity_code_unique');
    });

    $response = $this->actingAs($user)
        ->withSession(panelSession($entityUser))
        ->postJson(route('panel.schedules.store'), [
            'doctor_id' => $this->doctor->id,
            'full_name' => 'Paciente Violacao',
            'date_time' => now()->addDay()->setTime(10, 0)->format('Y-m-d H:i:s'),
        ]);

    $response->assertStatus(500);
    expect($response->json('errors.date_time'))->toBeNull()
        ->and(Schedule::where('entity_id', $this->entity->id)->count())->toBe(0);
});

it('POST /panel/schedules: violacao do indice de horario continua 422 com a mensagem traduzida', function () {
    $user       = User::factory()->create();
    $entityUser = createEntityUser($this->entity, $user, ClientRule::Admin->value);

    // Simula a corrida em que o outro agendamento do mesmo horário é gravado
    // entre a validação do slot e o INSERT.
    Schedule::creating(function (): void {
        throw sdlUniqueViolation(Schedule::DOCTOR_SLOT_UNIQUE_INDEX);
    });

    $this->actingAs($user)
        ->withSession(panelSession($entityUser))
        ->postJson(route('panel.schedules.store'), [
            'doctor_id' => $this->doctor->id,
            'full_name' => 'Paciente Horario',
            'date_time' => now()->addDay()->setTime(11, 0)->format('Y-m-d H:i:s'),
        ])
        ->assertStatus(422)
        ->assertJsonPath('errors.date_time.0', __('validation.custom.schedule.doctor_datetime_unique'));
});

it('import: violacao unica que NAO e de horario vira erro traduzido na linha, sem SQL nem "horario ocupado"', function () {
    Storage::fake();
    $this->doctor->update(['record' => '424242']);

    $path = "imports/schedules/{$this->entity->id}/sdl-integrity.csv";
    Storage::disk()->put($path, "\xEF\xBB\xBFcrm_medico;nome_paciente;data_hora\n424242;Paciente Import;15/06/2027 14:30\n");

    $import = ScheduleImport::create([
        'entity_id'     => $this->entity->id,
        'user_id'       => User::factory()->create()->id,
        'status'        => ImportStatus::Pending,
        'file_path'     => $path,
        'original_name' => 'sdl-integrity.csv',
    ]);

    Schedule::creating(function (): void {
        throw sdlUniqueViolation('schedules_entity_code_unique');
    });

    app(ScheduleImportService::class)->process($import);

    $import->refresh();
    $errors = Storage::disk()->get($import->errors_file_path);

    expect($import->error_rows)->toBe(1)
        ->and($import->imported_rows)->toBe(0)
        ->and($errors)->toContain(__('record_codes.unexpected_unique_conflict'))
        ->and($errors)->not->toContain(__('validation.custom.schedule.doctor_datetime_unique'))
        ->and($errors)->not->toContain('insert into');
});
