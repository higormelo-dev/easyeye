<?php

declare(strict_types=1);

use App\Enums\{ClientRule, MedicalSpecialty, ScheduleSituation};
use App\Models\{ClinicResource, Doctor, Entity, EntityUser, People, ResourceBlock, Schedule, ScheduleBlock, ScheduleEvent, User};
use Carbon\Carbon;
use Inertia\Testing\AssertableInertia;

/*
 * Fase 1 / A1 — contadores do dia no topo da Agenda (mesma conta do
 * Dashboard), cada um também filtro (chip), + filtros por especialidade e
 * sala/equipamento, todos no servidor e na URL. Escopo de médico sempre por
 * CurrentDoctor (médico vê só o dele).
 *
 * Relógio fixo: terça, 06/10/2026, 10:00.
 */
beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-06 10:00:00'));

    $this->entity = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $this->other  = Entity::factory()->create(['is_client' => true, 'active' => true]);

    [$this->docUserA, $this->docEuA, $this->doctorA] = acfDoctor($this->entity, 'DRA. ANA');
    [, , $this->doctorB]                             = acfDoctor($this->entity, 'DR. BRUNO');
    [, , $this->otherDoctor]                         = acfDoctor($this->other, 'DR. OUTRA');

    [$this->secUser, $this->secEu] = acfMember($this->entity, ClientRule::Secretary);

    $this->room = ClinicResource::create(['entity_id' => $this->entity->id, 'name' => 'SALA OCT', 'type' => 'equipment', 'active' => true]);
});

function acfDoctor(Entity $entity, string $name): array
{
    $user = User::factory()->create(['name' => $name]);
    $eu   = createEntityUser($entity, $user, ClientRule::Doctor->value);

    return [$user, $eu, Doctor::query()->create(['entity_user_id' => $eu->id, 'person_id' => People::factory()->create(['full_name' => $name])->id, 'active' => true])];
}

function acfMember(Entity $entity, ClientRule $rule): array
{
    $user = User::factory()->create();

    return [$user, createEntityUser($entity, $user, $rule->value)];
}

function acfSchedule(Entity $entity, Doctor $doctor, string $at, ScheduleSituation $situation, array $extra = []): Schedule
{
    return Schedule::query()->create([
        'entity_id' => $entity->id,
        'doctor_id' => $doctor->id,
        'full_name' => $extra['full_name'] ?? 'PACIENTE ' . strtoupper(substr(md5($at . $situation->value . random_int(1, 9999)), 0, 5)),
        'date_time' => Carbon::parse($at),
        'situation' => $situation->value,
        'active'    => true,
        ...array_diff_key($extra, ['full_name' => true]),
    ]);
}

/** Props da Agenda (Inertia) para o vínculo. */
function acfAgenda(object $test, User $user, EntityUser $eu, array $query = []): array
{
    $props = null;

    $test->actingAs($user)
        ->withSession(panelSession($eu))
        ->get(route('panel.schedules.index', $query))
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) use (&$props) {
            $props = $page->toArray()['props'];
        });

    return $props;
}

function acfDashboard(object $test, User $user, EntityUser $eu): array
{
    $props = null;

    $test->actingAs($user)
        ->withSession(panelSession($eu))
        ->get(route('panel.dashboard'))
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) use (&$props) {
            $props = $page->toArray()['props'];
        });

    return $props;
}

/** Ids dos agendamentos que a lista mostrou. */
function acfIds(array $props): array
{
    return collect($props['scheduleItems'])->where('type', 'schedule')->pluck('id')->sort()->values()->all();
}

