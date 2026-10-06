<?php

declare(strict_types=1);

use App\Domains\Tiss\Enums\TissGlosaStatus;
use App\Domains\Tiss\Models\{TissGlosa, TissOperator};
use App\Enums\{BillingClaimStatus, ClientRule, ScheduleSituation};
use App\Http\Controllers\PanelDashboardController;
use App\Models\{BillingClaim, Covenant, Doctor, Entity, EntityUser, FinancialCashEntry, Patient, People, Schedule, User, VisitType, WaitingList};
use App\Models\WhatsApp\WhatsAppMessage;
use App\Services\Dashboard\{ClinicOperationsService, DashboardInsightsService};
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

/**
 * Dashboard v2 — "painel por função" (PanelDashboardController +
 * App\Services\Dashboard\*): cada perfil recebe só os blocos do posto de
 * trabalho dele, decididos no servidor:
 *  - comparativo do mês × MESMO intervalo do mês anterior;
 *  - recepção: confirmações de hoje/amanhã, sala de espera, lista de espera,
 *    aniversariantes (telefone só para quem abre Pacientes);
 *  - financeiro só com o Gate ViewFinancial, sem nenhum dado de paciente;
 *  - isolamento entre clínicas e entre médicos;
 *  - número de consultas por perfil (o painel faz polling a cada 30 s).
 *
 * Relógio fixo: terça, 06/10/2026, 10:00.
 */
beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-06 10:00:00'));

    $this->entity = Entity::factory()->create(['name' => 'Clínica Visão Clara', 'is_client' => true, 'active' => true]);
    $this->other  = Entity::factory()->create(['name' => 'Outra Clínica', 'is_client' => true, 'active' => true]);

    [$this->docUserA, $this->docEuA, $this->doctorA] = dv2Doctor($this->entity, 'DRA. ANA ALVES');
    [$this->docUserB, $this->docEuB, $this->doctorB] = dv2Doctor($this->entity, 'DR. BRUNO BRAGA');
    [, , $this->otherDoctor]                         = dv2Doctor($this->other, 'DR. OSCAR OUTRA');

    $this->pAlice  = dv2Patient($this->entity, 'ALICE AMARAL', '11987650001');
    $this->pBruno  = dv2Patient($this->entity, 'BRUNO BATISTA', '11987650002');
    $this->pCarla  = dv2Patient($this->entity, 'CARLA COSTA', '11987650003');
    $this->pDaniel = dv2Patient($this->entity, 'DANIEL DIAS', '11987650004');
    $this->pOther  = dv2Patient($this->other, 'OLGA OUTRACLINICA', '21999990000');
});

function dv2Doctor(Entity $entity, string $name): array
{
    $user       = User::factory()->create(['name' => $name]);
    $entityUser = createEntityUser($entity, $user, ClientRule::Doctor->value);
    $doctor     = Doctor::query()->create([
        'entity_user_id' => $entityUser->id,
        'person_id'      => People::factory()->create(['full_name' => $name])->id,
        'active'         => true,
    ]);

    return [$user, $entityUser, $doctor];
}

/** Paciente com aniversário fora de hoje (o factory sortearia a data). */
function dv2Patient(Entity $entity, string $name, string $cellphone, string $birthDate = '1980-01-15', bool $active = true): Patient
{
    return Patient::factory()->create([
        'entity_id' => $entity->id,
        'active'    => $active,
        'person_id' => People::factory()->create([
            'full_name'  => $name,
            'cellphone'  => $cellphone,
            'telephone'  => null,
            'birth_date' => $birthDate,
        ])->id,
    ]);
}

function dv2Schedule(Entity $entity, Doctor $doctor, ?Patient $patient, string $dateTime, ScheduleSituation $situation, array $extra = []): Schedule
{
    return Schedule::query()->create([
        'entity_id'  => $entity->id,
        'doctor_id'  => $doctor->id,
        'patient_id' => $patient?->id,
        'full_name'  => $patient?->person?->full_name ?? 'SEM CADASTRO',
        'date_time'  => Carbon::parse($dateTime),
        'situation'  => $situation->value,
        'active'     => true,
        ...$extra,
    ]);
}

function dv2Member(Entity $entity, ClientRule $rule): array
{
    $user = User::factory()->create(['name' => 'Membro ' . $rule->value]);

    return [$user, createEntityUser($entity, $user, $rule->value)];
}

function dv2Props($test, User $user, EntityUser $entityUser, array $headers = []): array
{
    $props = null;

    $test->actingAs($user)
        ->withSession(panelSession($entityUser))
        ->withHeaders($headers)
        ->get(route('panel.dashboard'))
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) use (&$props) {
            $props = $page->toArray()['props'];
        });

    return $props;
}

