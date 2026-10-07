<?php

declare(strict_types=1);

use App\Enums\{ClientRule, DataAccessPurpose, ScheduleSituation};
use App\Models\{ClinicResource, Covenant, CovenantPlan, DataAccessLog, Doctor, Entity, Patient, People, Schedule, User, VisitType};
use App\Support\PatientAccess;
use Barryvdh\Snappy\Facades\SnappyPdf;
use Carbon\Carbon;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Str;

/*
 * Fase 1 / A5 — impressão a partir da Agenda (PDF): agenda do dia com os
 * filtros aplicados e ficha do paciente do agendamento. Conteúdo, tenant,
 * escopo de médico, log de acesso sensível (LGPD art. 37), telefone só para
 * quem tem acesso a Pacientes, idioma do usuário.
 */
beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-06 08:00:00'));

    $this->entity = Entity::factory()->create(['name' => 'Clínica Íris', 'is_client' => true, 'active' => true]);
    $this->other  = Entity::factory()->create(['is_client' => true, 'active' => true]);

    $this->secretary                           = createEntityUser($this->entity, User::factory()->create(['name' => 'SOFIA RECEPÇÃO']), ClientRule::Secretary->value);
    [$this->anaUser, $this->anaEu, $this->ana] = sptDoctor($this->entity, 'DRA. ANA');
    [, , $this->bruno]                         = sptDoctor($this->entity, 'DR. BRUNO');

    $this->covenant = Covenant::factory()->create(['entity_id' => $this->entity->id, 'name' => 'UNIMED', 'active' => true]);
    $this->plan     = CovenantPlan::create(['entity_id' => $this->entity->id, 'covenant_id' => $this->covenant->id, 'name' => 'PLENO', 'active' => true]);
    $this->visit    = VisitType::create(['entity_id' => $this->entity->id, 'name' => 'RETORNO', 'active' => true]);
    $this->room     = ClinicResource::create(['entity_id' => $this->entity->id, 'name' => 'SALA 2', 'type' => 'room', 'active' => true]);

    $this->patient = Patient::factory()->create([
        'entity_id'        => $this->entity->id,
        'covenant_id'      => $this->covenant->id,
        'covenant_plan_id' => $this->plan->id,
        'card_number'      => '0012345',
        'person_id'        => People::factory()->create([
            'full_name'         => 'MARIA DAS GRAÇAS',
            'birth_date'        => '1956-03-10',
            'cellphone'         => '11987654321',
            'telephone'         => null,
            'national_registry' => '52998224725',
            'mother_name'       => 'JOANA',
            'email'             => 'maria@example.test',
            'gender'            => 0,
        ])->id,
    ]);

    $this->schedule = Schedule::create([
        'entity_id'      => $this->entity->id,
        'doctor_id'      => $this->ana->id,
        'patient_id'     => $this->patient->id,
        'covenant_id'    => $this->covenant->id,
        'visit_id'       => $this->visit->id,
        'full_name'      => 'MARIA DAS GRAÇAS',
        'date_time'      => Carbon::parse('2026-10-06 09:30'),
        'situation'      => ScheduleSituation::Confirmed->value,
        'is_fit_in'      => true,
        'series_id'      => (string) Str::uuid(),
        'session_number' => 3,
        'session_total'  => 10,
        'notes'          => 'Trazer exames anteriores',
        'active'         => true,
    ]);
    $this->schedule->resources()->sync([$this->room->id]);

    $this->brunoSchedule = Schedule::create([
        'entity_id' => $this->entity->id, 'doctor_id' => $this->bruno->id, 'full_name' => 'JOÃO SEM CADASTRO',
        'date_time' => Carbon::parse('2026-10-06 14:00'), 'situation' => ScheduleSituation::Scheduled->value,
        'cellphone' => '11911112222', 'active' => true,
    ]);

    [, , $otherDoctor] = sptDoctor($this->other, 'DR. ALHEIO');
    $this->foreign     = Schedule::create([
        'entity_id' => $this->other->id, 'doctor_id' => $otherDoctor->id, 'full_name' => 'PACIENTE DE OUTRA CLINICA',
        'date_time' => Carbon::parse('2026-10-06 09:30'), 'situation' => ScheduleSituation::Scheduled->value, 'active' => true,
    ]);
});

afterEach(fn () => App::setLocale('pt_BR'));

function sptDoctor(Entity $entity, string $name): array
{
    $user = User::factory()->create(['name' => $name]);
    $eu   = createEntityUser($entity, $user, ClientRule::Doctor->value);

    return [$user, $eu, Doctor::create(['entity_user_id' => $eu->id, 'person_id' => People::factory()->create()->id, 'active' => true, 'record' => '12345'])];
}