/** Agenda de hoje com um pouco de tudo (Dra. Ana e Dr. Bruno). */
function acfSeedDay(object $test): void
{
    $t    = $test;
    $t->s = [
        'scheduledPast'   => acfSchedule($t->entity, $t->doctorA, '2026-10-06 08:00', ScheduleSituation::Scheduled),
        'confirmedPast'   => acfSchedule($t->entity, $t->doctorA, '2026-10-06 08:15', ScheduleSituation::Confirmed),
        'scheduledFuture' => acfSchedule($t->entity, $t->doctorA, '2026-10-06 11:00', ScheduleSituation::Scheduled),
        'confirmedFuture' => acfSchedule($t->entity, $t->doctorB, '2026-10-06 14:00', ScheduleSituation::Confirmed),
        'waiting'         => acfSchedule($t->entity, $t->doctorA, '2026-10-06 09:00', ScheduleSituation::Waiting, ['arrived_at' => '2026-10-06 08:50']),
        'dilating'        => acfSchedule($t->entity, $t->doctorB, '2026-10-06 09:15', ScheduleSituation::Dilating, ['arrived_at' => '2026-10-06 09:00']),
        'inCare'          => acfSchedule($t->entity, $t->doctorA, '2026-10-06 09:30', ScheduleSituation::InProgress, ['arrived_at' => '2026-10-06 09:20']),
        'attended'        => acfSchedule($t->entity, $t->doctorA, '2026-10-06 07:30', ScheduleSituation::Attended, ['arrived_at' => '2026-10-06 07:20']),
        'noShow'          => acfSchedule($t->entity, $t->doctorB, '2026-10-06 07:45', ScheduleSituation::NoShow),
        'cancelled'       => acfSchedule($t->entity, $t->doctorB, '2026-10-06 15:00', ScheduleSituation::Cancelled),
        'fitIn'           => acfSchedule($t->entity, $t->doctorA, '2026-10-06 11:00', ScheduleSituation::Scheduled, ['is_fit_in' => true]),
        'fitInCancelled'  => acfSchedule($t->entity, $t->doctorA, '2026-10-06 11:30', ScheduleSituation::Cancelled, ['is_fit_in' => true]),
        // Outro dia e outra clínica: nunca entram.
        'tomorrow'    => acfSchedule($t->entity, $t->doctorA, '2026-10-07 09:00', ScheduleSituation::Scheduled),
        'otherClinic' => acfSchedule($t->other, $t->otherDoctor, '2026-10-06 09:00', ScheduleSituation::Waiting, ['arrived_at' => '2026-10-06 08:55']),
    ];
}

describe('contadores = Dashboard', function () {
    it('secretária: os números da Agenda batem com o resumo e a recepção do Dashboard', function () {
        acfSeedDay($this);

        $counters  = acfAgenda($this, $this->secUser, $this->secEu)['counters'];
        $dashboard = acfDashboard($this, $this->secUser, $this->secEu);
        $stats     = $dashboard['stats'];
        $today     = $dashboard['reception']['days']['today'];

        expect($counters)->toMatchArray([
            'total'       => 12,
            'confirmed'   => 6,  // confirmados + chegaram/atendidos
            'unconfirmed' => 3,  // agendados sem confirmação (inclui o encaixe agendado)
            'not_arrived' => 2,  // 08:00 agendado e 08:15 confirmado, sem chegada
            'waiting'     => 2,  // aguardando + dilatando
            'in_care'     => 1,
            'attended'    => 1,
            'no_show'     => 1,
            'cancelled'   => 2,
            'fit_in'      => 1,  // o encaixe cancelado não conta
            'blocked'     => 0,
        ]);

        expect($counters['total'])->toBe($stats['today_count'])
            ->and($counters['attended'])->toBe($stats['attended_today'])
            ->and($counters['no_show'] + $counters['cancelled'])->toBe($stats['cancelled_today'])
            ->and($counters['waiting'])->toBe($stats['waiting_now'])
            ->and($counters['waiting'])->toBe($dashboard['reception']['waiting_room']['count'])
            ->and($counters['in_care'])->toBe($dashboard['reception']['waiting_room']['in_care'])
            ->and($counters['confirmed'])->toBe($today['confirmed'])
            ->and($counters['unconfirmed'])->toBe($today['unconfirmed']);
    });

    it('médico: só os dele, iguais ao "Meu dia" do Dashboard (a URL não troca o médico)', function () {
        acfSeedDay($this);

        $counters = acfAgenda($this, $this->docUserA, $this->docEuA, ['doctor' => $this->doctorB->id])['counters'];
        $stats    = acfDashboard($this, $this->docUserA, $this->docEuA)['stats'];

        expect($counters['total'])->toBe(8)
            ->and($counters['total'])->toBe($stats['today_count'])
            ->and($counters['attended'])->toBe($stats['attended_today'])
            ->and($counters['no_show'] + $counters['cancelled'])->toBe($stats['cancelled_today'])
            ->and($counters['waiting'])->toBe($stats['waiting_now'])
            ->and($counters['waiting'])->toBe(1);
    });

    it('cada chip mostra exatamente as linhas que ele conta', function () {
        acfSeedDay($this);

        $counters = acfAgenda($this, $this->secUser, $this->secEu)['counters'];

        foreach (['confirmed', 'unconfirmed', 'not_arrived', 'waiting', 'in_care', 'attended', 'no_show', 'cancelled', 'fit_in'] as $key) {
            $props = acfAgenda($this, $this->secUser, $this->secEu, ['status' => $key]);

            expect(count(acfIds($props)))->toBe($counters[$key], "chip {$key}")
                ->and($props['filters']['status'])->toBe($key)
                // Os contadores não mudam com o chip escolhido.
                ->and($props['counters'])->toBe($counters);
        }

        expect(acfIds(acfAgenda($this, $this->secUser, $this->secEu, ['status' => 'not_arrived'])))
            ->toBe(collect([$this->s['scheduledPast']->id, $this->s['confirmedPast']->id])->sort()->values()->all())
            ->and(acfIds(acfAgenda($this, $this->secUser, $this->secEu, ['status' => 'fit_in'])))->toBe([$this->s['fitIn']->id]);
    });

    it('"não chegaram" acompanha o relógio: o horário que passa entra na conta', function () {
        acfSchedule($this->entity, $this->doctorA, '2026-10-06 10:30', ScheduleSituation::Confirmed);

        expect(acfAgenda($this, $this->secUser, $this->secEu)['counters']['not_arrived'])->toBe(0);

        $this->travelTo(Carbon::parse('2026-10-06 10:45:00'));

        expect(acfAgenda($this, $this->secUser, $this->secEu)['counters']['not_arrived'])->toBe(1);
    });

    it('turno e médico escolhidos recortam os contadores; a busca e outra clínica não', function () {
        acfSeedDay($this);

        $morningA = acfAgenda($this, $this->secUser, $this->secEu, ['doctor' => $this->doctorA->id, 'bout' => 2]);
        $searched = acfAgenda($this, $this->secUser, $this->secEu, ['search' => 'NADA-QUE-EXISTA']);

        expect($morningA['counters']['total'])->toBe(8)
            ->and($morningA['filters'])->toMatchArray(['doctor' => $this->doctorA->id, 'bout' => 2])
            ->and(acfIds($searched))->toBe([])
            ->and($searched['counters']['total'])->toBe(12);
    });
});

