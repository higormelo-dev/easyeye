<?php

declare(strict_types=1);

use App\Enums\{ClientRule, ScheduleSituation};
use App\Models\{Doctor, DoctorWorkSchedule, Entity, People, Schedule, ScheduleBlock, User};
use App\Services\Schedules\ScheduleAvailabilityService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Fase 1 / A3 — vagas do mês no calendário lateral da Agenda.
 *
 * Capacidade = horários da escala no intervalo do médico − bloqueios;
 * ocupados = agendamentos ativos (sem cancelados/faltas) que NÃO são encaixe;
 * "poucas vagas" = ≤ % da clínica (padrão 20%). Agregado quando "todos os
 * médicos"; médico só vê o dele. Cache curto invalidado em mudança de
 * agendamento/escala/bloqueio.
 *
 * Relógio fixo: quinta, 01/10/2026, 07:00 (antes do expediente).
 */
beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-01 07:00:00'));

    $this->entity    = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $this->secretary = createEntityUser($this->entity, User::factory()->create(), ClientRule::Secretary->value);

    // Dra. Ana: seg e qua 08:00–10:00 a cada 30 min = 4 horários por dia.
    $this->ana = savDoctor($this->entity, 'DRA. ANA', 30, [1, 3], '08:00', '10:00');
});

function savDoctor(Entity $entity, string $name, int $interval, array $days, string $from, string $to): Doctor
{
    $eu     = createEntityUser($entity, User::factory()->create(['name' => $name]), ClientRule::Doctor->value);
    $doctor = Doctor::create(['entity_user_id' => $eu->id, 'person_id' => People::factory()->create()->id, 'active' => true, 'schedule_interval' => $interval]);

    foreach ($days as $day) {
        DoctorWorkSchedule::create(['doctor_id' => $doctor->id, 'day_of_week' => $day, 'starts_at' => "{$from}:00", 'ends_at' => "{$to}:00"]);
    }

    return $doctor;
}

function savBook(Doctor $doctor, string $at, ScheduleSituation $situation = ScheduleSituation::Scheduled, array $extra = []): Schedule
{
    return Schedule::create(array_merge([
        'entity_id' => $doctor->entityUser->entity_id,
        'doctor_id' => $doctor->id,
        'full_name' => 'PACIENTE',
        'date_time' => Carbon::parse($at),
        'situation' => $situation->value,
        'active'    => true,
    ], $extra));
}

function savMonth(object $test, $entityUser, array $query = []): array
{
    return $test->actingAs($entityUser->user)
        ->withSession(panelSession($entityUser))
        ->getJson(route('panel.schedules.availability', array_merge(['month' => '2026-10'], $query)))
        ->assertOk()
        ->json();
}