/** Só os blocos de dados do Dashboard (sem o compartilhado do layout). */
function dv2DataJson(array $props): string
{
    return json_encode(array_intersect_key($props, array_flip([
        'stats', 'scheduleToday', 'nextPatient', 'recentPatients', 'aiWaiting', 'unsignedRecords',
        'reception', 'waitlist', 'birthdays', 'doctorsToday', 'cashToday', 'insights',
    ])), JSON_UNESCAPED_UNICODE);
}

function dv2Confirmation(Schedule $schedule, string $status): WhatsAppMessage
{
    return WhatsAppMessage::query()->create([
        'entity_id'   => $schedule->entity_id,
        'schedule_id' => $schedule->id,
        'direction'   => 'out',
        'kind'        => WhatsAppMessage::KIND_CONFIRMATION,
        'phone'       => '5511987650000',
        'body'        => 'Confirma sua consulta?',
        'status'      => $status,
    ]);
}

function dv2CashEntry(Entity $entity, array $attributes): FinancialCashEntry
{
    return FinancialCashEntry::query()->create([
        'entity_id'   => $entity->id,
        'entry_date'  => now()->toDateString(),
        'description' => 'Lançamento',
        'type'        => 'income',
        'status'      => 'paid',
        'amount'      => 100,
        'active'      => true,
        ...$attributes,
    ]);
}

describe('período de comparação', function () {
    it('mês atual até hoje × o mesmo intervalo de dias do mês anterior (com o fim do mês curto)', function (string $today, array $expected) {
        expect(DashboardInsightsService::periods(Carbon::parse($today)))->toBe($expected);
    })->with([
        'dia 6'                => ['2026-10-06', ['current' => ['from' => '2026-10-01', 'to' => '2026-10-06'], 'previous' => ['from' => '2026-09-01', 'to' => '2026-09-06']]],
        'dia 1'                => ['2026-10-01', ['current' => ['from' => '2026-10-01', 'to' => '2026-10-01'], 'previous' => ['from' => '2026-09-01', 'to' => '2026-09-01']]],
        '31/10 → até 30/09'    => ['2026-10-31', ['current' => ['from' => '2026-10-01', 'to' => '2026-10-31'], 'previous' => ['from' => '2026-09-01', 'to' => '2026-09-30']]],
        '31/03 → até 28/02'    => ['2026-03-31', ['current' => ['from' => '2026-03-01', 'to' => '2026-03-31'], 'previous' => ['from' => '2026-02-01', 'to' => '2026-02-28']]],
        'bissexto → até 29/02' => ['2028-03-30', ['current' => ['from' => '2028-03-01', 'to' => '2028-03-30'], 'previous' => ['from' => '2028-02-01', 'to' => '2028-02-29']]],
        'janeiro → dezembro'   => ['2027-01-15', ['current' => ['from' => '2027-01-01', 'to' => '2027-01-15'], 'previous' => ['from' => '2026-12-01', 'to' => '2026-12-15']]],
    ]);
});