/**
 * Captura a view e os dados entregues ao wkhtmltopdf e devolve o HTML
 * renderizado de verdade (o binário não roda nos testes).
 *
 * @return array{view: string, data: array, html: string}
 */
function sptCapture(): object
{
    $captured = new stdClass();

    $pdf = Mockery::mock();
    $pdf->shouldReceive('setPaper')->andReturnSelf();
    $pdf->shouldReceive('setOrientation')->andReturnSelf();
    $pdf->shouldReceive('setOption')->andReturnSelf();
    $pdf->shouldReceive('inline')->andReturn(response('%PDF-fake', 200, ['Content-Type' => 'application/pdf']));

    SnappyPdf::shouldReceive('loadView')->andReturnUsing(function (string $view, array $data) use ($captured, $pdf) {
        $captured->view = $view;
        $captured->data = $data;
        $captured->html = view($view, $data)->render();

        return $pdf;
    });

    return $captured;
}

function sptAs(object $test, $entityUser): object
{
    return $test->actingAs($entityUser->user)->withSession(panelSession($entityUser));
}

describe('agenda do dia', function () {
    it('lista horário, paciente, idade, convênio/plano, tipo, situação, encaixe, sessão, sala e telefone', function () {
        $pdf = sptCapture();

        sptAs($this, $this->secretary)->get(route('panel.schedules.print', ['date' => '2026-10-06']))->assertOk();

        expect($pdf->view)->toBe('pdf.schedule_agenda')
            ->and($pdf->html)->toContain('09:30')
            ->toContain('MARIA DAS GRAÇAS')
            ->toContain('70 anos')
            ->toContain('UNIMED · PLENO')
            ->toContain('RETORNO')
            ->toContain('Confirmado')
            ->toContain('Encaixe')
            ->toContain('3/10')
            ->toContain('SALA 2')
            ->toContain('(11) 98765-4321')
            ->toContain('JOÃO SEM CADASTRO')
            ->toContain('(11) 91111-2222')
            ->toContain('Todos os médicos')
            ->toContain('Terça-feira, 6 de outubro de 2026')
            ->not->toContain('PACIENTE DE OUTRA CLINICA');
    });

    it('respeita os filtros da tela (médico, turno, chip) e mostra quais foram aplicados', function () {
        $pdf = sptCapture();

        sptAs($this, $this->secretary)->get(route('panel.schedules.print', [
            'date' => '2026-10-06', 'doctor' => $this->bruno->id, 'bout' => 3, 'status' => 'unconfirmed',
        ]))->assertOk();

        expect($pdf->data['rows']->pluck('name')->all())->toBe(['JOÃO SEM CADASTRO'])
            ->and($pdf->html)->toContain('DR. BRUNO')
            ->toContain('Tarde')
            ->toContain('Situação: Não confirmados')
            ->not->toContain('MARIA DAS GRAÇAS');
    });

    it('médico imprime só a agenda dele, mesmo pedindo outro médico', function () {
        $pdf = sptCapture();

        sptAs($this, $this->anaEu)->get(route('panel.schedules.print', ['date' => '2026-10-06', 'doctor' => $this->bruno->id]))->assertOk();

        expect($pdf->data['rows']->pluck('name')->all())->toBe(['MARIA DAS GRAÇAS'])
            ->and($pdf->html)->toContain('DRA. ANA');
    });

    it('registra um acesso por agendamento impresso, ligado ao paciente', function () {
        sptCapture();

        sptAs($this, $this->secretary)->get(route('panel.schedules.print', ['date' => '2026-10-06']))->assertOk();

        $logs = DataAccessLog::query()->where('resource_type', Schedule::class)->get();

        expect($logs)->toHaveCount(2)
            ->and($logs->pluck('resource_id')->sort()->values()->all())->toBe(collect([$this->schedule->id, $this->brunoSchedule->id])->sort()->values()->all())
            ->and($logs->firstWhere('resource_id', $this->schedule->id)->patient_id)->toBe($this->patient->id)
            ->and($logs->every(fn ($log) => $log->purpose === DataAccessPurpose::Report && $log->entity_id === $this->entity->id && $log->user_id === $this->secretary->user_id))->toBeTrue();
    });

    it('sem acesso a Pacientes: sem a coluna de telefone', function () {
        $this->app->instance(PatientAccess::class, new class() extends PatientAccess {
            public function current(): bool
            {
                return false;
            }
        });
        $pdf = sptCapture();

        sptAs($this, $this->secretary)->get(route('panel.schedules.print', ['date' => '2026-10-06']))->assertOk();

        expect($pdf->data['withPhones'])->toBeFalse()
            ->and($pdf->html)->not->toContain('(11) 98765-4321')
            ->not->toContain('Telefone');
    });

    it('em inglês: textos e data no idioma do usuário', function () {
        $pdf = sptCapture();

        sptAs($this, $this->secretary)->withSession(['locale' => 'en'])->get(route('panel.schedules.print', ['date' => '2026-10-06']))->assertOk();

        expect($pdf->html)->toContain('<html lang="en">')
            ->toContain('Day schedule')
            ->toContain('Tuesday, October 6, 2026')
            ->toContain('70 years')
            ->toContain('Confirmed')
            ->toContain('Fit-in')
            ->toContain('Health plan')
            ->not->toContain('Encaixe');
    });

    it('médico sem cadastro de médico: 403', function () {
        $missing = createEntityUser($this->entity, User::factory()->create(), ClientRule::Doctor->value);

        sptAs($this, $missing)->get(route('panel.schedules.print', ['date' => '2026-10-06']))->assertForbidden();
    });
});

