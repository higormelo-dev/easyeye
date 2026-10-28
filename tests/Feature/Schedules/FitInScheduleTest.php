<?php

declare(strict_types=1);

use App\Enums\{ClientRule, ScheduleSituation};
use App\Enums\ImportStatus;
use App\Exceptions\ScheduleSlotUnavailableException;
use App\Models\{ClinicResource, Doctor, DoctorWorkSchedule, Entity, ResourceBlock, ResourceWorkSchedule, Schedule, ScheduleBlock, User};
use App\Models\ScheduleImport;
use App\Services\{ScheduleImportService, ScheduleService};
use Carbon\Carbon;
use Illuminate\Database\{Connection, QueryException, UniqueConstraintViolationException};
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\{DB, Storage};

/*
 * Fase 1 / A2 — encaixe com limite por turno.
 *
 * Encaixe pode ocupar horário já tomado e fora do alinhamento do intervalo;
 * continua respeitando expediente e bloqueios do médico e TODAS as regras da
 * sala. Teto por médico + dia + turno (padrão 2; 0 = não aceita), contando
 * encaixes não cancelados, conferido sob lock — dois encaixes simultâneos não
 * furam o teto. Remarcar/editar/lote/série mantêm ou validam a flag.
 */
beforeEach(function () {
    $this->entity = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $this->doctor = createDoctorForEntity($this->entity);

    // Seg–sex 08–12 e 13–18, intervalo de 15 min.
    for ($d = 1; $d <= 5; $d++) {
        DoctorWorkSchedule::create(['doctor_id' => $this->doctor->id, 'day_of_week' => $d, 'starts_at' => '08:00:00', 'ends_at' => '12:00:00']);
        DoctorWorkSchedule::create(['doctor_id' => $this->doctor->id, 'day_of_week' => $d, 'starts_at' => '13:00:00', 'ends_at' => '18:00:00']);
    }
    $this->doctor->update(['schedule_interval' => 15]);

    $this->secretary = createEntityUser($this->entity, User::factory()->create(), ClientRule::Secretary->value);
    $this->monday    = Carbon::now()->next(Carbon::MONDAY)->setTime(9, 0);
});

function fitAs(object $test, $entityUser = null): object
{
    $entityUser ??= $test->secretary;

    return $test->actingAs($entityUser->user)->withSession(panelSession($entityUser));
}

function fitPayload(object $test, Carbon $at, array $extra = []): array
{
    return array_merge([
        'doctor_id' => $test->doctor->id,
        'full_name' => 'PACIENTE ENCAIXE',
        'date_time' => $at->format('Y-m-d H:i'),
        'is_fit_in' => true,
    ], $extra);
}

function fitSchedule(object $test, Carbon $at, array $extra = []): Schedule
{
    return Schedule::create(array_merge([
        'entity_id' => $test->entity->id,
        'doctor_id' => $test->doctor->id,
        'full_name' => 'JÁ AGENDADO',
        'date_time' => $at,
        'situation' => ScheduleSituation::Scheduled->value,
        'active'    => true,
    ], $extra));
}

function fitLimitMessage(int $limit, string $shift, Carbon $at): string
{
    return trans_choice('schedules.fit_in_limit_reached', $limit, [
        'limit' => $limit,
        'shift' => __('schedules.shift_' . $shift),
        'date'  => $at->format('d/m/Y'),
    ]);
}

/** Segunda sessão PostgreSQL (fora da transação do teste) para disputar a cota. */
function fitRivalConnection(): Connection
{
    config(['database.connections.pgsql_fit_rival' => config('database.connections.pgsql')]);

    return DB::connection('pgsql_fit_rival');
}

