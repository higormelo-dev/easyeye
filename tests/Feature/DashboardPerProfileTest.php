<?php

declare(strict_types=1);

use App\Domains\AI\Models\AiRun;
use App\Enums\AI\{AiRiskLevel, AiRunMode, AiRunStatus};
use App\Enums\{ClientRule, DocumentationType, FeatureKey, ScheduleSituation, SubscriptionStatus};
use App\Http\Controllers\PanelDashboardController;
use App\Models\{Doctor, Entity, MedicalRecord, MedicalRecordDocumentation, Patient, PatientExam, People, Plan, PlanFeature, Schedule, Subscription, User};
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

/**
 * Dashboard por perfil (PanelDashboardController):
 *  - médico: SÓ o que é dele (agenda, próximo paciente, exames sem laudo,
 *    laudos de IA a revisar, prontuários sem assinatura, pacientes atendidos
 *    por ele) — nada de outro médico nem de outra clínica;
 *  - médico sem cadastro de médico: nada da clínica;
 *  - financeiro e usuário: nenhum nome/telefone de paciente nem agenda nominal;
 *  - secretária e administrador: visão da clínica.
 *
 * Relógio fixo ao meio-dia para "hoje"/"próximo horário" serem determinísticos.
 */
beforeEach(function () {
    $this->travelTo(now()->setTime(12, 0));

    $this->entity = Entity::factory()->create(['is_client' => true, 'active' => true]);

    [$this->userA, $this->euA, $this->doctorA] = dppDoctor($this->entity, 'DRA. ANA ALVES');
    [$this->userB, $this->euB, $this->doctorB] = dppDoctor($this->entity, 'DR. BRUNO BRAGA');

    $this->pA1 = dppPatient($this->entity, 'ALICE AGUARDANDO');
    $this->pA2 = dppPatient($this->entity, 'ARTHUR ATENDIDO');
    $this->pA3 = dppPatient($this->entity, 'AMANDA DILATANDO');
    $this->pA4 = dppPatient($this->entity, 'AUGUSTO AGENDADO');
    $this->pA5 = dppPatient($this->entity, 'AURORA CANCELADA');
    $this->pB1 = dppPatient($this->entity, 'BIANCA DOOUTROMEDICO');
});

function dppDoctor(Entity $entity, string $name): array
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

function dppPatient(Entity $entity, string $name): Patient
{
    return Patient::factory()->create([
        'entity_id' => $entity->id,
        'active'    => true,
        'person_id' => People::factory()->create(['full_name' => $name, 'cellphone' => '11987654321'])->id,
    ]);
}

function dppSchedule(Entity $entity, Doctor $doctor, Patient $patient, string $time, ScheduleSituation $situation, ?string $arrived = null, int $daysAgo = 0): Schedule
{
    return Schedule::query()->create([
        'entity_id'  => $entity->id,
        'doctor_id'  => $doctor->id,
        'patient_id' => $patient->id,
        'full_name'  => $patient->person->full_name,
        'date_time'  => now()->subDays($daysAgo)->setTimeFromTimeString($time),
        'situation'  => $situation->value,
        'arrived_at' => $arrived ? now()->subDays($daysAgo)->setTimeFromTimeString($arrived) : null,
        'active'     => true,
    ]);
}

/** Agenda de hoje (relógio às 12:00) dos dois médicos da clínica. */
function dppTodaySchedules($test): array
{
    return [
        'attended'  => dppSchedule($test->entity, $test->doctorA, $test->pA2, '08:00', ScheduleSituation::Attended, '07:55'),
        'waiting'   => dppSchedule($test->entity, $test->doctorA, $test->pA1, '10:00', ScheduleSituation::Waiting, '11:30'),
        'dilating'  => dppSchedule($test->entity, $test->doctorA, $test->pA3, '10:30', ScheduleSituation::Dilating, '11:00'),
        'scheduled' => dppSchedule($test->entity, $test->doctorA, $test->pA4, '14:00', ScheduleSituation::Scheduled),
        'cancelled' => dppSchedule($test->entity, $test->doctorA, $test->pA5, '11:00', ScheduleSituation::Cancelled),
        'other'     => dppSchedule($test->entity, $test->doctorB, $test->pB1, '10:00', ScheduleSituation::Waiting, '10:00'),
    ];
}

