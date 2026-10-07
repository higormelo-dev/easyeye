<?php

declare(strict_types=1);

use App\Enums\{ClientRule, DataAccessPurpose, ScheduleSituation};
use App\Models\{Covenant, DataAccessLog, Doctor, Entity, EntityUser, Patient, People, Schedule, User, VisitType};
use Carbon\Carbon;
use Inertia\Testing\AssertableInertia;

/*
 * Fase 1 / B3 — relatório de primeira consulta: pacientes cuja PRIMEIRA
 * consulta atendida na clínica cai no período (data, paciente, médico,
 * convênio, tipo), filtro por médico, exportação (CSV/Excel) e escopo de
 * médico (CurrentDoctor).
 */
beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-20 10:00:00'));

    $this->entity = Entity::factory()->create(['is_client' => true]);
    $this->other  = Entity::factory()->create(['is_client' => true]);

    [$this->docAUser, $this->docAEu, $this->doctorA] = fcrDoctor($this->entity, 'DRA. ANA');
    [$this->docBUser, $this->docBEu, $this->doctorB] = fcrDoctor($this->entity, 'DR. BRUNO');
    [, , $this->otherDoctor]                         = fcrDoctor($this->other, 'DR. OUTRA');

    [$this->admUser, $this->admEu] = fcrMember($this->entity, ClientRule::Admin);
    [$this->finUser, $this->finEu] = fcrMember($this->entity, ClientRule::Financial);
    [$this->secUser, $this->secEu] = fcrMember($this->entity, ClientRule::Secretary);

    $this->unimed  = Covenant::factory()->create(['entity_id' => $this->entity->id, 'name' => 'UNIMED', 'active' => true]);
    $this->consult = VisitType::query()->create(['entity_id' => $this->entity->id, 'name' => 'CONSULTA OFTALMO', 'active' => true]);

    // Novo em outubro com a Ana (agendou antes, mas só foi ATENDIDO em outubro).
    $this->novo = fcrPatient($this->entity, 'PACIENTE NOVO');
    fcrSchedule($this->novo, $this->doctorA, '2026-09-10 09:00', ScheduleSituation::NoShow);
    $this->novoFirst = fcrSchedule($this->novo, $this->doctorA, '2026-10-05 09:00', ScheduleSituation::Attended, ['covenant_id' => $this->unimed->id, 'visit_id' => $this->consult->id]);
    fcrSchedule($this->novo, $this->doctorB, '2026-10-15 09:00', ScheduleSituation::Attended); // retorno: não conta

    // Antigo: 1ª atendida em agosto — volta em outubro, não é primeira consulta.
    $this->antigo = fcrPatient($this->entity, 'PACIENTE ANTIGO');
    fcrSchedule($this->antigo, $this->doctorB, '2026-08-01 10:00', ScheduleSituation::Attended);
    fcrSchedule($this->antigo, $this->doctorB, '2026-10-06 10:00', ScheduleSituation::Attended);

    // Novo com o Bruno em outubro.
    $this->novoB      = fcrPatient($this->entity, 'NOVO DO BRUNO');
    $this->novoBFirst = fcrSchedule($this->novoB, $this->doctorB, '2026-10-12 15:00', ScheduleSituation::Attended);

    // Ainda não atendido (agendado/cancelado): não entra.
    $pendente = fcrPatient($this->entity, 'AINDA NAO ATENDIDO');
    fcrSchedule($pendente, $this->doctorA, '2026-10-21 08:00', ScheduleSituation::Scheduled);
    fcrSchedule($pendente, $this->doctorA, '2026-10-02 08:00', ScheduleSituation::Cancelled);

    // 1ª atendida em novembro: fora do período.
    $futuro = fcrPatient($this->entity, 'FUTURO');
    fcrSchedule($futuro, $this->doctorA, '2026-11-03 08:00', ScheduleSituation::Attended);

    // Outra clínica.
    fcrSchedule(fcrPatient($this->other, 'DE OUTRA CLINICA'), $this->otherDoctor, '2026-10-05 08:00', ScheduleSituation::Attended);
});

function fcrDoctor(Entity $entity, string $name): array
{
    $user = User::factory()->create(['name' => $name]);
    $eu   = createEntityUser($entity, $user, ClientRule::Doctor->value);

    return [$user, $eu, Doctor::create(['entity_user_id' => $eu->id, 'person_id' => People::factory()->create()->id, 'active' => true])];
}