describe('administrador — gestão', function () {
    it('indicadores do mês nos dois intervalos iguais, com receita e a receber; nada de outra clínica', function () {
        // Outubro (1–6): 3 atendidos, 1 falta, 1 cancelado.
        dv2Schedule($this->entity, $this->doctorA, $this->pAlice, '2026-10-01 09:00', ScheduleSituation::Attended);
        dv2Schedule($this->entity, $this->doctorA, $this->pBruno, '2026-10-02 09:00', ScheduleSituation::Attended);
        dv2Schedule($this->entity, $this->doctorB, $this->pCarla, '2026-10-05 09:00', ScheduleSituation::Attended);
        dv2Schedule($this->entity, $this->doctorB, $this->pDaniel, '2026-10-05 10:00', ScheduleSituation::NoShow);
        dv2Schedule($this->entity, $this->doctorB, $this->pDaniel, '2026-10-05 11:00', ScheduleSituation::Cancelled);
        // Setembro (1–6): 1 atendido, 1 falta; 10/09 fica FORA do intervalo comparável.
        dv2Schedule($this->entity, $this->doctorA, $this->pAlice, '2026-09-03 09:00', ScheduleSituation::Attended);
        dv2Schedule($this->entity, $this->doctorA, $this->pBruno, '2026-09-04 09:00', ScheduleSituation::NoShow);
        dv2Schedule($this->entity, $this->doctorA, $this->pCarla, '2026-09-10 09:00', ScheduleSituation::Attended);
        // Outra clínica, mesmo período.
        dv2Schedule($this->other, $this->otherDoctor, $this->pOther, '2026-10-02 09:00', ScheduleSituation::Attended);

        dv2CashEntry($this->entity, ['entry_date' => '2026-10-03', 'amount' => 500]);
        dv2CashEntry($this->entity, ['entry_date' => '2026-09-02', 'amount' => 200]);
        dv2CashEntry($this->entity, ['entry_date' => '2026-09-20', 'amount' => 9000]); // fora do intervalo
        dv2CashEntry($this->entity, ['entry_date' => '2026-10-20', 'amount' => 300, 'status' => 'pending']); // a receber
        dv2CashEntry($this->entity, ['entry_date' => '2026-10-01', 'amount' => 120, 'status' => 'pending']); // vencido
        dv2CashEntry($this->other, ['entry_date' => '2026-10-03', 'amount' => 7777]);

        [$admin, $eu] = dv2Member($this->entity, ClientRule::Admin);
        $insights     = dv2Props($this, $admin, $eu)['insights'];

        expect($insights['period'])->toBe([
            'current'  => ['from' => '2026-10-01', 'to' => '2026-10-06'],
            'previous' => ['from' => '2026-09-01', 'to' => '2026-09-06'],
        ])->and($insights['finance'])->toBeTrue();

        expect($insights['month']['current'])->toMatchArray([
            'attended'        => 3,
            'noshow'          => 1,
            'cancelled'       => 1,
            'total'           => 5,
            'attendance_rate' => 75.0,
            'noshow_rate'     => 25.0,
            'occupancy_rate'  => 75.0,
            'income'          => 500.0,
        ])->and($insights['month']['previous'])->toMatchArray([
            'attended'        => 1,
            'noshow'          => 1,
            'attendance_rate' => 50.0,
            'noshow_rate'     => 50.0,
            'income'          => 200.0,
        ]);

        expect($insights['receivables']['cash'])->toMatchArray([
            'upcoming' => 300.0, 'upcoming_count' => 1, 'overdue' => 120.0, 'overdue_count' => 1,
        ])->and($insights['receivables']['total'])->toEqual(420.0);

        // Série diária: 30 dias, hoje no fim, sem a outra clínica.
        $days = collect($insights['daily']['days']);
        expect($days)->toHaveCount(30)
            ->and($days->last()['date'])->toBe('2026-10-06')
            ->and($days->firstWhere('date', '2026-10-05'))->toMatchArray(['attended' => 1, 'noshow' => 1, 'cancelled' => 1, 'total' => 3])
            ->and($days->firstWhere('date', '2026-10-02')['attended'])->toBe(1)
            ->and($insights['trend']['series'])->toHaveCount(6);
    });

    it('atendimentos por médico hoje: agregado por médico, só desta clínica, sem pacientes', function () {
        dv2Schedule($this->entity, $this->doctorA, $this->pAlice, '2026-10-06 08:00', ScheduleSituation::Attended);
        dv2Schedule($this->entity, $this->doctorA, $this->pBruno, '2026-10-06 08:30', ScheduleSituation::Waiting, ['arrived_at' => '2026-10-06 08:20']);
        dv2Schedule($this->entity, $this->doctorA, $this->pCarla, '2026-10-06 09:00', ScheduleSituation::NoShow);
        dv2Schedule($this->entity, $this->doctorB, $this->pDaniel, '2026-10-06 14:00', ScheduleSituation::Scheduled);
        dv2Schedule($this->other, $this->otherDoctor, $this->pOther, '2026-10-06 08:00', ScheduleSituation::Attended);

        [$admin, $eu] = dv2Member($this->entity, ClientRule::Admin);
        $doctors      = dv2Props($this, $admin, $eu)['doctorsToday'];

        expect($doctors['items'])->toHaveCount(2)
            ->and($doctors['items'][0])->toMatchArray([
                'name' => 'DRA. ANA ALVES', 'total' => 3, 'attended' => 1, 'missed' => 1, 'waiting' => 1, 'expected' => 2,
            ])
            ->and($doctors['items'][1])->toMatchArray(['name' => 'DR. BRUNO BRAGA', 'total' => 1, 'attended' => 0])
            ->and(json_encode($doctors))->not->toContain('ALICE')->not->toContain('OSCAR');
    });

    it('agenda de hoje traz tipo de consulta, convênio e hora de chegada para a linha de apoio', function () {
        $visit    = VisitType::query()->create(['entity_id' => $this->entity->id, 'name' => 'RETORNO', 'active' => true]);
        $covenant = Covenant::factory()->create(['entity_id' => $this->entity->id, 'name' => 'UNIMED TESTE']);

        dv2Schedule($this->entity, $this->doctorA, $this->pAlice, '2026-10-06 09:30', ScheduleSituation::Waiting, [
            'visit_id' => $visit->id, 'covenant_id' => $covenant->id, 'arrived_at' => '2026-10-06 09:12',
        ]);

        [$admin, $eu] = dv2Member($this->entity, ClientRule::Admin);
        $row          = dv2Props($this, $admin, $eu)['scheduleToday'][0];

        expect($row)->toMatchArray(['visit' => 'RETORNO', 'covenant' => 'UNIMED TESTE', 'arrived_time' => '09:12']);
    });

    it('"Atualizar" (header) recalcula os números de gestão; sem ele, vale o cache curto', function () {
        [$admin, $eu] = dv2Member($this->entity, ClientRule::Admin);

        expect(dv2Props($this, $admin, $eu)['insights']['month']['current']['attended'])->toBe(0);

        dv2Schedule($this->entity, $this->doctorA, $this->pAlice, '2026-10-02 09:00', ScheduleSituation::Attended);

        expect(dv2Props($this, $admin, $eu)['insights']['month']['current']['attended'])->toBe(0)
            ->and(dv2Props($this, $admin, $eu, [PanelDashboardController::REFRESH_HEADER => '1'])['insights']['month']['current']['attended'])->toBe(1);
    });

    it('polling (partial reload das props de operação) não recalcula os números de gestão', function () {
        [$admin, $eu] = dv2Member($this->entity, ClientRule::Admin);

        $response = $this->actingAs($admin)
            ->withSession(panelSession($eu))
            ->get(route('panel.dashboard'), [
                ...inertiaHeaders(),
                'X-Inertia-Partial-Component' => 'Panel/Dashboard',
                'X-Inertia-Partial-Data'      => 'stats,scheduleToday,doctorsToday',
            ])
            ->assertOk();

        expect(array_keys($response->json('props')))->not->toContain('insights')
            ->and($response->json('props.doctorsToday'))->toBeArray();
    });
    it('ocupação conta só o que já aconteceu: as consultas de hoje que ainda vão acontecer não derrubam o mês', function () {
        // Hoje às 10:00: uma atendida às 08:00 e três marcadas para a tarde.
        dv2Schedule($this->entity, $this->doctorA, $this->pAlice, '2026-10-06 08:00', ScheduleSituation::Attended);
        dv2Schedule($this->entity, $this->doctorA, $this->pBruno, '2026-10-06 14:00', ScheduleSituation::Scheduled);
        dv2Schedule($this->entity, $this->doctorA, $this->pCarla, '2026-10-06 15:00', ScheduleSituation::Confirmed);
        dv2Schedule($this->entity, $this->doctorB, $this->pDaniel, '2026-10-06 16:00', ScheduleSituation::Scheduled);

        [$admin, $eu] = dv2Member($this->entity, ClientRule::Admin);
        $current      = dv2Props($this, $admin, $eu)['insights']['month']['current'];

        expect($current['occupancy_rate'])->toEqual(100.0)
            ->and($current['total'])->toBe(4)
            ->and($current['noshow_rate'])->toEqual(0.0);
    });
});