describe('estado de cada dia', function () {
    it('com vagas, poucas vagas, sem vagas, sem expediente, bloqueado — com "N livres de M"', function () {
        // seg 05: 4 livres; qua 07: 1 livre (25% > 20% = com vagas); seg 12: 0 livres.
        savBook($this->ana, '2026-10-07 08:00');
        savBook($this->ana, '2026-10-07 08:30');
        savBook($this->ana, '2026-10-07 09:00');

        foreach (['08:00', '08:30', '09:00', '09:30'] as $t) {
            savBook($this->ana, "2026-10-12 {$t}");
        }
        // qua 14: bloqueio do dia todo.
        ScheduleBlock::create(['doctor_id' => $this->ana->id, 'starts_at' => '2026-10-14 00:00', 'ends_at' => '2026-10-15 00:00', 'type' => 'holiday']);

        $days = savMonth($this, $this->secretary, ['doctor' => $this->ana->id])['days'];

        expect($days['2026-10-05'])->toBe(['state' => 'available', 'free' => 4, 'capacity' => 4])
            ->and($days['2026-10-07'])->toBe(['state' => 'available', 'free' => 1, 'capacity' => 4])
            ->and($days['2026-10-12'])->toBe(['state' => 'full', 'free' => 0, 'capacity' => 4])
            ->and($days['2026-10-06']['state'])->toBe('off')
            ->and($days['2026-10-14'])->toBe(['state' => 'blocked', 'free' => 0, 'capacity' => 0]);
    });

    it('"poucas vagas" segue o percentual da clínica (padrão 20%)', function () {
        // seg 19: 1 livre de 4 = 25%.
        foreach (['08:00', '08:30', '09:00'] as $t) {
            savBook($this->ana, "2026-10-19 {$t}");
        }

        expect(savMonth($this, $this->secretary, ['doctor' => $this->ana->id])['days']['2026-10-19']['state'])->toBe('available');

        $this->entity->update(['few_slots_percent' => 25]);

        $month = savMonth($this, $this->secretary, ['doctor' => $this->ana->id]);
        expect($month['days']['2026-10-19']['state'])->toBe('few')
            ->and($month['threshold'])->toBe(25);
    });

    it('encaixe não consome vaga; cancelado e falta liberam; horário fora da grade não conta', function () {
        savBook($this->ana, '2026-10-05 08:00', ScheduleSituation::Scheduled, ['is_fit_in' => true]);
        savBook($this->ana, '2026-10-05 08:30', ScheduleSituation::Cancelled);
        savBook($this->ana, '2026-10-05 09:00', ScheduleSituation::NoShow);
        savBook($this->ana, '2026-10-05 09:10'); // fora do intervalo de 30 min
        savBook($this->ana, '2026-10-05 09:30', ScheduleSituation::Attended);

        expect(savMonth($this, $this->secretary, ['doctor' => $this->ana->id])['days']['2026-10-05'])
            ->toBe(['state' => 'available', 'free' => 3, 'capacity' => 4]);
    });

    it('bloqueio parcial tira só os horários cobertos da capacidade', function () {
        ScheduleBlock::create(['doctor_id' => $this->ana->id, 'starts_at' => '2026-10-05 09:00', 'ends_at' => '2026-10-05 10:00', 'type' => 'meeting']);
        savBook($this->ana, '2026-10-05 08:00');

        expect(savMonth($this, $this->secretary, ['doctor' => $this->ana->id])['days']['2026-10-05'])
            ->toBe(['state' => 'available', 'free' => 1, 'capacity' => 2]);
    });

    it('dias passados não têm estado; hoje conta só os horários que ainda não passaram', function () {
        $this->travelTo(Carbon::parse('2026-10-07 08:45:00')); // quarta

        $days = savMonth($this, $this->secretary, ['doctor' => $this->ana->id])['days'];

        expect($days['2026-10-05']['state'])->toBe('past')
            ->and($days['2026-10-07'])->toBe(['state' => 'available', 'free' => 2, 'capacity' => 2]);
    });

    it('sem nenhuma escala no recorte: sem marcador (a agenda não tem grade)', function () {
        $semEscala = savDoctor($this->entity, 'DR. SEM ESCALA', 15, [], '08:00', '09:00');

        expect(collect(savMonth($this, $this->secretary, ['doctor' => $semEscala->id])['days'])->pluck('state')->unique()->all())->toBe(['none']);
    });
});

describe('agregado e escopo', function () {
    it('"todos os médicos" soma capacidade e vagas; médico inativo e sem escala ficam fora', function () {
        $bruno   = savDoctor($this->entity, 'DR. BRUNO', 60, [1], '08:00', '10:00'); // seg: 2 horários
        $inativo = savDoctor($this->entity, 'DR. INATIVO', 30, [1], '08:00', '12:00');
        $inativo->update(['active' => false]);
        savDoctor($this->entity, 'DR. SEM ESCALA', 15, [], '08:00', '09:00');

        savBook($this->ana, '2026-10-05 08:00');
        savBook($bruno, '2026-10-05 08:00');
        savBook($bruno, '2026-10-05 09:00');

        $days = savMonth($this, $this->secretary)['days'];

        expect($days['2026-10-05'])->toBe(['state' => 'available', 'free' => 3, 'capacity' => 6])
            ->and($days['2026-10-07'])->toBe(['state' => 'available', 'free' => 4, 'capacity' => 4])
            ->and($days['2026-10-06']['state'])->toBe('off');
    });

    it('médico vê só as vagas dele (a URL não troca o médico); sem cadastro de médico: 403', function () {
        $bruno = savDoctor($this->entity, 'DR. BRUNO', 60, [2], '08:00', '10:00');

        $anaDays = savMonth($this, $this->ana->entityUser, ['doctor' => $bruno->id])['days'];
        expect($anaDays['2026-10-05']['capacity'])->toBe(4)
            ->and($anaDays['2026-10-06']['state'])->toBe('off');

        $missing = createEntityUser($this->entity, User::factory()->create(), ClientRule::Doctor->value);
        $this->actingAs($missing->user)->withSession(panelSession($missing))
            ->getJson(route('panel.schedules.availability', ['month' => '2026-10']))
            ->assertForbidden();
    });

    it('médico de outra clínica: 404; mês inválido: 422', function () {
        $other  = Entity::factory()->create(['is_client' => true, 'active' => true]);
        $alheio = savDoctor($other, 'DR. ALHEIO', 30, [1], '08:00', '10:00');

        $this->actingAs($this->secretary->user)->withSession(panelSession($this->secretary))
            ->getJson(route('panel.schedules.availability', ['month' => '2026-10', 'doctor' => $alheio->id]))
            ->assertNotFound();

        $this->actingAs($this->secretary->user)->withSession(panelSession($this->secretary))
            ->getJson(route('panel.schedules.availability', ['month' => 'outubro']))
            ->assertUnprocessable();
    });
});

