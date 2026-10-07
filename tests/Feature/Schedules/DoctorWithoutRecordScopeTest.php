<?php

declare(strict_types=1);

use App\Enums\{ClientRule, ScheduleSituation};
use App\Models\{Covenant, Doctor, DoctorWorkSchedule, Entity, Patient, People, Schedule, ScheduleBlock, ScheduleEvent, User, WaitingList};
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Médico sem cadastro de médico via dados da clínica inteira: a Agenda e os
 * Relatórios resolviam o "médico logado" pela chave de sessão `user_rule` e,
 * sem Doctor ligado ao vínculo, caíam na visão de TODOS os médicos. Agora a
 * regra é única (App\Support\CurrentDoctor, chave `selected_entity_user_rule`):
 *  - perfil médico SEM cadastro → nada de outros médicos (vazio / 403 / 422);
 *  - perfil médico COM cadastro → só o que é dele;
 *  - admin/secretária → clínica inteira, como antes.
 *
 * As sessões usam panelSession() (só `selected_entity_user_rule`, como o
 * login grava) — sem a padronização, o médico COM cadastro também via tudo.
 */
beforeEach(function () {
    $this->entity = Entity::factory()->create(['is_client' => true, 'active' => true, 'call_panel_enabled' => true]);

    $this->doctorA = createDoctorForEntity($this->entity);
    $this->doctorB = createDoctorForEntity($this->entity);
    $this->euA     = $this->doctorA->entityUser;

    $this->orphanUser = User::factory()->create();
    $this->euOrphan   = createEntityUser($this->entity, $this->orphanUser, ClientRule::Doctor->value);

    $this->adminUser = User::factory()->create();
    $this->euAdmin   = createEntityUser($this->entity, $this->adminUser, ClientRule::Admin->value);

    $this->secretaryUser = User::factory()->create();
    $this->euSecretary   = createEntityUser($this->entity, $this->secretaryUser, ClientRule::Secretary->value);

    $patient = Patient::create([
        'entity_id'   => $this->entity->id,
        'person_id'   => People::factory()->create()->id,
        'covenant_id' => Covenant::factory()->create()->id,
        'active'      => true,
    ]);

    $at = now()->setTime(10, 0);

    $this->scheduleA = Schedule::create([
        'entity_id' => $this->entity->id, 'doctor_id' => $this->doctorA->id, 'patient_id' => $patient->id,
        'full_name' => 'PACIENTE DO A', 'date_time' => $at, 'situation' => ScheduleSituation::Scheduled->value, 'active' => true,
    ]);
    $this->scheduleB = Schedule::create([
        'entity_id' => $this->entity->id, 'doctor_id' => $this->doctorB->id, 'patient_id' => $patient->id,
        'full_name' => 'PACIENTE DO B', 'date_time' => $at->copy()->addMinutes(30), 'situation' => ScheduleSituation::Scheduled->value, 'active' => true,
    ]);

    $event = fn (?string $doctorId, string $title) => ScheduleEvent::create([
        'entity_id' => $this->entity->id, 'doctor_id' => $doctorId, 'title' => $title, 'type' => 'meeting',
        'starts_at' => $at->copy()->addHours(3), 'ends_at' => $at->copy()->addHours(4), 'created_by' => $this->euAdmin->id,
    ]);
    $this->eventA      = $event($this->doctorA->id, 'REUNIÃO DO A');
    $this->eventB      = $event($this->doctorB->id, 'REUNIÃO DO B');
    $this->eventClinic = $event(null, 'REUNIÃO GERAL');

    $this->date = $at->toDateString();
});

function f0As(object $test, $entityUser): object
{
    return $test->actingAs($entityUser->user)->withSession(panelSession($entityUser));
}

/** @return list<string> nomes/títulos das linhas da Agenda do dia */
function f0AgendaRows(array $items): array
{
    return collect($items)->map(fn ($i) => $i['name'] ?? $i['title'])->sort()->values()->all();
}