describe('onde o encaixe pode cair', function () {
    it('sobre horário já ocupado: grava como encaixe; dois normais no mesmo horário continuam barrados', function () {
        fitSchedule($this, $this->monday);

        $id = fitAs($this)->postJson(route('panel.schedules.store'), fitPayload($this, $this->monday))
            ->assertCreated()
            ->json('data.id');

        expect(Schedule::find($id)->is_fit_in)->toBeTrue();

        fitAs($this)->postJson(route('panel.schedules.store'), fitPayload($this, $this->monday, ['is_fit_in' => false, 'full_name' => 'OUTRO NORMAL']))
            ->assertUnprocessable()
            ->assertJsonPath('errors.date_time.0', __('validation.custom.schedule.doctor_datetime_unique'));

        // O índice único do banco também segue valendo para os normais.
        expect(fn () => fitSchedule($this, $this->monday, ['full_name' => 'DIRETO NO BANCO']))
            ->toThrow(UniqueConstraintViolationException::class);
    });

    it('fora do alinhamento do intervalo (09:07): encaixe passa, horário normal não', function () {
        $offGrid = $this->monday->copy()->setTime(9, 7);

        fitAs($this)->postJson(route('panel.schedules.store'), fitPayload($this, $offGrid, ['is_fit_in' => false]))
            ->assertUnprocessable()
            ->assertJsonPath('errors.date_time.0', __('actions.work_schedule_interval_error', ['interval' => 15]));

        fitAs($this)->postJson(route('panel.schedules.store'), fitPayload($this, $offGrid))->assertCreated();
    });

    it('fora do expediente ou em bloqueio do médico: recusado mesmo como encaixe', function () {
        ScheduleBlock::create(['doctor_id' => $this->doctor->id, 'starts_at' => $this->monday->copy()->setTime(10, 0), 'ends_at' => $this->monday->copy()->setTime(11, 0), 'type' => 'meeting']);

        fitAs($this)->postJson(route('panel.schedules.store'), fitPayload($this, $this->monday->copy()->setTime(12, 30)))
            ->assertUnprocessable()
            ->assertJsonPath('errors.date_time.0', __('actions.work_schedule_outside', ['day' => __('actions.weekdays')[1], 'time' => '12:30']));

        fitAs($this)->postJson(route('panel.schedules.store'), fitPayload($this, $this->monday->copy()->addDays(5)))
            ->assertUnprocessable();

        fitAs($this)->postJson(route('panel.schedules.store'), fitPayload($this, $this->monday->copy()->setTime(10, 15)))
            ->assertUnprocessable()
            ->assertJsonPath('errors.date_time.0', __('actions.work_schedule_blocked'));

        expect(Schedule::count())->toBe(0);
    });

    it('sala ocupada, fora da escala ou bloqueada: recusado mesmo como encaixe', function () {
        $room = ClinicResource::create(['entity_id' => $this->entity->id, 'name' => 'YAG LASER', 'type' => 'equipment', 'active' => true]);
        ResourceWorkSchedule::create(['resource_id' => $room->id, 'day_of_week' => 1, 'starts_at' => '08:00:00', 'ends_at' => '11:00:00']);
        ResourceBlock::create(['resource_id' => $room->id, 'starts_at' => $this->monday->copy()->setTime(10, 0), 'ends_at' => $this->monday->copy()->setTime(11, 0), 'type' => 'other']);
        fitSchedule($this, $this->monday)->resources()->sync([$room->id]);

        $cases = [
            [$this->monday->copy(), __('actions.resource_double_booked', ['name' => 'YAG LASER'])],
            [$this->monday->copy()->setTime(11, 15), __('actions.resource_unavailable', ['name' => 'YAG LASER', 'day' => __('actions.weekdays')[1], 'time' => '11:15'])],
            [$this->monday->copy()->setTime(10, 30), __('actions.resource_blocked', ['name' => 'YAG LASER'])],
        ];

        foreach ($cases as [$at, $message]) {
            fitAs($this)->postJson(route('panel.schedules.store'), fitPayload($this, $at, ['resource_ids' => [$room->id]]))
                ->assertUnprocessable()
                ->assertJsonPath('errors.date_time.0', $message);
        }

        expect(Schedule::where('is_fit_in', true)->count())->toBe(0);
    });
});