describe('médico — meu mês', function () {
    it('atendimentos e taxa de falta DELE nos dois intervalos; nada de outro médico nem de outra clínica', function () {
        dv2Schedule($this->entity, $this->doctorA, $this->pAlice, '2026-10-01 09:00', ScheduleSituation::Attended);
        dv2Schedule($this->entity, $this->doctorA, $this->pBruno, '2026-10-02 09:00', ScheduleSituation::Attended);
        dv2Schedule($this->entity, $this->doctorA, $this->pCarla, '2026-10-03 09:00', ScheduleSituation::NoShow);
        dv2Schedule($this->entity, $this->doctorA, $this->pAlice, '2026-09-02 09:00', ScheduleSituation::Attended);
        dv2Schedule($this->entity, $this->doctorA, $this->pAlice, '2026-09-25 09:00', ScheduleSituation::Attended); // fora
        dv2Schedule($this->entity, $this->doctorB, $this->pDaniel, '2026-10-02 10:00', ScheduleSituation::Attended);

        $insights = dv2Props($this, $this->docUserA, $this->docEuA)['insights'];

        expect($insights['finance'])->toBeFalse()
            ->and($insights['month']['current'])->toMatchArray(['attended' => 2, 'noshow' => 1, 'noshow_rate' => 33.3])
            ->and($insights['month']['previous'])->toMatchArray(['attended' => 1, 'noshow' => 0, 'noshow_rate' => 0.0])
            ->and($insights)->not->toHaveKeys(['receivables', 'trend', 'daily', 'glosas']);

        $insightsB = dv2Props($this, $this->docUserB, $this->docEuB)['insights'];
        expect($insightsB['month']['current']['attended'])->toBe(1);
    });

    it('médico sem cadastro de médico: sem números de gestão', function () {
        [$user, $eu] = dv2Member($this->entity, ClientRule::Doctor);

        expect(dv2Props($this, $user, $eu)['insights'])->toBeNull();
    });
});