function dppProps($test, User $user, $entityUser): array
{
    $props = null;

    $test->actingAs($user)
        ->withSession(panelSession($entityUser))
        ->get(route('panel.dashboard'))
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) use (&$props) {
            $props = $page->toArray()['props'];
        });

    return $props;
}

/** Só a parte de dados do Dashboard (sem o compartilhado do layout: auth, tour...). */
function dppDataJson(array $props): string
{
    return json_encode(array_intersect_key($props, array_flip([
        'stats', 'scheduleToday', 'nextPatient', 'recentPatients', 'aiWaiting', 'unsignedRecords',
        'reception', 'waitlist', 'birthdays', 'doctorsToday', 'cashToday', 'insights',
    ])), JSON_UNESCAPED_UNICODE);
}

function dppGiveAi(Entity $entity): void
{
    $plan = Plan::factory()->create(['active' => true]);
    PlanFeature::factory()->enabled(FeatureKey::HasAiEyeImageAnalysis)->for($plan)->create();
    Subscription::factory()->create([
        'entity_id' => $entity->id, 'plan_id' => $plan->id, 'status' => SubscriptionStatus::Active,
        'starts_at' => now()->subDay(), 'ends_at' => now()->addMonth(),
    ]);
}

function dppExam(Patient $patient, ?Doctor $doctor, array $attributes = []): PatientExam
{
    return PatientExam::factory()->create([
        'patient_id' => $patient->id,
        'doctor_id'  => $doctor?->id,
        'active'     => true,
        ...$attributes,
    ]);
}

function dppRecord(Entity $entity, Patient $patient, Doctor $doctor, array $attributes = []): MedicalRecord
{
    $record = MedicalRecord::query()->create([
        'entity_id'  => $entity->id,
        'patient_id' => $patient->id,
        'doctor_id'  => $doctor->id,
    ]);

    if ($attributes !== []) {
        // Direto na tabela: created_at retroativo e assinatura sem passar pelo
        // fluxo (a trava do Signable bloquearia updates depois de assinado).
        DB::table('medical_records')->where('id', $record->id)->update($attributes);
    }

    return $record->fresh();
}

function dppAiRun(Entity $entity, User $requester, AiRunStatus $status, array $attributes = [], ?PatientExam $exam = null): AiRun
{
    $run = AiRun::query()->create([
        'entity_id'    => $entity->id,
        'requested_by' => $requester->id,
        'workflow'     => 'eye_image_analysis',
        'mode'         => AiRunMode::Economy->value,
        'risk_level'   => AiRiskLevel::Medium->value,
        'status'       => $status->value,
        'final_output' => 'Rascunho.',
        ...$attributes,
    ]);

    if ($exam) {
        DB::table('ai_run_patient_exam')->insert([
            'ai_run_id'       => $run->id,
            'patient_exam_id' => $exam->id,
            'entity_id'       => $entity->id,
            'created_at'      => now(),
        ]);
    }

    return $run;
}