describe('teto por turno', function () {
    it('padrão 2 por turno: o 3º é recusado com o limite; outro turno e encaixe cancelado liberam; falta conta', function () {
        fitAs($this)->postJson(route('panel.schedules.store'), fitPayload($this, $this->monday))->assertCreated();
        fitAs($this)->postJson(route('panel.schedules.store'), fitPayload($this, $this->monday->copy()->setTime(10, 0)))->assertCreated();

        fitAs($this)->postJson(route('panel.schedules.store'), fitPayload($this, $this->monday->copy()->setTime(11, 0)))
            ->assertUnprocessable()
            ->assertJsonPath('errors.date_time.0', fitLimitMessage(2, 'morning', $this->monday));

        // Tarde é outra cota; outro dia também.
        fitAs($this)->postJson(route('panel.schedules.store'), fitPayload($this, $this->monday->copy()->setTime(14, 0)))->assertCreated();
        fitAs($this)->postJson(route('panel.schedules.store'), fitPayload($this, $this->monday->copy()->addDay()))->assertCreated();

        // Cancelado libera a vaga de encaixe; falta continua ocupando.
        $first = Schedule::where('is_fit_in', true)->orderBy('date_time')->first();
        $first->update(['situation' => ScheduleSituation::NoShow->value]);
        fitAs($this)->postJson(route('panel.schedules.store'), fitPayload($this, $this->monday->copy()->setTime(11, 0)))->assertUnprocessable();

        $first->update(['situation' => ScheduleSituation::Cancelled->value]);
        fitAs($this)->postJson(route('panel.schedules.store'), fitPayload($this, $this->monday->copy()->setTime(11, 0)))->assertCreated();
    });

    it('teto configurado por médico e turno; 0 = não aceita encaixe naquele turno', function () {
        $this->doctor->update(['fit_in_limit_morning' => 0, 'fit_in_limit_afternoon' => 1]);

        fitAs($this)->postJson(route('panel.schedules.store'), fitPayload($this, $this->monday))
            ->assertUnprocessable()
            ->assertJsonPath('errors.date_time.0', __('schedules.fit_in_not_accepted', ['shift' => __('schedules.shift_morning')]));

        fitAs($this)->postJson(route('panel.schedules.store'), fitPayload($this, $this->monday->copy()->setTime(14, 0)))->assertCreated();
        fitAs($this)->postJson(route('panel.schedules.store'), fitPayload($this, $this->monday->copy()->setTime(15, 0)))
            ->assertUnprocessable()
            ->assertJsonPath('errors.date_time.0', fitLimitMessage(1, 'afternoon', $this->monday));

        // Horário normal não depende do teto de encaixe.
        fitAs($this)->postJson(route('panel.schedules.store'), fitPayload($this, $this->monday, ['is_fit_in' => false]))->assertCreated();
    });

    it('[CONCORRENCIA] a contagem do teto espera a cota travada por outro encaixe do MESMO médico/dia/turno', function () {
        $service = app(ScheduleService::class);

        $rival = fitRivalConnection();
        $rival->beginTransaction();
        $rival->select('select pg_advisory_xact_lock(?)', [ScheduleService::fitInLockKey((string) $this->doctor->id, $this->monday)]);

        try {
            DB::statement("SET LOCAL lock_timeout = '300ms'");

            // Mesma cota: precisa esperar o COMMIT de quem está gravando
            // (aqui estoura o lock_timeout). Sem o lock, os dois contavam o
            // mesmo número e ambos gravavam, furando o teto.
            expect(fn () => DB::transaction(fn () => $service->assertFitInCapacity((string) $this->doctor->id, $this->monday)))
                ->toThrow(fn (QueryException $e) => expect($e->getCode())->toBe('55P03'));

            // Outro turno (outra cota) não espera.
            DB::transaction(fn () => $service->assertFitInCapacity((string) $this->doctor->id, $this->monday->copy()->setTime(14, 0)));
        } finally {
            $rival->rollBack();
            DB::purge('pgsql_fit_rival');
            DB::statement('SET LOCAL lock_timeout = 0');
        }
    });

    it('trava a cota ANTES de contar e grava o encaixe na mesma transação', function () {
        $log = [];
        DB::listen(function (QueryExecuted $query) use (&$log): void {
            $log[] = ['sql' => strtolower($query->sql), 'level' => $query->connection->transactionLevel()];
        });

        fitAs($this)->postJson(route('panel.schedules.store'), fitPayload($this, $this->monday))->assertCreated();

        $entries  = collect($log);
        $lockAt   = $entries->search(fn (array $e) => str_contains($e['sql'], 'pg_advisory_xact_lock') && ! str_contains($e['sql'], 'schedule_code'));
        $countAt  = $entries->keys()->first(fn ($i) => $i > $lockAt && str_contains($entries[$i]['sql'], 'count(*)') && str_contains($entries[$i]['sql'], '"is_fit_in" = '));
        $insertAt = $entries->search(fn (array $e) => str_starts_with($e['sql'], 'insert into "schedules"'));

        expect($lockAt)->toBeInt()
            ->and($countAt)->toBeInt()
            ->and($insertAt)->toBeInt()
            ->and($lockAt)->toBeLessThan($countAt)
            ->and($countAt)->toBeLessThan($insertAt)
            ->and($entries[$lockAt]['level'])->toBeGreaterThan(1)
            ->and($entries[$insertAt]['level'])->toBeGreaterThanOrEqual($entries[$lockAt]['level']);
    });

    it('o teto barra também no serviço (gravação), não só no formulário', function () {
        fitSchedule($this, $this->monday, ['is_fit_in' => true]);
        fitSchedule($this, $this->monday->copy()->setTime(10, 0), ['is_fit_in' => true]);

        expect(fn () => DB::transaction(fn () => app(ScheduleService::class)->assertFitInCapacity((string) $this->doctor->id, $this->monday->copy()->setTime(11, 0))))
            ->toThrow(ScheduleSlotUnavailableException::class, fitLimitMessage(2, 'morning', $this->monday));
    });
});