describe('secretária — recepção', function () {
    it('confirmações de hoje e amanhã: confirmadas × sem confirmação, WhatsApp e quem ligar (com telefone)', function () {
        // Hoje (10:00): 08:00 já passou sem confirmar; 11:00 sem confirmar (WhatsApp falhou);
        // 14:00 confirmada pelo WhatsApp; 15:00 chegou; 16:00 cancelada (fora da conta).
        dv2Schedule($this->entity, $this->doctorA, $this->pAlice, '2026-10-06 08:00', ScheduleSituation::Scheduled);
        $failed = dv2Schedule($this->entity, $this->doctorA, $this->pBruno, '2026-10-06 11:00', ScheduleSituation::Scheduled);
        $byWa   = dv2Schedule($this->entity, $this->doctorA, $this->pCarla, '2026-10-06 14:00', ScheduleSituation::Confirmed);
        dv2Schedule($this->entity, $this->doctorB, $this->pDaniel, '2026-10-06 15:00', ScheduleSituation::Waiting, ['arrived_at' => '2026-10-06 09:40']);
        dv2Schedule($this->entity, $this->doctorB, $this->pAlice, '2026-10-06 16:00', ScheduleSituation::Cancelled);
        dv2Confirmation($failed, WhatsAppMessage::STATUS_FAILED);
        dv2Confirmation($byWa, WhatsAppMessage::STATUS_ANSWERED);

        // Amanhã: 09:00 sem confirmar (aguardando resposta), 19:00 confirmada.
        $awaiting = dv2Schedule($this->entity, $this->doctorA, $this->pDaniel, '2026-10-07 09:00', ScheduleSituation::Scheduled);
        dv2Schedule($this->entity, $this->doctorB, $this->pCarla, '2026-10-07 19:00', ScheduleSituation::Confirmed);
        dv2Confirmation($awaiting, WhatsAppMessage::STATUS_SENT);
        // Depois de amanhã e outra clínica: fora.
        dv2Schedule($this->entity, $this->doctorA, $this->pAlice, '2026-10-08 09:00', ScheduleSituation::Scheduled);
        dv2Schedule($this->other, $this->otherDoctor, $this->pOther, '2026-10-06 11:00', ScheduleSituation::Scheduled);

        [$user, $eu] = dv2Member($this->entity, ClientRule::Secretary);
        $reception   = dv2Props($this, $user, $eu)['reception'];

        $today = $reception['days']['today'];
        expect($today)->toMatchArray([
            'date' => '2026-10-06', 'total' => 4, 'confirmed' => 2, 'unconfirmed' => 2, 'whatsapp_confirmed' => 1, 'to_call_total' => 1,
        ])->and($today['whatsapp'])->toBe(['awaiting' => 0, 'queued' => 0, 'failed' => 1, 'none' => 1])
            ->and($today['to_call'])->toHaveCount(1)
            ->and($today['to_call'][0])->toMatchArray([
                'id'    => $failed->id, 'name' => 'BRUNO BATISTA', 'doctor' => 'DRA. ANA ALVES', 'whatsapp' => 'failed',
                'phone' => '(11) 98765-0002', 'phone_href' => 'tel:+5511987650002',
            ]);

        $tomorrow = $reception['days']['tomorrow'];
        expect($tomorrow)->toMatchArray(['date' => '2026-10-07', 'total' => 2, 'confirmed' => 1, 'unconfirmed' => 1, 'to_call_total' => 1])
            ->and($tomorrow['whatsapp']['awaiting'])->toBe(1)
            ->and($tomorrow['shifts'])->toBe([
                ['key' => 'morning', 'total' => 1, 'confirmed' => 0],
                ['key' => 'evening', 'total' => 1, 'confirmed' => 1],
            ])
            ->and($tomorrow['to_call'][0]['name'])->toBe('DANIEL DIAS');

        expect(json_encode($reception))->not->toContain('OLGA');
    });

    it('sala de espera: quem chegou e espera, por ordem de chegada, com o tempo de espera', function () {
        dv2Schedule($this->entity, $this->doctorA, $this->pAlice, '2026-10-06 09:30', ScheduleSituation::Waiting, ['arrived_at' => '2026-10-06 09:40']);
        dv2Schedule($this->entity, $this->doctorB, $this->pBruno, '2026-10-06 09:00', ScheduleSituation::Dilating, ['arrived_at' => '2026-10-06 09:05']);
        dv2Schedule($this->entity, $this->doctorA, $this->pCarla, '2026-10-06 09:45', ScheduleSituation::InProgress, ['arrived_at' => '2026-10-06 09:30']);
        dv2Schedule($this->entity, $this->doctorA, $this->pDaniel, '2026-10-06 11:00', ScheduleSituation::Confirmed); // não chegou

        [$user, $eu] = dv2Member($this->entity, ClientRule::Secretary);
        $room        = dv2Props($this, $user, $eu)['reception']['waiting_room'];

        expect($room['count'])->toBe(2)
            ->and($room['in_care'])->toBe(1)
            ->and($room['longest'])->toBe(55)
            ->and(collect($room['items'])->pluck('name')->all())->toBe(['BRUNO BATISTA', 'ALICE AMARAL'])
            ->and($room['items'][0])->toMatchArray(['state' => 'preparing', 'waiting_minutes' => 55, 'arrived_time' => '09:05', 'doctor' => 'DR. BRUNO BRAGA'])
            ->and($room['items'][1])->toMatchArray(['state' => 'ready', 'waiting_minutes' => 20]);
    });

    it('lista de espera: total ativo e os primeiros na ordem da Agenda, só desta clínica', function () {
        $wl = fn (Entity $entity, Doctor $doctor, string $name, int $position, bool $active = true) => WaitingList::query()->create([
            'entity_id' => $entity->id, 'doctor_id' => $doctor->id, 'full_name' => $name, 'cellphone' => '11987651111',
            'position'  => $position, 'active' => $active, 'preferred_date_from' => '2026-10-10',
        ]);
        $wl($this->entity, $this->doctorA, 'SEGUNDO DA FILA', 2);
        $wl($this->entity, $this->doctorB, 'PRIMEIRO DA FILA', 1);
        $wl($this->entity, $this->doctorA, 'JA AGENDADO', 3, false);
        $wl($this->other, $this->otherDoctor, 'OUTRA CLINICA FILA', 1);

        [$user, $eu] = dv2Member($this->entity, ClientRule::Secretary);
        $waitlist    = dv2Props($this, $user, $eu)['waitlist'];

        expect($waitlist['count'])->toBe(2)
            ->and(collect($waitlist['items'])->pluck('name')->all())->toBe(['PRIMEIRO DA FILA', 'SEGUNDO DA FILA'])
            ->and($waitlist['items'][0])->toMatchArray(['doctor' => 'DR. BRUNO BRAGA', 'from' => '10/10/2026', 'phone' => '(11) 98765-1111']);
    });

    it('aniversariantes de hoje: só pacientes ativos desta clínica, com idade e telefone', function () {
        dv2Patient($this->entity, 'ANIVERSARIANTE ATIVA', '11987652222', '1972-10-06');
        dv2Patient($this->entity, 'ANIVERSARIANTE INATIVO', '11987652223', '1990-10-06', false);
        dv2Patient($this->entity, 'NASCEU ONTEM', '11987652224', '1990-10-05');
        dv2Patient($this->other, 'ANIVERSARIANTE OUTRA CLINICA', '11987652225', '1985-10-06');

        [$user, $eu] = dv2Member($this->entity, ClientRule::Secretary);
        $birthdays   = dv2Props($this, $user, $eu)['birthdays'];

        expect($birthdays['count'])->toBe(1)
            ->and($birthdays['items'][0])->toMatchArray([
                'name' => 'ANIVERSARIANTE ATIVA', 'age' => 54, 'phone' => '(11) 98765-2222', 'phone_href' => 'tel:+5511987652222',
            ]);
    });

    it('29/02 aparece em 28/02 quando o ano não é bissexto', function () {
        $this->travelTo(Carbon::parse('2027-02-28 10:00:00'));
        dv2Patient($this->entity, 'NASCEU EM 29 DE FEVEREIRO', '11987653333', '2000-02-29');

        [$user, $eu] = dv2Member($this->entity, ClientRule::Secretary);

        expect(collect(dv2Props($this, $user, $eu)['birthdays']['items'])->pluck('name')->all())->toBe(['NASCEU EM 29 DE FEVEREIRO']);
    });

    it('sem números de gestão nem financeiro para a recepção', function () {
        dv2CashEntry($this->entity, ['amount' => 999]);
        [$user, $eu] = dv2Member($this->entity, ClientRule::Secretary);

        $props = dv2Props($this, $user, $eu);

        expect($props['insights'])->toBeNull()
            ->and($props['cashToday'])->toBeNull()
            ->and($props['doctorsToday'])->toBeNull();
    });
});