describe('filtros de especialidade e sala', function () {
    it('especialidade e sala filtram a lista E os contadores, e voltam na URL', function () {
        $glaucoma = acfSchedule($this->entity, $this->doctorA, '2026-10-06 13:00', ScheduleSituation::Scheduled, ['specialty_area' => MedicalSpecialty::Glaucoma->value]);
        $retina   = acfSchedule($this->entity, $this->doctorA, '2026-10-06 13:15', ScheduleSituation::Confirmed, ['specialty_area' => MedicalSpecialty::RetinaVitreous->value]);
        $retina->resources()->sync([$this->room->id]);
        acfSchedule($this->entity, $this->doctorB, '2026-10-06 13:30', ScheduleSituation::Scheduled);

        $bySpecialty = acfAgenda($this, $this->secUser, $this->secEu, ['specialty' => MedicalSpecialty::Glaucoma->value]);
        $byRoom      = acfAgenda($this, $this->secUser, $this->secEu, ['resource' => $this->room->id]);

        expect(acfIds($bySpecialty))->toBe([$glaucoma->id])
            ->and($bySpecialty['counters']['total'])->toBe(1)
            ->and($bySpecialty['filters']['specialty'])->toBe(MedicalSpecialty::Glaucoma->value)
            ->and(acfIds($byRoom))->toBe([$retina->id])
            ->and($byRoom['counters'])->toMatchArray(['total' => 1, 'confirmed' => 1])
            ->and($byRoom['filters']['resource'])->toBe($this->room->id)
            ->and(collect($byRoom['resourceOptions'])->pluck('id')->all())->toBe([$this->room->id]);
    });

    it('sala de outra clínica (ou inexistente) na URL é ignorada', function () {
        $foreignRoom = ClinicResource::create(['entity_id' => $this->other->id, 'name' => 'SALA ALHEIA', 'type' => 'room', 'active' => true]);
        acfSchedule($this->entity, $this->doctorA, '2026-10-06 13:00', ScheduleSituation::Scheduled);

        $props = acfAgenda($this, $this->secUser, $this->secEu, ['resource' => $foreignRoom->id, 'specialty' => 999]);

        expect($props['filters']['resource'])->toBeNull()
            ->and($props['filters']['specialty'])->toBeNull()
            ->and($props['counters']['total'])->toBe(1);
    });
});