describe('encaixe não ocupa a grade', function () {
    it('horário só com encaixe continua livre para um agendamento normal (formulário e importação)', function () {
        fitSchedule($this, $this->monday, ['is_fit_in' => true]);

        $slots = fitAs($this)->getJson(route('panel.schedules.slots', ['doctor_id' => $this->doctor->id, 'date' => $this->monday->toDateString()]))
            ->assertOk()
            ->json();

        expect(collect($slots['slots'])->firstWhere('time', '09:00')['available'])->toBeTrue()
            ->and($slots['fit_in']['morning'])->toBe(['limit' => 2, 'used' => 1])
            ->and($slots['fit_in']['afternoon'])->toBe(['limit' => 2, 'used' => 0]);

        fitAs($this)->postJson(route('panel.schedules.store'), fitPayload($this, $this->monday, ['is_fit_in' => false]))->assertCreated();

        // Importação (sem validateSlot) cria normal: o índice só barra normal × normal.
        expect(fn () => fitSchedule($this, $this->monday->copy()->setTime(9, 30), ['is_fit_in' => true]))->not->toThrow(Exception::class)
            ->and(fn () => fitSchedule($this, $this->monday->copy()->setTime(9, 30)))->not->toThrow(Exception::class);
    });
});

it('importação: coluna "encaixe" grava o paciente a mais; sem ela, o 2º no mesmo horário é recusado', function () {
    Storage::fake();
    $this->doctor->forceFill(['import_code' => 'DOC-FIT'])->save();
    $at = $this->monday->format('d/m/Y H:i');

    $csv = "codigo_importacao_medico;nome_paciente;data_hora;situacao;encaixe\n"
        . "DOC-FIT;Paciente Grade;{$at};Agendado;não\n"
        . "DOC-FIT;Paciente Encaixe;{$at};Agendado;sim\n"
        . "DOC-FIT;Paciente Sem Encaixe;{$at};Agendado;\n";
    $path = "imports/schedules/{$this->entity->id}/fit.csv";
    Storage::disk()->put($path, "\xEF\xBB\xBF" . $csv);
    $import = ScheduleImport::create([
        'entity_id' => $this->entity->id, 'user_id' => User::factory()->create()->id, 'status' => ImportStatus::Pending,
        'file_path' => $path, 'original_name' => 'fit.csv',
    ]);

    app(ScheduleImportService::class)->process($import);
    $import->refresh();

    expect($import->imported_rows)->toBe(2)
        ->and($import->error_rows)->toBe(1)
        ->and(Schedule::where('full_name', 'PACIENTE ENCAIXE')->value('is_fit_in'))->toBeTrue()
        ->and(Schedule::where('full_name', 'PACIENTE GRADE')->value('is_fit_in'))->toBeFalse()
        ->and(Schedule::where('full_name', 'PACIENTE SEM ENCAIXE')->exists())->toBeFalse();
});