describe('financeiro — só agregados, só com ViewFinancial', function () {
    it('caixa de hoje, a receber (caixa e convênios), glosas, mês × mês anterior e tendência', function () {
        dv2CashEntry($this->entity, ['amount' => 350]);
        dv2CashEntry($this->entity, ['amount' => 80, 'type' => 'expense']);
        dv2CashEntry($this->entity, ['amount' => 50, 'status' => 'pending']); // a receber hoje
        dv2CashEntry($this->entity, ['amount' => 999, 'status' => 'cancelled']);
        dv2CashEntry($this->other, ['amount' => 4444]);

        $covenant = Covenant::factory()->create(['entity_id' => $this->entity->id, 'active' => true]);
        $claim    = fn (array $attrs) => BillingClaim::query()->create([
            'entity_id'       => $this->entity->id, 'covenant_id' => $covenant->id, 'status' => BillingClaimStatus::Submitted->value,
            'attendance_date' => '2026-10-02', 'amount' => 200, 'quantity' => 1, 'unit_price' => 200, ...$attrs,
        ]);
        $claim(['due_date' => '2026-10-01']);  // vencida
        $claim(['due_date' => '2026-11-01']);  // em aberto
        $claim(['status' => BillingClaimStatus::Paid->value, 'paid_amount' => 200]);

        $operator = TissOperator::query()->create(['ans_code' => (string) random_int(100000, 999999), 'name' => 'Operadora ' . Str::random(4), 'active' => true]);
        TissGlosa::query()->create([
            'entity_id'  => $this->entity->id, 'operator_id' => $operator->id, 'status' => TissGlosaStatus::Open->value,
            'glosa_code' => '3099', 'glosa_description' => 'Não autorizado', 'amount' => 75, 'identified_at' => '2026-10-02', 'deadline' => '2026-10-05',
        ]);

        [$user, $eu] = dv2Member($this->entity, ClientRule::Financial);
        $props       = dv2Props($this, $user, $eu);

        expect($props['cashToday'])->toMatchArray(['received' => 350.0, 'paid' => 80.0, 'realized_balance' => 270.0, 'receivable' => 50.0]);

        $insights = $props['insights'];
        expect($insights['finance'])->toBeTrue()
            ->and($insights['receivables']['claims'])->toMatchArray(['open' => 400.0, 'open_count' => 2, 'overdue' => 200.0, 'overdue_count' => 1])
            ->and($insights['glosas'])->toMatchArray(['open_count' => 1, 'open' => 75.0, 'overdue_count' => 1])
            ->and($insights['month']['current'])->toMatchArray(['income' => 350.0, 'billed' => 600.0, 'paid' => 200.0])
            ->and($insights['month']['covenants'])->not->toBeEmpty()
            ->and($insights['trend']['series'])->toHaveCount(6);
    });

    it('perfis sem ViewFinancial não recebem nada financeiro (secretária, médico, usuário)', function (ClientRule $rule) {
        dv2CashEntry($this->entity, ['amount' => 12345]);
        [$user, $eu] = dv2Member($this->entity, $rule);

        $props = dv2Props($this, $user, $eu);
        $json  = dv2DataJson($props);

        expect($props['cashToday'])->toBeNull()
            ->and($json)->not->toContain('12345')
            ->and($json)->not->toContain('receivables')
            ->and($json)->not->toContain('"income"');
    })->with([
        'secretária' => [ClientRule::Secretary],
        'médico'     => [ClientRule::Doctor],
        'usuário'    => [ClientRule::User],
    ]);

    it('financeiro e usuário: nenhum nome, telefone ou dado de paciente em nenhum bloco', function (ClientRule $rule) {
        dv2Schedule($this->entity, $this->doctorA, $this->pAlice, '2026-10-06 09:00', ScheduleSituation::Waiting, ['arrived_at' => '2026-10-06 08:50']);
        dv2Schedule($this->entity, $this->doctorA, $this->pBruno, '2026-10-07 09:00', ScheduleSituation::Scheduled);
        dv2Patient($this->entity, 'ANIVERSARIANTE SIGILO', '11987654444', '1970-10-06');
        WaitingList::query()->create(['entity_id' => $this->entity->id, 'doctor_id' => $this->doctorA->id, 'full_name' => 'FILA SIGILO', 'cellphone' => '11987655555', 'active' => true]);

        [$user, $eu] = dv2Member($this->entity, $rule);
        $props       = dv2Props($this, $user, $eu);
        $json        = dv2DataJson($props);

        foreach (['ALICE', 'BRUNO', 'SIGILO', '98765', 'ANA ALVES'] as $pii) {
            expect($json)->not->toContain($pii);
        }

        expect($props['reception'])->toBeNull()
            ->and($props['waitlist'])->toBeNull()
            ->and($props['birthdays'])->toBeNull()
            ->and($props['doctorsToday'])->toBeNull();
    })->with([
        'financeiro' => [ClientRule::Financial],
        'usuário'    => [ClientRule::User],
    ]);
});