describe('médico', function () {
    it('agenda de hoje e resumo do dia só com as consultas dele, sem a coluna médico', function () {
        dppTodaySchedules($this);

        $props = dppProps($this, $this->userA, $this->euA);

        expect($props['profile'])->toBe('doctor')
            ->and($props['doctorMissing'])->toBeFalse()
            ->and($props['sections'])->toBe(['next', 'kpis', 'agenda', 'pending', 'patients', 'shortcuts', 'stock']);

        $names = collect($props['scheduleToday'])->pluck('name')->all();
        expect($names)->toBe(['ARTHUR ATENDIDO', 'ALICE AGUARDANDO', 'AMANDA DILATANDO', 'AURORA CANCELADA', 'AUGUSTO AGENDADO'])
            ->and(collect($props['scheduleToday'])->every(fn ($row) => ! array_key_exists('doctor', $row)))->toBeTrue();

        // Nada da clínica inteira: sem total de pacientes/médicos.
        expect($props['stats'])->not->toHaveKeys(['total_patients', 'total_doctors'])
            ->and($props['stats'])->toMatchArray([
                'doctor_id'       => (string) $this->doctorA->id,
                'today_count'     => 5,
                'attended_today'  => 1,
                'pending_today'   => 3,
                'cancelled_today' => 1,
                'waiting_now'     => 2, // aguardando + dilatando (chegaram)
            ]);

        expect(dppDataJson($props))->not->toContain('BIANCA')->not->toContain('BRUNO');
    });

    it('próximo paciente: quem está pronto para ele vem antes de quem chegou antes mas está dilatando', function () {
        $s = dppTodaySchedules($this);

        $next = dppProps($this, $this->userA, $this->euA)['nextPatient'];

        expect($next)->toMatchArray([
            'id'              => $s['waiting']->id,
            'name'            => 'ALICE AGUARDANDO',
            'state'           => 'waiting',
            'waiting_minutes' => 30,
            'attend_url'      => route('panel.schedules.attend', $s['waiting']->id),
            'patient_url'     => route('panel.patients.index', ['open' => $this->pA1->id]),
        ])->and($next['arrived_time'])->not->toBeNull();
    });

    it('próximo paciente: só em preparo (dilatando) — aparece, mas sem "Iniciar atendimento"', function () {
        $s = dppTodaySchedules($this);
        $s['waiting']->update(['situation' => ScheduleSituation::InProgress->value]);

        $next = dppProps($this, $this->userA, $this->euA)['nextPatient'];

        expect($next['id'])->toBe($s['dilating']->id)
            ->and($next['state'])->toBe('waiting')
            ->and($next['attend_url'])->toBeNull();
    });

    it('próximo paciente: ninguém esperando, vem o próximo horário marcado; nada mais hoje, null', function () {
        $s = dppTodaySchedules($this);
        $s['waiting']->update(['situation' => ScheduleSituation::Attended->value]);
        $s['dilating']->update(['situation' => ScheduleSituation::Attended->value]);

        $next = dppProps($this, $this->userA, $this->euA)['nextPatient'];
        expect($next['id'])->toBe($s['scheduled']->id)
            ->and($next['state'])->toBe('scheduled')
            ->and($next['arrived_time'])->toBeNull()
            ->and($next['attend_url'])->toBeNull();

        $s['scheduled']->update(['situation' => ScheduleSituation::NoShow->value]);
        expect(dppProps($this, $this->userA, $this->euA)['nextPatient'])->toBeNull();
    });

    it('pacientes recentes: os dele, por data da última consulta, sem faltas/cancelados nem telefone', function () {
        dppTodaySchedules($this);
        // Consulta antiga do pA2 e uma do médico B com paciente que A nunca atendeu.
        dppSchedule($this->entity, $this->doctorA, $this->pA2, '09:00', ScheduleSituation::Attended, '08:50', 10);
        dppSchedule($this->entity, $this->doctorB, $this->pB1, '09:00', ScheduleSituation::Attended, '08:50', 2);

        $recent = dppProps($this, $this->userA, $this->euA)['recentPatients'];

        expect(collect($recent)->pluck('name')->all())->toBe(['AMANDA DILATANDO', 'ALICE AGUARDANDO', 'ARTHUR ATENDIDO'])
            ->and(collect($recent)->every(fn ($p) => ! array_key_exists('phone', $p) && $p['last_visit'] !== null))->toBeTrue()
            ->and($recent[0]['url'])->toBe(route('panel.patients.index', ['open' => $this->pA3->id]));
    });

    it('exames sem laudo: só os dele, na janela de 30 dias, sem os laudados (manual ou IA aprovada)', function () {
        dppExam($this->pA1, $this->doctorA);
        dppExam($this->pA2, $this->doctorA, ['created_at' => now()->subDays(30)->startOfDay()]);
        dppExam($this->pA1, $this->doctorA, ['created_at' => now()->subDays(31)]); // fora da janela
        dppExam($this->pA1, $this->doctorA, ['active' => false]);                  // desabilitado
        dppExam($this->pB1, $this->doctorB);                                        // do outro médico
        dppExam($this->pA1, null);                                                  // sem médico

        $manual = dppExam($this->pA1, $this->doctorA);
        $doc    = MedicalRecordDocumentation::query()->create([
            'medical_record_id' => dppRecord($this->entity, $this->pA1, $this->doctorA)->id,
            'patient_id'        => $this->pA1->id,
            'doctor_id'         => $this->doctorA->id,
            'type'              => DocumentationType::Report,
            'title'             => 'Laudo',
            'content'           => '<p>Normal.</p>',
        ]);
        $doc->syncExams([(string) $manual->id], $this->entity->id);

        dppAiRun($this->entity, $this->userA, AiRunStatus::Approved, ['patient_id' => $this->pA1->id], dppExam($this->pA1, $this->doctorA));

        $stats = dppProps($this, $this->userA, $this->euA)['stats'];

        expect($stats['exams_pending'])->toBe(2);
    });

    it('laudos de IA aguardando: pedidos por ele ou do caso dele; nada de outro médico, outra clínica, chat ou já aprovado', function () {
        dppGiveAi($this->entity);
        $secretary = User::factory()->create();
        createEntityUser($this->entity, $secretary, ClientRule::Secretary->value);

        $examA  = dppExam($this->pA1, $this->doctorA);
        $examB  = dppExam($this->pB1, $this->doctorB);
        $record = dppRecord($this->entity, $this->pA2, $this->doctorA);

        $mine        = dppAiRun($this->entity, $this->userA, AiRunStatus::WaitingApproval, ['patient_id' => $this->pA1->id], $examA);
        $fromMyCase  = dppAiRun($this->entity, $secretary, AiRunStatus::WaitingApproval, ['workflow' => 'record_assist', 'patient_id' => $this->pA2->id, 'medical_record_id' => $record->id]);
        $onMyExam    = dppAiRun($this->entity, $secretary, AiRunStatus::WaitingApproval, ['patient_id' => $this->pA1->id], dppExam($this->pA1, $this->doctorA));
        $otherDoctor = dppAiRun($this->entity, $this->userB, AiRunStatus::WaitingApproval, ['patient_id' => $this->pB1->id], $examB);
        dppAiRun($this->entity, $this->userA, AiRunStatus::Approved, ['patient_id' => $this->pA1->id]);
        dppAiRun($this->entity, $this->userA, AiRunStatus::WaitingApproval, ['workflow' => 'assistant_chat']);

        $otherClinic = Entity::factory()->create(['is_client' => true, 'active' => true]);
        dppAiRun($otherClinic, $this->userA, AiRunStatus::WaitingApproval);

        $ai = dppProps($this, $this->userA, $this->euA)['aiWaiting'];

        expect($ai['count'])->toBe(3)
            ->and(collect($ai['items'])->pluck('id')->sort()->values()->all())
            ->toBe(collect([$mine->id, $fromMyCase->id, $onMyExam->id])->sort()->values()->all())
            ->and(collect($ai['items'])->pluck('id'))->not->toContain($otherDoctor->id)
            ->and($ai['list_url'])->toBe(route('panel.ai-runs.index', ['status' => 'waiting_approval']));

        $items = collect($ai['items'])->keyBy('id');
        // Laudo de imagem abre o exame no Gerenciador de Imagens (onde aprova).
        expect($items[$mine->id]['url'])->toBe(route('panel.eye-images.index', ['patient_id' => $this->pA1->id, 'exam_id' => $examA->id]))
            ->and($items[$fromMyCase->id]['url'])->toBe($ai['list_url'])
            ->and($items[$mine->id]['patient'])->toBe('ALICE AGUARDANDO');

        // Médico B não vê os de A.
        $aiB = dppProps($this, $this->userB, $this->euB)['aiWaiting'];
        expect(collect($aiB['items'])->pluck('id')->all())->toBe([$otherDoctor->id]);
    });

    it('laudos de IA: sem IA no plano da clínica, o bloco nem vem (aprovar daria 403)', function () {
        dppAiRun($this->entity, $this->userA, AiRunStatus::WaitingApproval);

        expect(dppProps($this, $this->userA, $this->euA)['aiWaiting'])->toBeNull();
    });

    it('lista curta de IA: até 5 itens, mais recentes primeiro, com a contagem total', function () {
        dppGiveAi($this->entity);

        foreach (range(1, 7) as $i) {
            $run = dppAiRun($this->entity, $this->userA, AiRunStatus::WaitingApproval, ['patient_id' => $this->pA1->id]);
            DB::table('ai_runs')->where('id', $run->id)->update(['created_at' => now()->subMinutes(10 - $i)]);
            $last = $run;
        }

        $ai = dppProps($this, $this->userA, $this->euA)['aiWaiting'];

        expect($ai['count'])->toBe(7)
            ->and($ai['items'])->toHaveCount(5)
            ->and($ai['items'][0]['id'])->toBe($last->id);
    });

    it('prontuários sem assinatura: só os dele, não assinados, dos últimos 30 dias, desta clínica', function () {
        $unsigned = dppRecord($this->entity, $this->pA1, $this->doctorA);
        dppRecord($this->entity, $this->pA2, $this->doctorA, ['signed_at' => now(), 'is_locked' => true, 'signature_hash' => 'x']);
        dppRecord($this->entity, $this->pA2, $this->doctorA, ['created_at' => now()->subDays(31)]);
        dppRecord($this->entity, $this->pB1, $this->doctorB);

        $otherClinic  = Entity::factory()->create(['is_client' => true, 'active' => true]);
        $otherPatient = dppPatient($otherClinic, 'OUTRA CLINICA');
        dppRecord($otherClinic, $otherPatient, $this->doctorA);

        $records = dppProps($this, $this->userA, $this->euA)['unsignedRecords'];

        expect($records['count'])->toBe(1)
            ->and($records['days'])->toBe(30)
            ->and($records['items'])->toHaveCount(1)
            ->and($records['items'][0])->toMatchArray([
                'id'      => $unsigned->id,
                'patient' => 'ALICE AGUARDANDO',
                'url'     => route('panel.patients.medicalrecords.edit', [$this->pA1->id, $unsigned->id]),
            ]);
    });

    it('usuário médico sem cadastro de médico: aviso e nenhum dado da clínica', function () {
        dppTodaySchedules($this);
        dppGiveAi($this->entity);
        dppAiRun($this->entity, $this->userA, AiRunStatus::WaitingApproval);
        dppExam($this->pA1, $this->doctorA);
        dppRecord($this->entity, $this->pA1, $this->doctorA);

        $user       = User::factory()->create();
        $entityUser = createEntityUser($this->entity, $user, ClientRule::Doctor->value);

        $props = dppProps($this, $user, $entityUser);

        expect($props['doctorMissing'])->toBeTrue()
            ->and($props['scheduleToday'])->toBe([])
            ->and($props['recentPatients'])->toBe([])
            ->and($props['nextPatient'])->toBeNull()
            ->and($props['aiWaiting'])->toBeNull()
            ->and($props['unsignedRecords']['count'])->toBe(0)
            ->and($props['stats'])->toMatchArray(['doctor_id' => null, 'today_count' => 0, 'exams_pending' => 0, 'waiting_now' => 0])
            ->and(dppDataJson($props))->not->toContain('ALICE')->not->toContain('BIANCA');
    });

    it('médico com cadastro numa clínica não leva os dados dela para outra clínica onde não tem cadastro', function () {
        dppTodaySchedules($this);

        $otherClinic = Entity::factory()->create(['is_client' => true, 'active' => true]);
        $otherLink   = createEntityUser($otherClinic, $this->userA, ClientRule::Doctor->value);

        $props = dppProps($this, $this->userA, $otherLink);

        expect($props['doctorMissing'])->toBeTrue()
            ->and($props['scheduleToday'])->toBe([])
            ->and(dppDataJson($props))->not->toContain('ALICE');
    });
});