function fcrMember(Entity $entity, ClientRule $rule): array
{
    $user = User::factory()->create();

    return [$user, createEntityUser($entity, $user, $rule->value)];
}

function fcrPatient(Entity $entity, string $name): Patient
{
    return Patient::create([
        'entity_id'   => $entity->id,
        'person_id'   => People::factory()->create(['full_name' => $name])->id,
        'covenant_id' => Covenant::factory()->create()->id,
        'active'      => true,
    ]);
}

function fcrSchedule(Patient $patient, Doctor $doctor, string $at, ScheduleSituation $situation, array $extra = []): Schedule
{
    return Schedule::query()->create([
        'entity_id'  => $patient->entity_id,
        'doctor_id'  => $doctor->id,
        'patient_id' => $patient->id,
        'full_name'  => $patient->person->full_name,
        'date_time'  => $at,
        'situation'  => $situation->value,
        'active'     => true,
        ...$extra,
    ]);
}

function fcrReport(object $t, User $user, EntityUser $eu, array $query = []): array
{
    $props = null;
    $t->actingAs($user)->withSession(panelSession($eu))
        ->get(route('panel.schedules.reports.first-consultations', $query))
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) use (&$props) {
            $page->component('Panel/Reports/FirstConsultations');
            $props = $page->toArray()['props'];
        });

    return $props;
}

const FCR_OCTOBER = ['date_from' => '2026-10-01', 'date_until' => '2026-10-31'];

describe('quem entra no relatório', function () {
    it('só a 1ª consulta ATENDIDA de cada paciente na clínica, dentro do período', function () {
        $props = fcrReport($this, $this->admUser, $this->admEu, FCR_OCTOBER);

        expect(collect($props['rows'])->pluck('id')->all())->toBe([$this->novoFirst->id, $this->novoBFirst->id])
            ->and($props['summary']['total'])->toBe(2);

        $first = $props['rows'][0];
        expect($first)->toMatchArray([
            'date_time'    => '05/10/2026 09:00',
            'patient_name' => 'PACIENTE NOVO',
            'patient_code' => $this->novo->code,
            'doctor_name'  => 'DRA. ANA',
            'covenant'     => 'UNIMED',
            'type'         => 'CONSULTA OFTALMO',
        ]);
        expect(collect($props['summary']['by_doctor'])->pluck('total', 'name')->all())->toBe(['DRA. ANA' => 1, 'DR. BRUNO' => 1]);
    });

    it('duas consultas atendidas no mesmo horário contam uma vez (desempate)', function () {
        $p = fcrPatient($this->entity, 'MESMA HORA');
        fcrSchedule($p, $this->doctorA, '2026-10-08 11:00', ScheduleSituation::Attended);
        fcrSchedule($p, $this->doctorB, '2026-10-08 11:00', ScheduleSituation::Attended, ['is_fit_in' => true]);

        $rows = collect(fcrReport($this, $this->admUser, $this->admEu, FCR_OCTOBER)['rows']);

        expect($rows->where('patient_name', 'MESMA HORA'))->toHaveCount(1);
    });

    it('filtro por médico e período padrão (mês atual até hoje)', function () {
        $byDoctor = fcrReport($this, $this->admUser, $this->admEu, [...FCR_OCTOBER, 'doctor_id' => $this->doctorB->id]);
        expect(collect($byDoctor['rows'])->pluck('id')->all())->toBe([$this->novoBFirst->id]);

        $default = fcrReport($this, $this->admUser, $this->admEu);
        expect($default['filters']['date_from'])->toBe('2026-10-01')
            ->and($default['filters']['date_until'])->toBe('2026-10-20')
            ->and($default['summary']['total'])->toBe(2);
    });

    it('período longo demais é recusado', function () {
        $this->actingAs($this->admUser)->withSession(panelSession($this->admEu))
            ->get(route('panel.schedules.reports.first-consultations', ['date_from' => '2024-01-01', 'date_until' => '2026-10-01']))
            ->assertSessionHasErrors('date_until');
    });
});