describe('Agenda — lista/calendário e eventos', function () {
    it('médico sem cadastro vê a agenda vazia com o aviso (nem com ?doctor= de outro médico)', function () {
        foreach ([[], ['doctor' => $this->doctorB->id]] as $extra) {
            f0As($this, $this->euOrphan)
                ->get(route('panel.schedules.index', ['date' => $this->date, ...$extra]))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component('Panel/Schedules/Index')
                    ->where('scheduleItems', [])
                    ->where('doctors', [])
                    ->where('isDoctor', true)
                    ->where('isStaff', false)
                    ->where('doctorMissing', true));
        }
    });

    it('médico com cadastro vê só os dele (+ compromissos da clínica) e a si mesmo na lista de médicos', function () {
        f0As($this, $this->euA)
            ->get(route('panel.schedules.index', ['date' => $this->date, 'doctor' => $this->doctorB->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('doctorMissing', false)
                ->where('isDoctor', true)
                ->where('scheduleItems', fn ($items) => f0AgendaRows(collect($items)->all()) === ['PACIENTE DO A', 'REUNIÃO DO A', 'REUNIÃO GERAL'])
                ->where('doctors', fn ($doctors) => collect($doctors)->pluck('id')->all() === [$this->doctorA->id]));
    });

    it('admin e secretária continuam vendo a clínica inteira', function () {
        foreach ([$this->euAdmin, $this->euSecretary] as $eu) {
            f0As($this, $eu)
                ->get(route('panel.schedules.index', ['date' => $this->date]))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->where('doctorMissing', false)
                    ->where('isDoctor', false)
                    ->where('isStaff', true)
                    ->where('scheduleItems', fn ($items) => f0AgendaRows(collect($items)->all()) === ['PACIENTE DO A', 'PACIENTE DO B', 'REUNIÃO DO A', 'REUNIÃO DO B', 'REUNIÃO GERAL'])
                    ->where('doctors', fn ($doctors) => count($doctors) === 2));
        }
    });
});

describe('Agenda — um agendamento (ver/alterar/remarcar/chamar)', function () {
    beforeEach(function () {
        $this->calls = fn (Schedule $s) => [
            ['getJson', route('panel.schedules.show', $s), []],
            ['putJson', route('panel.schedules.update', $s), ['doctor_id' => $s->doctor_id, 'full_name' => 'X', 'date_time' => $s->date_time->format('Y-m-d H:i')]],
            ['patchJson', route('panel.schedules.situation', $s), ['situation' => ScheduleSituation::Confirmed->value]],
            ['patchJson', route('panel.schedules.mood', $s), ['mood' => null]],
            ['postJson', route('panel.schedules.reschedule', $s), ['date_time' => $s->date_time->copy()->addDay()->format('Y-m-d H:i')]],
            ['postJson', route('panel.schedules.call', $s), []],
            ['get', route('panel.schedules.attend', $s), []],
            ['deleteJson', route('panel.schedules.destroy', $s), []],
        ];
    });

    it('médico sem cadastro: 403 (ou 422 no doctor_id) em todas as rotas, nada muda', function () {
        foreach (($this->calls)($this->scheduleB) as [$method, $url, $data]) {
            $status = f0As($this, $this->euOrphan)->{$method}($url, $data)->status();
            expect($status)->toBeIn([403, 422], "{$method} {$url}");
        }

        expect($this->scheduleB->fresh())->not->toBeNull()
            ->and($this->scheduleB->fresh()->situation)->toBe(ScheduleSituation::Scheduled)
            ->and(Schedule::count())->toBe(2);
    });

    it('médico sem cadastro recebe a mensagem de vínculo ausente', function () {
        f0As($this, $this->euOrphan)
            ->getJson(route('panel.schedules.show', $this->scheduleB))
            ->assertForbidden()
            ->assertJsonPath('message', __('schedules.doctor_missing'));
    });

    it('médico com cadastro: agendamento de outro médico é 403; o dele funciona', function () {
        foreach (($this->calls)($this->scheduleB) as [$method, $url, $data]) {
            $status = f0As($this, $this->euA)->{$method}($url, $data)->status();
            expect($status)->toBeIn([403, 422], "{$method} {$url}");
        }

        expect($this->scheduleB->fresh())->not->toBeNull();

        f0As($this, $this->euA)->getJson(route('panel.schedules.show', $this->scheduleA))->assertOk();
        f0As($this, $this->euA)
            ->patchJson(route('panel.schedules.situation', $this->scheduleA), ['situation' => ScheduleSituation::Confirmed->value])
            ->assertOk();
    });

    it('secretária continua alterando agendamento de qualquer médico', function () {
        f0As($this, $this->euSecretary)->getJson(route('panel.schedules.show', $this->scheduleB))->assertOk();
        f0As($this, $this->euSecretary)
            ->patchJson(route('panel.schedules.situation', $this->scheduleB), ['situation' => ScheduleSituation::Confirmed->value])
            ->assertOk();
    });
});