describe('ficha do paciente', function () {
    it('identificação, contato, convênio/plano/carteirinha e os dados do agendamento', function () {
        $pdf = sptCapture();

        sptAs($this, $this->secretary)->get(route('panel.schedules.patient-sheet', $this->schedule))->assertOk();

        expect($pdf->view)->toBe('pdf.schedule_patient_sheet')
            ->and($pdf->html)->toContain('MARIA DAS GRAÇAS')
            ->toContain($this->patient->code)
            ->toContain('10/03/1956')
            ->toContain('70 anos')
            ->toContain('Feminino')
            ->toContain('529.982.247-25')
            ->toContain('JOANA')
            ->toContain('(11) 98765-4321')
            ->toContain('maria@example.test')
            ->toContain('UNIMED')
            ->toContain('PLENO')
            ->toContain('0012345')
            ->toContain($this->schedule->code)
            ->toContain('Terça-feira, 6 de outubro de 2026 — 09:30')
            ->toContain('DRA. ANA')
            ->toContain('RETORNO')
            ->toContain('Encaixe')
            ->toContain('Sessão 3/10')
            ->toContain('SALA 2')
            ->toContain('Trazer exames anteriores');
    });

    it('registra o acesso ao cadastro do paciente', function () {
        sptCapture();

        sptAs($this, $this->secretary)->get(route('panel.schedules.patient-sheet', $this->schedule))->assertOk();

        $log = DataAccessLog::query()->where('resource_type', Patient::class)->sole();

        expect($log->patient_id)->toBe($this->patient->id)
            ->and($log->purpose)->toBe(DataAccessPurpose::Administrative)
            ->and($log->entity_id)->toBe($this->entity->id);
    });

    it('outra clínica: 404; médico não abre a ficha de agendamento do colega; sem paciente: 404; sem acesso a Pacientes: 403', function () {
        sptCapture();

        sptAs($this, $this->secretary)->get(route('panel.schedules.patient-sheet', $this->foreign))->assertNotFound();

        $brunoWithPatient = Schedule::create([
            'entity_id' => $this->entity->id, 'doctor_id' => $this->bruno->id, 'patient_id' => $this->patient->id, 'full_name' => 'MARIA DAS GRAÇAS',
            'date_time' => Carbon::parse('2026-10-07 10:00'), 'situation' => ScheduleSituation::Scheduled->value, 'active' => true,
        ]);
        sptAs($this, $this->anaEu)->get(route('panel.schedules.patient-sheet', $brunoWithPatient))->assertForbidden();
        sptAs($this, $this->anaEu)->get(route('panel.schedules.patient-sheet', $this->schedule))->assertOk();

        sptAs($this, $this->secretary)->get(route('panel.schedules.patient-sheet', $this->brunoSchedule))->assertNotFound();

        $this->app->instance(PatientAccess::class, new class() extends PatientAccess {
            public function current(): bool
            {
                return false;
            }
        });
        sptAs($this, $this->secretary)->get(route('panel.schedules.patient-sheet', $this->schedule))->assertForbidden();

        expect(DataAccessLog::query()->where('resource_type', Patient::class)->count())->toBe(1);
    });

    it('drawer oferece a ficha só com paciente cadastrado e acesso a Pacientes', function () {
        sptAs($this, $this->secretary)->getJson(route('panel.schedules.show', $this->schedule))
            ->assertJsonPath('data.patient_sheet_url', route('panel.schedules.patient-sheet', $this->schedule->id));

        sptAs($this, $this->secretary)->getJson(route('panel.schedules.show', $this->brunoSchedule))
            ->assertJsonPath('data.patient_sheet_url', null);
    });
});