describe('acesso e escopo de médico', function () {
    it('médico vê só as primeiras consultas que foram com ele (e não troca o médico pela URL)', function () {
        $props = fcrReport($this, $this->docAUser, $this->docAEu, [...FCR_OCTOBER, 'doctor_id' => $this->doctorB->id]);

        expect(collect($props['rows'])->pluck('id')->all())->toBe([$this->novoFirst->id])
            ->and($props['isDoctor'])->toBeTrue()
            ->and($props['doctors'])->toBe([])
            ->and($props['filters']['doctor_id'])->toBeNull();
    });

    it('perfil médico sem cadastro: vazio com aviso; exportar é recusado', function () {
        [$user, $eu] = fcrMember($this->entity, ClientRule::Doctor);

        $props = fcrReport($this, $user, $eu, FCR_OCTOBER);
        expect($props['rows'])->toBe([])
            ->and($props['doctorMissing'])->toBeTrue();

        $this->actingAs($user)->withSession(panelSession($eu))
            ->get(route('panel.schedules.reports.first-consultations.export', FCR_OCTOBER))
            ->assertForbidden();
    });

    it('financeiro abre; secretária não', function () {
        fcrReport($this, $this->finUser, $this->finEu, FCR_OCTOBER);

        $this->actingAs($this->secUser)->withSession(panelSession($this->secEu))
            ->get(route('panel.schedules.reports.first-consultations'))
            ->assertForbidden();
    });

    it('a Agenda oferece o relatório a quem pode abrir', function () {
        $urls = function (User $user, EntityUser $eu) {
            $props = null;
            $this->actingAs($user)->withSession(panelSession($eu))->get(route('panel.schedules.index'))
                ->assertInertia(function (AssertableInertia $page) use (&$props) {
                    $props = $page->toArray()['props'];
                });

            return $props['reportsUrls'];
        };

        expect($urls($this->admUser, $this->admEu))->toHaveKeys(['production', 'absenteeism', 'first_consultations'])
            ->and($urls($this->docAUser, $this->docAEu))->toBe(['first_consultations' => route('panel.schedules.reports.first-consultations')])
            ->and($urls($this->secUser, $this->secEu))->toBeNull();
    });
});

describe('exportação', function () {
    it('CSV com o mesmo recorte, cabeçalho no idioma e registro de acesso por linha', function () {
        $response = $this->actingAs($this->admUser)->withSession(panelSession($this->admEu))
            ->get(route('panel.schedules.reports.first-consultations.export', FCR_OCTOBER));

        $response->assertOk();
        expect($response->headers->get('Content-Type'))->toContain('text/csv')
            ->and($response->headers->get('Content-Disposition'))->toContain('primeiras_consultas_2026-10-01_2026-10-31.csv');

        $csv = $response->getContent();
        expect($csv)->toContain('Data;Paciente;Código;Médico;Convênio;Tipo')
            ->and($csv)->toContain('"05/10/2026 09:00";"PACIENTE NOVO";' . $this->novo->code . ';"DRA. ANA";UNIMED;"CONSULTA OFTALMO"')
            ->and($csv)->toContain('NOVO DO BRUNO')
            ->and($csv)->not->toContain('PACIENTE ANTIGO')
            ->and($csv)->not->toContain('DE OUTRA CLINICA');

        $logs = DataAccessLog::query()->where('purpose', DataAccessPurpose::Export->value)->get();
        expect($logs)->toHaveCount(2)
            ->and($logs->pluck('patient_id')->sort()->values()->all())->toBe(collect([$this->novo->id, $this->novoB->id])->sort()->values()->all());
    });

    it('médico exporta só as dele; Excel sai como xlsx', function () {
        $csv = $this->actingAs($this->docBUser)->withSession(panelSession($this->docBEu))
            ->get(route('panel.schedules.reports.first-consultations.export', FCR_OCTOBER))
            ->assertOk()
            ->getContent();

        expect($csv)->toContain('NOVO DO BRUNO')->not->toContain('"PACIENTE NOVO"');

        $xlsx = $this->actingAs($this->admUser)->withSession(panelSession($this->admEu))
            ->get(route('panel.schedules.reports.first-consultations.export', [...FCR_OCTOBER, 'format' => 'xlsx']))
            ->assertOk();

        expect($xlsx->headers->get('Content-Type'))->toBe('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            ->and(substr($xlsx->getContent(), 0, 2))->toBe('PK');
    });
});

it('first_consultations.php tem as mesmas chaves em pt_BR e en', function () {
    expect(array_keys(require lang_path('en/first_consultations.php')))
        ->toEqualCanonicalizing(array_keys(require lang_path('pt_BR/first_consultations.php')));
});