describe('Agenda — criar, horários livres e lote', function () {
    it('médico sem cadastro não cria agendamento (mensagem de vínculo ausente)', function () {
        f0As($this, $this->euOrphan)
            ->postJson(route('panel.schedules.store'), [
                'doctor_id' => $this->doctorB->id,
                'full_name' => 'NOVO',
                'date_time' => now()->addDays(2)->setTime(9, 0)->format('Y-m-d H:i'),
            ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.doctor_id.0', __('schedules.doctor_missing'));

        expect(Schedule::count())->toBe(2);
    });

    it('médico com cadastro só agenda para si mesmo', function () {
        $when = now()->addDays(2)->setTime(9, 0)->format('Y-m-d H:i');

        f0As($this, $this->euA)
            ->postJson(route('panel.schedules.store'), ['doctor_id' => $this->doctorB->id, 'full_name' => 'NOVO', 'date_time' => $when])
            ->assertUnprocessable()
            ->assertJsonPath('errors.doctor_id.0', __('schedules.doctor_only_own'));

        f0As($this, $this->euA)
            ->postJson(route('panel.schedules.store'), ['doctor_id' => $this->doctorA->id, 'full_name' => 'NOVO', 'date_time' => $when])
            ->assertCreated();
    });

    it('horários livres (slots): médico sem cadastro e médico de outro não veem a agenda alheia', function () {
        DoctorWorkSchedule::create(['doctor_id' => $this->doctorB->id, 'day_of_week' => now()->addDay()->dayOfWeek, 'starts_at' => '08:00:00', 'ends_at' => '12:00:00']);
        $query = ['doctor_id' => $this->doctorB->id, 'date' => now()->addDay()->toDateString()];

        foreach ([$this->euOrphan, $this->euA] as $eu) {
            f0As($this, $eu)->getJson(route('panel.schedules.slots', $query))
                ->assertOk()
                ->assertJsonPath('slots', [])
                ->assertJsonPath('has_schedule', false);
        }

        expect(count(f0As($this, $this->euSecretary)->getJson(route('panel.schedules.slots', $query))->json('slots')))->toBeGreaterThan(0);
    });

    it('lote (situação e remarcação): médico sem cadastro 403; médico com cadastro não mexe nos de outro', function () {
        $ids = [$this->scheduleA->id, $this->scheduleB->id];

        f0As($this, $this->euOrphan)->postJson(route('panel.schedules.bulk-update'), ['ids' => $ids, 'situation' => ScheduleSituation::Confirmed->value])
            ->assertForbidden();
        f0As($this, $this->euOrphan)->postJson(route('panel.schedules.bulk-reschedule'), ['ids' => $ids, 'date' => now()->addDays(3)->toDateString()])
            ->assertForbidden();

        f0As($this, $this->euA)->postJson(route('panel.schedules.bulk-update'), ['ids' => $ids, 'situation' => ScheduleSituation::Confirmed->value])
            ->assertOk()
            ->assertJsonPath('updated', 1);

        expect($this->scheduleA->fresh()->situation)->toBe(ScheduleSituation::Confirmed)
            ->and($this->scheduleB->fresh()->situation)->toBe(ScheduleSituation::Scheduled);

        $before = $this->scheduleB->fresh()->date_time->toDateTimeString();
        f0As($this, $this->euA)->postJson(route('panel.schedules.bulk-reschedule'), ['ids' => [$this->scheduleB->id], 'date' => now()->addDays(3)->toDateString()])
            ->assertOk()
            ->assertJsonPath('updated', 0);
        expect($this->scheduleB->fresh()->date_time->toDateTimeString())->toBe($before);
    });
});

it('importação de agenda (cria agendamento de qualquer médico): médico sem cadastro recebe 403', function () {
    f0As($this, $this->euOrphan)->get(route('panel.schedules.import.index'))->assertForbidden();
    f0As($this, $this->euOrphan)->post(route('panel.schedules.import.store'), [])->assertForbidden();
    f0As($this, $this->euSecretary)->get(route('panel.schedules.import.index'))->assertOk();
});

describe('Compromissos e lista de espera', function () {
    it('médico sem cadastro não cria/edita/remove compromisso', function () {
        $payload = ['title' => 'X', 'type' => 'meeting', 'starts_at' => now()->addDay()->toDateTimeString(), 'ends_at' => now()->addDay()->addHour()->toDateTimeString()];

        f0As($this, $this->euOrphan)->postJson(route('panel.schedule-events.store'), $payload)->assertForbidden();
        f0As($this, $this->euOrphan)->putJson(route('panel.schedule-events.update', $this->eventB), $payload + ['doctor_id' => $this->doctorB->id])->assertForbidden();
        f0As($this, $this->euOrphan)->deleteJson(route('panel.schedule-events.destroy', $this->eventClinic))->assertForbidden();

        expect(ScheduleEvent::count())->toBe(3);
    });

    it('médico com cadastro não mexe em compromisso de outro médico', function () {
        $payload = ['title' => 'X', 'type' => 'meeting', 'starts_at' => now()->addDay()->toDateTimeString(), 'ends_at' => now()->addDay()->addHour()->toDateTimeString()];

        f0As($this, $this->euA)->postJson(route('panel.schedule-events.store'), $payload + ['doctor_id' => $this->doctorB->id])->assertForbidden();
        f0As($this, $this->euA)->putJson(route('panel.schedule-events.update', $this->eventB), $payload + ['doctor_id' => $this->doctorB->id])->assertForbidden();
        f0As($this, $this->euA)->deleteJson(route('panel.schedule-events.destroy', $this->eventB))->assertForbidden();

        f0As($this, $this->euA)->postJson(route('panel.schedule-events.store'), $payload + ['doctor_id' => $this->doctorA->id])->assertCreated();
        f0As($this, $this->euA)->deleteJson(route('panel.schedule-events.destroy', $this->eventA))->assertOk();
    });

    it('lista de espera: médico sem cadastro recebe vazio e não grava; médico com cadastro vê e mexe só na dele', function () {
        $entry = fn (string $doctorId, string $name) => WaitingList::create([
            'entity_id' => $this->entity->id, 'doctor_id' => $doctorId, 'full_name' => $name, 'position' => 1, 'active' => true,
        ]);
        $entry($this->doctorA->id, 'ESPERA A');
        $wlB = $entry($this->doctorB->id, 'ESPERA B');
        $wlB->update(['position' => 5]);

        f0As($this, $this->euOrphan)->getJson(route('panel.waiting-list.index'))->assertOk()->assertJsonPath('count', 0);
        f0As($this, $this->euOrphan)->postJson(route('panel.waiting-list.store'), ['doctor_id' => $this->doctorB->id, 'full_name' => 'X'])
            ->assertForbidden()
            ->assertJsonPath('message', __('schedules.doctor_missing'));
        f0As($this, $this->euOrphan)->deleteJson(route('panel.waiting-list.destroy', $wlB))->assertForbidden();

        $names = collect(f0As($this, $this->euA)->getJson(route('panel.waiting-list.index', ['doctor_id' => $this->doctorB->id]))->json('data'))
            ->pluck('full_name')->all();
        expect($names)->toBe(['ESPERA A']);

        f0As($this, $this->euA)->postJson(route('panel.waiting-list.store'), ['doctor_id' => $this->doctorB->id, 'full_name' => 'X'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.doctor_id.0', __('schedules.doctor_only_own'));
        f0As($this, $this->euA)->deleteJson(route('panel.waiting-list.destroy', $wlB))->assertForbidden();
        f0As($this, $this->euA)->patchJson(route('panel.waiting-list.reorder'), ['ids' => [$wlB->id]])->assertOk();

        expect(WaitingList::find($wlB->id)->position)->toBe(5)
            ->and(f0As($this, $this->euSecretary)->getJson(route('panel.waiting-list.index'))->json('count'))->toBe(2);
    });
});

describe('Escala e bloqueios do médico', function () {
    it('médico sem cadastro não altera escala nem bloqueios de outro médico (sessão real, sem user_rule)', function () {
        $payload = ['days' => [['day' => 1, 'active' => true, 'ranges' => [['starts_at' => '08:00', 'ends_at' => '12:00']]]]];

        f0As($this, $this->euOrphan)->putJson(route('panel.doctors.work-schedule.sync', $this->doctorB->id), $payload)->assertForbidden();
        f0As($this, $this->euOrphan)->postJson(route('panel.doctors.blocks.store', $this->doctorB->id), [
            'starts_at' => now()->addDay()->toDateTimeString(), 'ends_at' => now()->addDays(2)->toDateTimeString(), 'type' => 'absence',
        ])->assertForbidden();

        expect(DoctorWorkSchedule::where('doctor_id', $this->doctorB->id)->count())->toBe(0)
            ->and(ScheduleBlock::where('doctor_id', $this->doctorB->id)->count())->toBe(0);
    });

    it('médico com cadastro altera a própria escala; secretária altera a de qualquer um', function () {
        $payload = ['days' => [['day' => 1, 'active' => true, 'ranges' => [['starts_at' => '08:00', 'ends_at' => '12:00']]]]];

        f0As($this, $this->euA)->putJson(route('panel.doctors.work-schedule.sync', $this->doctorB->id), $payload)->assertForbidden();
        f0As($this, $this->euA)->putJson(route('panel.doctors.work-schedule.sync', $this->doctorA->id), $payload)->assertOk();
        f0As($this, $this->euSecretary)->putJson(route('panel.doctors.work-schedule.sync', $this->doctorB->id), $payload)->assertOk();
    });
});

describe('Relatórios de agenda', function () {
    it('médico (com ou sem cadastro) não abre os relatórios gerenciais', function () {
        foreach ([$this->euOrphan, $this->euA] as $eu) {
            foreach (['panel.schedules.reports.production', 'panel.schedules.reports.absenteeism'] as $name) {
                f0As($this, $eu)->get(route($name, ['date_from' => $this->date, 'date_until' => $this->date]))->assertForbidden();
            }
        }
    });

    it('sessão de perfil médico sem cadastro nunca recebe a clínica inteira (defesa em profundidade)', function () {
        // Vínculo admin no banco, mas a sessão diz "médico" (troca de perfil
        // no meio da sessão): passa no middleware/Gate e chega ao controller.
        $session = array_merge(panelSession($this->euAdmin), ['selected_entity_user_rule' => ClientRule::Doctor->value]);
        $range   = ['date_from' => $this->date, 'date_until' => $this->date];

        $this->actingAs($this->adminUser)->withSession($session)
            ->get(route('panel.schedules.reports.production', $range))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('schedules', [])->where('summary.total', 0)->where('doctorMissing', true));

        $this->scheduleB->update(['situation' => ScheduleSituation::NoShow->value]);

        $this->actingAs($this->adminUser)->withSession($session)
            ->get(route('panel.schedules.reports.absenteeism', $range))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('schedules', [])->where('summary.total_period', 0)->where('doctorMissing', true));
    });

    it('admin vê o relatório da clínica inteira', function () {
        f0As($this, $this->euAdmin)
            ->get(route('panel.schedules.reports.production', ['date_from' => $this->date, 'date_until' => $this->date]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('summary.total', 2)->where('doctorMissing', false));
    });
});