describe('blocos por perfil', function () {
    it('cada perfil recebe só os blocos do posto de trabalho dele', function (ClientRule $rule, array $present, array $absent) {
        [$user, $eu] = $rule === ClientRule::Doctor ? [$this->docUserA, $this->docEuA] : dv2Member($this->entity, $rule);

        $props = dv2Props($this, $user, $eu);

        foreach ($present as $key) {
            expect($props[$key])->not->toBeNull("{$rule->value}: {$key} deveria vir");
        }

        foreach ($absent as $key) {
            expect($props[$key])->toBeNull("{$rule->value}: {$key} não deveria vir");
        }
    })->with([
        'médico'     => [ClientRule::Doctor, ['insights'], ['reception', 'waitlist', 'birthdays', 'doctorsToday', 'cashToday']],
        'secretária' => [ClientRule::Secretary, ['reception', 'waitlist', 'birthdays'], ['insights', 'doctorsToday', 'cashToday']],
        'admin'      => [ClientRule::Admin, ['insights', 'doctorsToday'], ['reception', 'waitlist', 'birthdays', 'cashToday']],
        'financeiro' => [ClientRule::Financial, ['insights', 'cashToday'], ['reception', 'waitlist', 'birthdays', 'doctorsToday']],
        'usuário'    => [ClientRule::User, [], ['insights', 'reception', 'waitlist', 'birthdays', 'doctorsToday', 'cashToday']],
    ]);

    it('telefone do "ligar para confirmar" segue o acesso a Pacientes', function () {
        dv2Schedule($this->entity, $this->doctorA, $this->pBruno, '2026-10-06 11:00', ScheduleSituation::Scheduled);

        expect(app(ClinicOperationsService::class)->reception((string) $this->entity->id, false)['days']['today']['to_call'][0])
            ->not->toHaveKeys(['phone', 'phone_href'])
            ->toMatchArray(['name' => 'BRUNO BATISTA']);
    });
});