describe('cache', function () {
    it('invalida ao agendar, cancelar, bloquear e mudar a escala', function () {
        expect(savMonth($this, $this->secretary, ['doctor' => $this->ana->id])['days']['2026-10-05']['free'])->toBe(4);

        // Agendar pela tela.
        $this->actingAs($this->secretary->user)->withSession(panelSession($this->secretary))
            ->postJson(route('panel.schedules.store'), ['doctor_id' => $this->ana->id, 'full_name' => 'NOVO', 'date_time' => '2026-10-05 08:00'])
            ->assertCreated();
        expect(savMonth($this, $this->secretary, ['doctor' => $this->ana->id])['days']['2026-10-05']['free'])->toBe(3);

        // Cancelar libera.
        $id = Schedule::where('full_name', 'NOVO')->value('id');
        $this->actingAs($this->secretary->user)->withSession(panelSession($this->secretary))
            ->patchJson(route('panel.schedules.situation', $id), ['situation' => ScheduleSituation::Cancelled->value])
            ->assertOk();
        expect(savMonth($this, $this->secretary, ['doctor' => $this->ana->id])['days']['2026-10-05']['free'])->toBe(4);

        // Bloqueio.
        $this->actingAs($this->secretary->user)->withSession(panelSession($this->secretary))
            ->postJson(route('panel.doctors.blocks.store', $this->ana->id), ['starts_at' => '2026-10-05 08:00', 'ends_at' => '2026-10-05 09:00', 'type' => 'meeting'])
            ->assertCreated();
        expect(savMonth($this, $this->secretary, ['doctor' => $this->ana->id])['days']['2026-10-05']['capacity'])->toBe(2);

        // Escala: segunda deixa de ter expediente (só exclusão em massa).
        $days = collect(range(0, 6))->map(fn ($d) => ['day' => $d, 'active' => $d === 3, 'ranges' => [['starts_at' => '08:00', 'ends_at' => '10:00']]])->all();
        $this->actingAs($this->secretary->user)->withSession(panelSession($this->secretary))
            ->putJson(route('panel.doctors.work-schedule.sync', $this->ana->id), ['schedule_interval' => 30, 'days' => $days])
            ->assertOk();
        expect(savMonth($this, $this->secretary, ['doctor' => $this->ana->id])['days']['2026-10-05']['state'])->toBe('off');
    });

    it('a resposta fica em cache enquanto nada muda (mesma versão)', function () {
        savMonth($this, $this->secretary, ['doctor' => $this->ana->id]);

        // Escrita direta no banco (sem evento de modelo) não aparece até a versão mudar.
        DB::table('schedules')->insert([
            'id'         => (string) Str::uuid(), 'entity_id' => $this->entity->id, 'doctor_id' => $this->ana->id,
            'code'       => 'SDL-9999999999', 'full_name' => 'DIRETO', 'date_time' => '2026-10-05 08:00:00',
            'situation'  => ScheduleSituation::Scheduled->value, 'active' => true, 'cellphone_whatsapp' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        expect(savMonth($this, $this->secretary, ['doctor' => $this->ana->id])['days']['2026-10-05']['free'])->toBe(4);

        ScheduleAvailabilityService::bumpVersion($this->entity->id);

        expect(savMonth($this, $this->secretary, ['doctor' => $this->ana->id])['days']['2026-10-05']['free'])->toBe(3);
    });
});

describe('Configurações › Agenda', function () {
    it('administrador ajusta o percentual de "poucas vagas" (1 a 90%); secretária não acessa', function () {
        $admin = createEntityUser($this->entity, User::factory()->create(), ClientRule::Admin->value);

        $this->actingAs($admin->user)->withSession(panelSession($admin))
            ->get(route('panel.setting.schedule.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Panel/Settings/Schedule/Index')->where('fewSlotsPercent', 20));

        $this->actingAs($admin->user)->withSession(panelSession($admin))
            ->patchJson(route('panel.setting.schedule.update'), ['few_slots_percent' => 30])
            ->assertOk()
            ->assertJsonPath('few_slots_percent', 30);

        $this->actingAs($admin->user)->withSession(panelSession($admin))
            ->patchJson(route('panel.setting.schedule.update'), ['few_slots_percent' => 95])
            ->assertUnprocessable();

        $this->actingAs($this->secretary->user)->withSession(panelSession($this->secretary))
            ->patchJson(route('panel.setting.schedule.update'), ['few_slots_percent' => 10])
            ->assertForbidden();

        expect($this->entity->fresh()->few_slots_percent)->toBe(30);
    });
});