describe('editar, remarcar e lote mantêm/validam a flag', function () {
    it('editar sem o campo mantém o encaixe; mover para turno cheio recusa; virar normal em horário ocupado recusa', function () {
        $full1 = fitSchedule($this, $this->monday->copy()->setTime(14, 0), ['is_fit_in' => true]);
        fitSchedule($this, $this->monday->copy()->setTime(14, 30), ['is_fit_in' => true]);
        fitSchedule($this, $this->monday); // normal às 09:00
        $fitIn = fitSchedule($this, $this->monday->copy()->setTime(9, 0), ['is_fit_in' => true, 'full_name' => 'O ENCAIXE']);

        fitAs($this)->putJson(route('panel.schedules.update', $fitIn), [
            'doctor_id' => $this->doctor->id, 'full_name' => 'O ENCAIXE', 'date_time' => $this->monday->format('Y-m-d H:i'), 'notes' => 'nota',
        ])->assertOk();
        expect($fitIn->fresh()->is_fit_in)->toBeTrue()->and($fitIn->fresh()->notes)->toBe('nota');

        fitAs($this)->putJson(route('panel.schedules.update', $fitIn), [
            'doctor_id' => $this->doctor->id, 'full_name' => 'O ENCAIXE', 'date_time' => $this->monday->copy()->setTime(15, 0)->format('Y-m-d H:i'), 'is_fit_in' => true,
        ])->assertUnprocessable()->assertJsonPath('errors.date_time.0', fitLimitMessage(2, 'afternoon', $this->monday));

        fitAs($this)->putJson(route('panel.schedules.update', $fitIn), [
            'doctor_id' => $this->doctor->id, 'full_name' => 'O ENCAIXE', 'date_time' => $this->monday->format('Y-m-d H:i'), 'is_fit_in' => false,
        ])->assertUnprocessable()->assertJsonPath('errors.date_time.0', __('validation.custom.schedule.doctor_datetime_unique'));

        // Editar o próprio encaixe no turno cheio (mesmo horário) não conta ele mesmo.
        fitAs($this)->putJson(route('panel.schedules.update', $full1), [
            'doctor_id' => $this->doctor->id, 'full_name' => 'JÁ AGENDADO', 'date_time' => $this->monday->copy()->setTime(14, 0)->format('Y-m-d H:i'),
        ])->assertOk();

        expect($fitIn->fresh()->date_time->format('H:i'))->toBe('09:00');
    });

    it('remarcar mantém o encaixe (teto do turno de destino) e pode virar horário normal', function () {
        fitSchedule($this, $this->monday->copy()->addDay()->setTime(9, 0), ['is_fit_in' => true]);
        fitSchedule($this, $this->monday->copy()->addDay()->setTime(9, 30), ['is_fit_in' => true]);
        fitSchedule($this, $this->monday->copy()->addDay()->setTime(10, 0)); // normal
        $fitIn = fitSchedule($this, $this->monday, ['is_fit_in' => true]);

        // Terça de manhã já tem 2 encaixes.
        fitAs($this)->postJson(route('panel.schedules.reschedule', $fitIn), ['date_time' => $this->monday->copy()->addDay()->setTime(10, 0)->format('Y-m-d H:i')])
            ->assertUnprocessable()
            ->assertJsonPath('message', fitLimitMessage(2, 'morning', $this->monday->copy()->addDay()));

        // Terça à tarde, horário ocupado por um normal: cabe como encaixe.
        fitSchedule($this, $this->monday->copy()->addDay()->setTime(14, 0));
        $newId = fitAs($this)->postJson(route('panel.schedules.reschedule', $fitIn), ['date_time' => $this->monday->copy()->addDay()->setTime(14, 0)->format('Y-m-d H:i')])
            ->assertCreated()
            ->json('new_schedule.id');
        expect(Schedule::find($newId)->is_fit_in)->toBeTrue();

        // Desmarcando o encaixe, o novo horário segue a regra normal.
        $again = Schedule::find($newId);
        fitAs($this)->postJson(route('panel.schedules.reschedule', $again), ['date_time' => $this->monday->copy()->addDay()->setTime(10, 0)->format('Y-m-d H:i'), 'is_fit_in' => false])
            ->assertUnprocessable()
            ->assertJsonPath('message', __('validation.custom.schedule.doctor_datetime_unique'));
        $normalId = fitAs($this)->postJson(route('panel.schedules.reschedule', $again), ['date_time' => $this->monday->copy()->addDay()->setTime(15, 0)->format('Y-m-d H:i'), 'is_fit_in' => false])
            ->assertCreated()
            ->json('new_schedule.id');

        expect(Schedule::find($normalId)->is_fit_in)->toBeFalse();
    });

    it('remarcação em lote: encaixe que não cabe no turno de destino fica de fora com o motivo', function () {
        fitSchedule($this, $this->monday->copy()->addDay()->setTime(9, 0), ['is_fit_in' => true]);
        fitSchedule($this, $this->monday->copy()->addDay()->setTime(9, 30), ['is_fit_in' => true]);
        $fitIn  = fitSchedule($this, $this->monday->copy()->setTime(10, 0), ['is_fit_in' => true, 'full_name' => 'EM LOTE']);
        $normal = fitSchedule($this, $this->monday->copy()->setTime(11, 0), ['full_name' => 'NORMAL EM LOTE']);

        $response = fitAs($this)->postJson(route('panel.schedules.bulk-reschedule'), [
            'ids'  => [$fitIn->id, $normal->id],
            'date' => $this->monday->copy()->addDay()->toDateString(),
        ])->assertOk();

        expect($response->json('updated'))->toBe(1)
            ->and($response->json('blocked.0.id'))->toBe($fitIn->id)
            ->and($response->json('blocked.0.message'))->toContain(fitLimitMessage(2, 'morning', $this->monday->copy()->addDay()))
            ->and($fitIn->fresh()->date_time->isSameDay($this->monday))->toBeTrue()
            ->and($normal->fresh()->date_time->isSameDay($this->monday->copy()->addDay()))->toBeTrue();
    });
});