describe('desempenho', function () {
    it('número de consultas do Dashboard por perfil (abertura e polling) fica dentro do limite', function (ClientRule $rule, int $openLimit, int $pollLimit) {
        foreach (range(0, 19) as $i) {
            dv2Schedule($this->entity, $i % 2 ? $this->doctorA : $this->doctorB, $this->pAlice, sprintf('2026-10-%02d %02d:%02d', 1 + intdiv($i, 4), 8 + $i % 4, 0), ScheduleSituation::Attended);
        }
        dv2Schedule($this->entity, $this->doctorA, $this->pBruno, '2026-10-06 11:00', ScheduleSituation::Scheduled);
        dv2Schedule($this->entity, $this->doctorB, $this->pCarla, '2026-10-07 11:00', ScheduleSituation::Scheduled);

        [$user, $eu] = $rule === ClientRule::Doctor ? [$this->docUserA, $this->docEuA] : dv2Member($this->entity, $rule);

        $count = function (array $headers = []) use ($user, $eu): int {
            $queries = 0;
            DB::listen(function () use (&$queries) {
                $queries++;
            });

            $this->actingAs(User::find($user->id))
                ->withSession(panelSession($eu))
                ->get(route('panel.dashboard'), $headers)
                ->assertOk();

            return $queries;
        };

        $open = $count();
        // Polling: as props operacionais (lista do useDashboardPolling no front).
        $poll = $count([
            ...inertiaHeaders(),
            'X-Inertia-Partial-Component' => 'Panel/Dashboard',
            'X-Inertia-Partial-Data'      => 'stats,scheduleToday,nextPatient,recentPatients,aiWaiting,unsignedRecords,activation,activationScore,stockAlerts,reception,waitlist,birthdays,doctorsToday,cashToday',
        ]);

        expect($open)->toBeLessThanOrEqual($openLimit, "{$rule->value}: abertura com {$open} consultas")
            ->and($poll)->toBeLessThanOrEqual($pollLimit, "{$rule->value}: polling com {$poll} consultas");
    })->with([
        // Medido (cache frio): médico 30/18, secretária 27/18, admin 33/15,
        // financeiro 32/11, usuário 21/10 — inclui middleware/sessão. Um N+1
        // (por consulta, paciente ou médico) estoura o limite.
        'médico'     => [ClientRule::Doctor, 38, 24],
        'secretária' => [ClientRule::Secretary, 35, 24],
        'admin'      => [ClientRule::Admin, 42, 20],
        'financeiro' => [ClientRule::Financial, 40, 16],
        'usuário'    => [ClientRule::User, 28, 15],
    ]);
});

describe('textos e preferências', function () {
    it('nenhum texto fixo: toda chave usada nos componentes do Dashboard existe em pt_BR e en', function () {
        $files  = [resource_path('js/Pages/Panel/Dashboard.vue'), ...glob(resource_path('js/Pages/Panel/Dashboard/*.vue'))];
        $source = implode("\n", array_map('file_get_contents', $files));

        preg_match_all('/\\bt\\.([a-z][a-z0-9_]+)/', $source, $dotted);
        preg_match_all("/\\btx\\(\\s*'([a-z][a-z0-9_]+)'/", $source, $called);
        preg_match_all("/\\b(?:label|key): '((?:section|kpi|role)_[a-z0-9_]+)'/", $source, $declared);
        $keys = array_values(array_unique([...$dotted[1], ...$called[1], ...$declared[1]]));

        expect(count($keys))->toBeGreaterThan(100);

        foreach (['pt_BR', 'en'] as $locale) {
            $t = (array) trans('dashboard', [], $locale);

            foreach ($keys as $key) {
                expect(array_key_exists($key, $t))->toBeTrue("{$locale}: chave dashboard.{$key} não existe");
            }
        }
    });

    it('seções ocultas no "Personalizar" ficam salvas nas preferências (lista de chaves)', function () {
        [$user, $eu] = dv2Member($this->entity, ClientRule::Admin);

        $this->actingAs($user)->withSession(panelSession($eu))
            ->patchJson(route('panel.preferences.update'), ['dashboard_hidden_sections' => ['patients', 'trends']])
            ->assertOk()
            ->assertJsonPath('data.dashboard_hidden_sections', ['patients', 'trends']);

        $this->actingAs($user)->withSession(panelSession($eu))
            ->patchJson(route('panel.preferences.update'), ['dashboard_hidden_sections' => [['x' => 1]]])
            ->assertUnprocessable();
    });
});