describe('demais perfis', function () {
    it('financeiro e usuário: sem agenda nominal nem pacientes (nomes/telefones) — só contagens', function (string $rule, array $statKeys, array $sections) {
        dppTodaySchedules($this);
        $user       = User::factory()->create();
        $entityUser = createEntityUser($this->entity, $user, $rule);

        $props = dppProps($this, $user, $entityUser);

        expect($props['sections'])->toBe($sections)
            ->and($props['scheduleToday'])->toBe([])
            ->and($props['recentPatients'])->toBe([])
            ->and($props['nextPatient'])->toBeNull()
            ->and($props['aiWaiting'])->toBeNull()
            ->and($props['unsignedRecords'])->toBeNull()
            ->and($props['stats']['today_count'])->toBe(6)
            ->and($props['stats'])->toHaveKeys($statKeys);

        $json = dppDataJson($props);

        foreach (['ALICE', 'ARTHUR', 'BIANCA', '98765', 'ANA ALVES', 'BRUNO'] as $pii) {
            expect($json)->not->toContain($pii);
        }
    })->with([
        'financeiro' => [ClientRule::Financial->value, ['total_patients', 'total_doctors'], ['finance', 'kpis', 'trends', 'shortcuts', 'stock']],
        'usuário'    => [ClientRule::User->value, ['total_patients', 'exams_pending'], ['kpis', 'shortcuts', 'stock']],
    ]);

    it('financeiro não recebe exames pendentes; usuário não recebe total de médicos', function () {
        $fin = User::factory()->create();
        $usr = User::factory()->create();

        expect(dppProps($this, $fin, createEntityUser($this->entity, $fin, ClientRule::Financial->value))['stats'])->not->toHaveKey('exams_pending')
            ->and(dppProps($this, $usr, createEntityUser($this->entity, $usr, ClientRule::User->value))['stats'])->not->toHaveKey('total_doctors');
    });

    it('secretária: agenda da clínica toda (com médico), quem chegou e pacientes recentes com telefone', function () {
        dppTodaySchedules($this);
        $user       = User::factory()->create();
        $entityUser = createEntityUser($this->entity, $user, ClientRule::Secretary->value);

        $props = dppProps($this, $user, $entityUser);

        expect($props['profile'])->toBe('secretary')
            ->and($props['sections'])->toBe(['kpis', 'agenda', 'confirmations', 'waitlist', 'birthdays', 'patients', 'shortcuts', 'stock'])
            ->and($props['scheduleToday'])->toHaveCount(6)
            ->and(collect($props['scheduleToday'])->pluck('doctor')->unique()->sort()->values()->all())->toBe(['DR. BRUNO BRAGA', 'DRA. ANA ALVES'])
            ->and($props['stats']['waiting_now'])->toBe(3)
            ->and($props['stats']['today_count'])->toBe(6)
            ->and($props['recentPatients'])->not->toBeEmpty()
            ->and($props['recentPatients'][0])->toHaveKey('phone')
            ->and($props['nextPatient'])->toBeNull()
            ->and($props['aiWaiting'])->toBeNull();
    });

    it('administrador: visão da clínica como antes (todos os indicadores e agenda de todos)', function () {
        dppTodaySchedules($this);
        $user       = User::factory()->create();
        $entityUser = createEntityUser($this->entity, $user, ClientRule::Admin->value);

        $props = dppProps($this, $user, $entityUser);

        expect($props['profile'])->toBe('admin')
            ->and($props['doctorMissing'])->toBeFalse()
            ->and($props['stats'])->toHaveKeys(['total_patients', 'total_doctors', 'today_count', 'attended_today', 'pending_today', 'cancelled_today', 'exams_pending'])
            ->and($props['stats']['total_doctors'])->toBe(2)
            ->and($props['stats']['total_patients'])->toBe(6)
            ->and($props['scheduleToday'])->toHaveCount(6)
            ->and($props['unsignedRecords'])->toBeNull();
    });

    it('polling (partial reload) do médico também só traz os dados dele', function () {
        dppTodaySchedules($this);

        $response = $this->actingAs($this->userA)
            ->withSession(panelSession($this->euA))
            ->get(route('panel.dashboard'), [
                ...inertiaHeaders(),
                'X-Inertia-Partial-Component' => 'Panel/Dashboard',
                'X-Inertia-Partial-Data'      => 'scheduleToday,nextPatient,stats',
            ])
            ->assertOk();

        $props = $response->json('props');

        expect(collect($props['scheduleToday'])->pluck('name'))->not->toContain('BIANCA DOOUTROMEDICO')
            ->and($props['nextPatient']['name'])->toBe('ALICE AGUARDANDO')
            ->and($props['stats']['today_count'])->toBe(5);
    });
});