describe('quem cria e onde se configura', function () {
    it('perfil médico cria encaixe só para si', function () {
        $doctorEu  = $this->doctor->entityUser;
        $colleague = createDoctorForEntity($this->entity);

        fitAs($this, $doctorEu)->postJson(route('panel.schedules.store'), fitPayload($this, $this->monday))->assertCreated();
        fitAs($this, $doctorEu)->postJson(route('panel.schedules.store'), fitPayload($this, $this->monday, ['doctor_id' => $colleague->id]))
            ->assertUnprocessable()
            ->assertJsonPath('errors.doctor_id.0', __('schedules.doctor_only_own'));
    });

    it('escala do médico grava o teto por turno (0 a 20); médico não altera o de colega', function () {
        $admin = createEntityUser($this->entity, User::factory()->create(), ClientRule::Admin->value);
        $days  = collect(range(0, 6))->map(fn ($d) => ['day' => $d, 'active' => $d === 1, 'ranges' => [['starts_at' => '08:00', 'ends_at' => '12:00']]])->all();

        fitAs($this, $admin)->putJson(route('panel.doctors.work-schedule.sync', $this->doctor->id), [
            'days' => $days, 'fit_in_limits' => ['morning' => 0, 'afternoon' => 3, 'evening' => 1],
        ])->assertOk();

        expect($this->doctor->fresh()->fitInLimits())->toBe(['morning' => 0, 'afternoon' => 3, 'evening' => 1]);

        fitAs($this, $admin)->putJson(route('panel.doctors.work-schedule.sync', $this->doctor->id), [
            'days' => $days, 'fit_in_limits' => ['morning' => 21],
        ])->assertUnprocessable()->assertJsonValidationErrors('fit_in_limits.morning');

        // Sem o campo, mantém o que estava.
        fitAs($this, $admin)->putJson(route('panel.doctors.work-schedule.sync', $this->doctor->id), ['days' => $days])->assertOk();
        expect($this->doctor->fresh()->fitInLimits())->toBe(['morning' => 0, 'afternoon' => 3, 'evening' => 1]);

        $colleague = createDoctorForEntity($this->entity);
        fitAs($this, $colleague->entityUser)->putJson(route('panel.doctors.work-schedule.sync', $this->doctor->id), [
            'days' => $days, 'fit_in_limits' => ['morning' => 5],
        ])->assertForbidden();

        expect($this->doctor->fresh()->fitInLimit('morning'))->toBe(0);
    });

    it('tela da escala mostra o teto atual (padrão 2)', function () {
        $admin = createEntityUser($this->entity, User::factory()->create(), ClientRule::Admin->value);

        fitAs($this, $admin)->get(route('panel.doctors.work-schedule.index', $this->doctor->id))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('fitInLimits', ['morning' => 2, 'afternoon' => 2, 'evening' => 2])->where('maxFitInLimit', Doctor::MAX_FIT_IN_LIMIT));
    });
});

it('linha da agenda e detalhe trazem o selo de encaixe', function () {
    $fitIn = fitSchedule($this, $this->monday, ['is_fit_in' => true]);

    fitAs($this)->get(route('panel.schedules.index', ['date' => $this->monday->toDateString()]))
        ->assertInertia(fn ($page) => $page->where('scheduleItems.0.is_fit_in', true));

    fitAs($this)->getJson(route('panel.schedules.show', $fitIn))->assertOk()->assertJsonPath('data.is_fit_in', true);
});