describe('chip "Bloqueados"', function () {
    it('mostra bloqueios do médico, de sala e compromissos do dia — e só eles', function () {
        acfSchedule($this->entity, $this->doctorA, '2026-10-06 13:00', ScheduleSituation::Scheduled);
        ScheduleBlock::create(['doctor_id' => $this->doctorA->id, 'starts_at' => '2026-10-06 14:00', 'ends_at' => '2026-10-06 16:00', 'type' => 'meeting', 'reason' => 'CONGRESSO']);
        ScheduleBlock::create(['doctor_id' => $this->otherDoctor->id, 'starts_at' => '2026-10-06 14:00', 'ends_at' => '2026-10-06 16:00', 'type' => 'absence']);
        ResourceBlock::create(['resource_id' => $this->room->id, 'starts_at' => '2026-10-06 08:00', 'ends_at' => '2026-10-06 09:00', 'type' => 'other', 'reason' => 'MANUTENÇÃO DO OCT']);
        ScheduleEvent::create(['entity_id' => $this->entity->id, 'title' => 'REUNIÃO DE EQUIPE', 'type' => 'meeting', 'starts_at' => '2026-10-06 12:00', 'ends_at' => '2026-10-06 13:00', 'created_by' => $this->secEu->id]);
        ScheduleEvent::create(['entity_id' => $this->other->id, 'title' => 'ALHEIO', 'type' => 'meeting', 'starts_at' => '2026-10-06 12:00', 'ends_at' => '2026-10-06 13:00', 'created_by' => $this->secEu->id]);

        $props = acfAgenda($this, $this->secUser, $this->secEu, ['status' => 'blocked']);
        $rows  = collect($props['scheduleItems']);

        expect($props['counters']['blocked'])->toBe(3)
            ->and($rows->where('type', 'schedule'))->toHaveCount(0)
            ->and($rows->pluck('title')->sort()->values()->all())->toBe(['CONGRESSO', 'MANUTENÇÃO DO OCT', 'REUNIÃO DE EQUIPE'])
            ->and($rows->firstWhere('title', 'CONGRESSO')['doctor_name'])->toBe('DRA. ANA')
            ->and($rows->firstWhere('title', 'MANUTENÇÃO DO OCT')['resource_name'])->toBe('SALA OCT');

        // Médico: os bloqueios dele (o do colega não), sala e compromissos da clínica.
        ScheduleBlock::create(['doctor_id' => $this->doctorB->id, 'starts_at' => '2026-10-06 08:00', 'ends_at' => '2026-10-06 18:00', 'type' => 'absence', 'reason' => 'FÉRIAS DO BRUNO']);
        $doctorRows = collect(acfAgenda($this, $this->docUserA, $this->docEuA, ['status' => 'blocked'])['scheduleItems']);

        expect($doctorRows->pluck('title')->all())->not->toContain('FÉRIAS DO BRUNO')
            ->and($doctorRows->pluck('title')->all())->toContain('CONGRESSO');
    });

    it('turno filtra os bloqueios pelo horário que cobrem', function () {
        ScheduleBlock::create(['doctor_id' => $this->doctorA->id, 'starts_at' => '2026-10-06 14:00', 'ends_at' => '2026-10-06 16:00', 'type' => 'meeting', 'reason' => 'TARDE']);

        expect(acfAgenda($this, $this->secUser, $this->secEu, ['status' => 'blocked', 'bout' => 2])['counters']['blocked'])->toBe(0)
            ->and(acfAgenda($this, $this->secUser, $this->secEu, ['status' => 'blocked', 'bout' => 3])['counters']['blocked'])->toBe(1);
    });
});

it('médico sem cadastro de médico: contadores zerados e nenhuma linha', function () {
    acfSeedDay($this);
    [$user, $eu] = acfMember($this->entity, ClientRule::Doctor);

    $props = acfAgenda($this, $user, $eu);

    expect($props['doctorMissing'])->toBeTrue()
        ->and($props['scheduleItems'])->toBe([])
        ->and(array_sum($props['counters']))->toBe(0);
});