it('agenda de hoje traz turno e hora de cada consulta, com os limites da Agenda (13h e 18h)', function () {
    $times = ['07:30', '12:59', '13:00', '17:59', '18:00', '21:15'];

    foreach ($times as $time) {
        dppSchedule($this->entity, $this->doctorA, $this->pA4, $time, ScheduleSituation::Scheduled);
    }

    $rows = collect(dppProps($this, $this->userA, $this->euA)['scheduleToday']);

    expect($rows->pluck('shift')->all())->toBe(['morning', 'morning', 'afternoon', 'afternoon', 'evening', 'evening'])
        ->and($rows->pluck('hour')->all())->toBe([7, 12, 13, 17, 18, 21])
        ->and($rows->pluck('hour_label')->all())->toBe(['07:00', '12:00', '13:00', '17:00', '18:00', '21:00']);

    // Mesma regra usada pelo filtro de turno da Agenda (SchedulesController, bout 2/3/4).
    expect(PanelDashboardController::shiftOf(12))->toBe('morning')
        ->and(PanelDashboardController::shiftOf(13))->toBe('afternoon')
        ->and(PanelDashboardController::shiftOf(18))->toBe('evening');
});

it('agenda de hoje não corta mais a tarde em 25 consultas', function () {
    foreach (range(0, 39) as $i) {
        dppSchedule($this->entity, $this->doctorA, $this->pA4, sprintf('%02d:%02d', 8 + intdiv($i * 15, 60), ($i * 15) % 60), ScheduleSituation::Scheduled);
    }

    $rows = collect(dppProps($this, $this->userA, $this->euA)['scheduleToday']);

    expect($rows)->toHaveCount(40)
        ->and($rows->where('shift', 'afternoon'))->not->toBeEmpty();
});

it('cada consulta de hoje traz o grupo do resumo, batendo com os números do dia', function () {
    dppTodaySchedules($this);
    dppSchedule($this->entity, $this->doctorA, $this->pA5, '15:00', ScheduleSituation::NoShow);

    $props  = dppProps($this, $this->userA, $this->euA);
    $groups = collect($props['scheduleToday'])->countBy('group');

    expect($groups->get('attended', 0))->toBe($props['stats']['attended_today'])
        ->and($groups->get('pending', 0))->toBe($props['stats']['pending_today'])
        ->and($groups->get('cancelled', 0))->toBe($props['stats']['cancelled_today'])
        ->and($groups->sum())->toBe($props['stats']['today_count'])
        ->and($props['stats']['cancelled_today'])->toBe(2); // cancelado + faltou

    expect(PanelDashboardController::summaryGroupOf(null))->toBe('pending');
});
