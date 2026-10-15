<?php

declare(strict_types=1);

/**
 * Tela "Retornos" (Fase 6): quem acessa, o que cada perfil enxerga, as visões da lista, a busca e as ações da
 * equipe (contato, adiar, dispensar, reabrir, "feito fora"). Relógio fixo: domingo, 01/02/2026, 10:00 — os
 * atendimentos dos cenários são datados em relação a esse dia.
 */

use App\Domains\Recall\Enums\RecallLifecycle as Life;
use App\Domains\Recall\Models\{PatientRecall, PatientRecallEvent};
use App\Enums\{ClientRule, DataAccessPurpose, Eye};
use App\Models\{Covenant, DataAccessLog, EntityUser, Patient, People, User};
use App\Support\PatientAccess;
use Carbon\Carbon;
use Illuminate\Support\Facades\{DB, Notification};
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    Notification::fake();
    $this->travelTo(Carbon::parse('2026-02-01 10:00:00'));

    ['entity' => $this->entity, 'doctor' => $this->doctor] = rcfClinic(recall: true);

    $this->doctorEu   = EntityUser::query()->findOrFail($this->doctor->entity_user_id);
    $this->doctorUser = User::query()->findOrFail($this->doctorEu->user_id);

    [$this->secretary, $this->secretaryEu] = rsMember($this->entity, ClientRule::Secretary);
    [$this->admin, $this->adminEu]         = rsMember($this->entity, ClientRule::Admin);
    [$this->financial, $this->financialEu] = rsMember($this->entity, ClientRule::Financial);
});

function rsMember($entity, ClientRule $rule): array
{
    $user = User::factory()->create();

    return [$user, createEntityUser($entity, $user, $rule->value)];
}

function rsAs(object $test, User $user, EntityUser $eu): object
{
    return $test->actingAs($user)->withSession(panelSession($eu));
}

function rsPatient($entity, string $name, ?string $birth = '1950-03-10'): Patient
{
    return Patient::create([
        'entity_id'   => $entity->id,
        'person_id'   => People::factory()->create(['full_name' => $name, 'birth_date' => $birth, 'cellphone' => '11988887777', 'whatsapp' => true])->id,
        'covenant_id' => Covenant::factory()->create()->id,
        'active'      => true,
    ]);
}

/**
 * Três cenários clássicos em 01/02/2026:
 *  - ANA   — retorno em 45 dias do atendimento de 05/01 → vence 19/02 → "a vencer";
 *  - BRUNO — 60 dias do atendimento de 01/10/2025 → venceu 30/11 → "em atraso";
 *  - CÉLIA — 30 dias do atendimento de 01/05/2025 → perdida de vista.
 */
function rsScenario($test): array
{
    $patients = [];

    foreach ([['ANA SOARES', '2026-01-05', 45], ['BRUNO LIMA', '2025-10-01', 60], ['CÉLIA ROCHA', '2025-05-01', 30]] as [$name, $date, $days]) {
        $patient = rsPatient($test->entity, $name);
        rcfRecord($test->entity, $patient, $test->doctor, $date, $days);
        rcfEvaluate($test->entity, $patient, '2026-02-01');
        $patients[$name] = $patient;
    }

    return $patients;
}

/** Props da tela. */
function rsPage(object $test, User $user, EntityUser $eu, array $query = []): array
{
    $props = null;

    rsAs($test, $user, $eu)
        ->get(route('panel.recall.index', $query))
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) use (&$props) {
            $page->component('Panel/Recall/Index');
            $props = $page->toArray()['props'];
        });

    return $props;
}

function rsNames(array $props): array
{
    return array_map(static fn (array $row) => $row['patient']['name'], $props['rows']['data']);
}

// ── acesso ──────────────────────────────────────────────────────────────────

it('admin, médico e secretária abrem a tela; o financeiro não (403); sem a feature no plano, o bloqueio do plano', function () {
    foreach ([[$this->admin, $this->adminEu], [$this->doctorUser, $this->doctorEu], [$this->secretary, $this->secretaryEu]] as [$user, $eu]) {
        rsAs($this, $user, $eu)->get(route('panel.recall.index'))->assertOk();
    }

    rsAs($this, $this->financial, $this->financialEu)->get(route('panel.recall.index'))->assertForbidden();

    ['entity' => $noPlan] = rcfClinic();
    rcfGrantRecall($noPlan, enabled: false);
    [$user, $eu] = rsMember($noPlan, ClientRule::Admin);

    rsAs($this, $user, $eu)->get(route('panel.recall.index'), ['Accept' => 'application/json'])->assertForbidden();
});

it('o item "Retornos" aparece no menu só com a feature no plano', function () {
    $nav = fn (array $props) => collect($props['nav'] ?? [])->pluck('key')->all();

    expect($nav(rsPage($this, $this->secretary, $this->secretaryEu)))->toContain('recall');

    ['entity' => $noPlan] = rcfClinic();
    rcfGrantRecall($noPlan, enabled: false);
    [$user, $eu] = rsMember($noPlan, ClientRule::Secretary);

    $props = null;
    rsAs($this, $user, $eu)->get(route('panel.schedules.index'))->assertOk()->assertInertia(function (AssertableInertia $page) use (&$props) {
        $props = $page->toArray()['props'];
    });

    expect($nav($props))->not->toContain('recall');
});

// ── a lista ─────────────────────────────────────────────────────────────────

it('a lista "Para contatar" traz a vencer e em atraso (o mais atrasado primeiro); o perdido de vista tem a própria visão', function () {
    rsScenario($this);

    $props = rsPage($this, $this->secretary, $this->secretaryEu);

    expect($props['counts'])->toMatchArray(['due_soon' => 1, 'overdue' => 1, 'lost' => 1, 'to_contact' => 2, 'scheduled' => 0, 'snoozed' => 0])
        ->and(rsNames($props))->toBe(['BRUNO LIMA', 'ANA SOARES'])
        // Idade em anos INTEIROS (nascida em 10/03/1950, em 01/02/2026: 75).
        ->and($props['rows']['data'][1]['patient']['age'])->toBe(75)
        ->and(collect($props['rows']['data'])->pluck('state')->all())->toBe(['overdue', 'due_soon'])
        ->and($props['rows']['data'][1])->toMatchArray(['due_at' => '2026-02-19', 'days_to_due' => 18, 'state_label' => 'A vencer', 'lifecycle' => 'open'])
        ->and(rsNames(rsPage($this, $this->secretary, $this->secretaryEu, ['view' => 'lost'])))->toBe(['CÉLIA ROCHA']);
});

it('visão inválida cai na padrão; busca ignora acento e caixa; origem filtra; a página respeita o limite', function () {
    rsScenario($this);

    expect(rsNames(rsPage($this, $this->secretary, $this->secretaryEu, ['view' => 'inexistente'])))->toBe(['BRUNO LIMA', 'ANA SOARES'])
        ->and(rsNames(rsPage($this, $this->secretary, $this->secretaryEu, ['search' => 'bruno'])))->toBe(['BRUNO LIMA'])
        ->and(rsNames(rsPage($this, $this->secretary, $this->secretaryEu, ['view' => 'lost', 'search' => 'celia'])))->toBe(['CÉLIA ROCHA'])
        ->and(rsNames(rsPage($this, $this->secretary, $this->secretaryEu, ['search' => 'zzz'])))->toBe([])
        ->and(rsNames(rsPage($this, $this->secretary, $this->secretaryEu, ['source' => 'doctor_order'])))->toBe(['BRUNO LIMA', 'ANA SOARES'])
        ->and(rsNames(rsPage($this, $this->secretary, $this->secretaryEu, ['source' => 'protocol'])))->toBe([]);

    $props = rsPage($this, $this->secretary, $this->secretaryEu, ['search' => '100%_\\']); // curingas do LIKE são literais
    expect($props['rows']['data'])->toBe([]);
});

it('uma linha de OUTRA clínica nunca aparece, nem nos números', function () {
    rsScenario($this);

    $other        = rcfClinic(recall: true);
    $otherPatient = rsPatient($other['entity'], 'PACIENTE DE OUTRA CLÍNICA');
    rcfRecord($other['entity'], $otherPatient, $other['doctor'], '2026-01-05', 45);
    rcfEvaluate($other['entity'], $otherPatient, '2026-02-01');

    $props = rsPage($this, $this->secretary, $this->secretaryEu);

    expect(rsNames($props))->not->toContain('PACIENTE DE OUTRA CLÍNICA')
        ->and($props['counts']['due_soon'])->toBe(1)
        ->and(json_encode($props))->not->toContain('OUTRA CLÍNICA');
});

it('paciente removido some da lista', function () {
    $patients = rsScenario($this);
    $patients['ANA SOARES']->delete();

    expect(rsNames(rsPage($this, $this->secretary, $this->secretaryEu)))->toBe(['BRUNO LIMA']);
});

it('minimização: a condição clínica só aparece para o médico; o telefone, para quem tem acesso a Pacientes', function () {
    rcfAdopt($this->entity, 'glaucoma');
    $patient = rsPatient($this->entity, 'DIANA ALVES');
    rcfCondition($this->entity, $patient, 'glaucoma', Eye::Both, '2025-08-01');
    rcfEvaluate($this->entity, $patient, '2026-02-01'); // consulta (4 meses) venceu em 01/12/2025

    $secretary = collect(rsPage($this, $this->secretary, $this->secretaryEu)['rows']['data'])->firstWhere('patient.name', 'DIANA ALVES');
    $doctor    = collect(rsPage($this, $this->doctorUser, $this->doctorEu)['rows']['data'])->firstWhere('patient.name', 'DIANA ALVES');

    expect($secretary['condition'])->toBeNull()
        ->and($secretary['task_label'])->toBeString()->not->toBe('')
        ->and($secretary['patient']['phone'])->toBe('(11) 98888-7777')
        ->and($doctor['condition'])->toBe('Glaucoma')
        ->and($doctor['eye'])->toBe('both');

    // Nada de diagnóstico na carga da secretária.
    $this->assertStringNotContainsString('Glaucoma', json_encode(rsPage($this, $this->secretary, $this->secretaryEu)['rows']));
});

it('estado vazio: configuração da clínica no `setup` (retorno do médico ligado, protocolos adotados)', function () {
    $props = rsPage($this, $this->secretary, $this->secretaryEu);

    expect($props['rows']['data'])->toBe([])
        ->and($props['setup'])->toBe(['doctor_order_enabled' => true, 'protocols' => 0])
        ->and($props['snoozeMaxDays'])->toBe(365)
        ->and(collect($props['contactOutcomes'])->pluck('value')->all())->toContain('reached', 'no_answer');
});

// ── ações ───────────────────────────────────────────────────────────────────

function rsRow(object $test, string $name = 'ANA SOARES'): PatientRecall
{
    return PatientRecall::query()->withoutGlobalScopes()->whereHas('patient', fn ($q) => $q->whereHas('person', fn ($p) => $p->where('full_name', $name)))->firstOrFail();
}

it('registrar contato: conta, guarda o resultado, grava o evento com quem fez e o acesso do ator ao dado', function () {
    rsScenario($this);
    $row = rsRow($this);

    rsAs($this, $this->secretary, $this->secretaryEu)
        ->from(route('panel.recall.index'))
        ->post(route('panel.recall.contact', $row->id), ['outcome' => 'no_answer', 'note' => '  Caixa   postal  '])
        ->assertRedirect(route('panel.recall.index'))
        ->assertSessionHas('success');

    $row->refresh();
    $event = PatientRecallEvent::query()->withoutGlobalScopes()->where('recall_id', $row->id)->where('event', 'contacted')->sole();
    $log   = DataAccessLog::query()->where('resource_id', $row->id)->sole();

    expect($row->contact_count)->toBe(1)
        ->and($row->last_contact_outcome)->toBe('no_answer')
        ->and($row->last_contact_at)->not->toBeNull()
        ->and($event->actor_user_id)->toBe((string) $this->secretary->id)
        ->and($event->meta)->toBe(['outcome' => 'no_answer', 'note' => 'Caixa postal'])
        ->and($log->purpose)->toBe(DataAccessPurpose::Administrative)
        ->and((string) $log->patient_id)->toBe((string) $row->patient_id)
        ->and((string) $log->entity_id)->toBe((string) $this->entity->id);

    rsAs($this, $this->secretary, $this->secretaryEu)
        ->post(route('panel.recall.contact', $row->id), ['outcome' => 'reached']);

    expect($row->fresh()->contact_count)->toBe(2);
});

it('contato exige um resultado válido e a observação tem teto', function () {
    rsScenario($this);
    $row = rsRow($this);

    foreach ([['outcome' => ''], ['outcome' => 'inventado'], ['outcome' => 'reached', 'note' => str_repeat('a', 201)]] as $body) {
        rsAs($this, $this->secretary, $this->secretaryEu)->post(route('panel.recall.contact', $row->id), $body)->assertSessionHasErrors();
    }

    expect($row->fresh()->contact_count)->toBe(0);
});

it('adiar: de amanhã a um ano; volta sozinho no dia; datas fora da janela são recusadas', function () {
    rsScenario($this);
    $row = rsRow($this);

    foreach (['2026-02-01', '2026-01-15', '2027-02-02', 'amanhã'] as $until) {
        rsAs($this, $this->secretary, $this->secretaryEu)->post(route('panel.recall.snooze', $row->id), ['until' => $until])->assertSessionHasErrors('until');
    }

    rsAs($this, $this->secretary, $this->secretaryEu)->post(route('panel.recall.snooze', $row->id), ['until' => '2026-03-01', 'reason' => 'Viajando'])->assertSessionHas('success');

    expect($row->fresh())->lifecycle->toBe(Life::Snoozed)->snoozed_until->toDateString()->toBe('2026-03-01')
        ->and(rsNames(rsPage($this, $this->secretary, $this->secretaryEu)))->not->toContain('ANA SOARES')
        ->and(rsNames(rsPage($this, $this->secretary, $this->secretaryEu, ['view' => 'snoozed'])))->toBe(['ANA SOARES']);

    $this->travelTo(Carbon::parse('2026-03-01 08:00:00'));
    rcfEvaluate($this->entity, $row->patient, '2026-03-01');

    expect($row->fresh()->lifecycle)->toBe(Life::Open);
});

it('dispensar exige o motivo; a linha sai da lista e aparece no histórico; reabrir devolve', function () {
    rsScenario($this);
    $row = rsRow($this);

    rsAs($this, $this->secretary, $this->secretaryEu)->post(route('panel.recall.dismiss', $row->id), ['reason' => ''])->assertSessionHasErrors('reason');
    rsAs($this, $this->secretary, $this->secretaryEu)->post(route('panel.recall.dismiss', $row->id), ['reason' => 'Foi morar em outro estado'])->assertSessionHas('success');

    expect($row->fresh())->lifecycle->toBe(Life::Dismissed)->dismissed_reason->toBe('Foi morar em outro estado')
        ->and(rsNames(rsPage($this, $this->secretary, $this->secretaryEu)))->not->toContain('ANA SOARES')
        ->and(rsNames(rsPage($this, $this->secretary, $this->secretaryEu, ['view' => 'history'])))->toBe(['ANA SOARES']);

    rsAs($this, $this->secretary, $this->secretaryEu)->post(route('panel.recall.reopen', $row->id))->assertSessionHas('success');

    expect($row->fresh())->lifecycle->toBe(Life::Open)->dismissed_reason->toBeNull()
        ->and(rsNames(rsPage($this, $this->secretary, $this->secretaryEu)))->toContain('ANA SOARES');
});

it('"feito fora": re-ancora o retorno (reavalia na hora); data futura ou anterior ao início é recusada', function () {
    rsScenario($this);
    $row = rsRow($this, 'BRUNO LIMA'); // atendimento em 01/10/2025, retorno em 60 dias

    foreach (['2026-02-02', 'ontem'] as $date) {
        rsAs($this, $this->secretary, $this->secretaryEu)->post(route('panel.recall.done-elsewhere', $row->id), ['date' => $date])->assertSessionHasErrors('date');
    }

    // Antes do início do acompanhamento (01/10/2025): a regra de negócio recusa, com aviso.
    rsAs($this, $this->secretary, $this->secretaryEu)->post(route('panel.recall.done-elsewhere', $row->id), ['date' => '2025-09-01'])->assertSessionHas('error');

    expect($row->fresh())->external_done_at->toBeNull();

    rsAs($this, $this->secretary, $this->secretaryEu)->post(route('panel.recall.done-elsewhere', $row->id), ['date' => '2026-01-20'])->assertSessionHas('success');

    // Voltou em outro serviço em 20/01: o retorno pedido no atendimento de 01/10 foi cumprido (encerra).
    expect($row->fresh())->lifecycle->toBe(Life::Closed)->closed_reason->toBe('done_elsewhere')
        ->and(rsNames(rsPage($this, $this->secretary, $this->secretaryEu)))->not->toContain('BRUNO LIMA');
});

it('retorno de OUTRA clínica, id inexistente ou id mal formado: nada acontece', function () {
    rsScenario($this);
    $other        = rcfClinic(recall: true);
    $otherPatient = rsPatient($other['entity'], 'ESTRANHA');
    rcfRecord($other['entity'], $otherPatient, $other['doctor'], '2026-01-05', 45);
    rcfEvaluate($other['entity'], $otherPatient, '2026-02-01');
    $foreign = PatientRecall::query()->withoutGlobalScopes()->where('patient_id', $otherPatient->id)->sole();

    rsAs($this, $this->secretary, $this->secretaryEu)
        ->post(route('panel.recall.dismiss', $foreign->id), ['reason' => 'Tentando mexer na clínica vizinha'])
        ->assertSessionHas('error');

    expect($foreign->fresh()->lifecycle)->toBe(Life::Open);

    rsAs($this, $this->secretary, $this->secretaryEu)->post(route('panel.recall.reopen', '01a00000-0000-7000-8000-000000000000'))->assertSessionHas('error');
    rsAs($this, $this->secretary, $this->secretaryEu)->post('/panel/recall/nao-e-uuid/reopen')->assertNotFound();
});

it('a linha mudou entre o clique e o envio (outra pessoa tratou): recusa com aviso, sem sobrescrever', function () {
    rsScenario($this);
    $row = rsRow($this);

    rsAs($this, $this->admin, $this->adminEu)->post(route('panel.recall.dismiss', $row->id), ['reason' => 'Primeira pessoa dispensou']);
    rsAs($this, $this->secretary, $this->secretaryEu)->post(route('panel.recall.dismiss', $row->id), ['reason' => 'Segunda pessoa, atrasada'])->assertSessionHas('error');

    expect($row->fresh()->dismissed_reason)->toBe('Primeira pessoa dispensou');
});

it('regra suspensa: nenhuma ação (a EasyEye está em dúvida sobre ela)', function () {
    rsScenario($this);
    $row = rsRow($this);
    $row->forceFill(['rule_suspended' => true])->save();

    rsAs($this, $this->secretary, $this->secretaryEu)->post(route('panel.recall.contact', $row->id), ['outcome' => 'reached'])->assertSessionHas('error');

    expect($row->fresh()->contact_count)->toBe(0)
        ->and(rsNames(rsPage($this, $this->secretary, $this->secretaryEu)))->not->toContain('ANA SOARES')
        ->and(rsNames(rsPage($this, $this->secretary, $this->secretaryEu, ['view' => 'suspended'])))->toBe(['ANA SOARES']);
});

it('financeiro e usuário de outra clínica não executam nenhuma ação', function () {
    rsScenario($this);
    $row = rsRow($this);

    rsAs($this, $this->financial, $this->financialEu)->post(route('panel.recall.contact', $row->id), ['outcome' => 'reached'])->assertForbidden();

    $other       = rcfClinic(recall: true);
    [$user, $eu] = rsMember($other['entity'], ClientRule::Admin);
    rsAs($this, $user, $eu)->post(route('panel.recall.dismiss', $row->id), ['reason' => 'Clínica errada, mas com a feature'])->assertSessionHas('error');

    expect($row->fresh())->lifecycle->toBe(Life::Open)->contact_count->toBe(0);
});

it('a ação não depende da tela: a sequência contato → adiar → reabrir deixa a linha coerente e os eventos em ordem', function () {
    rsScenario($this);
    $row = rsRow($this);

    rsAs($this, $this->secretary, $this->secretaryEu)->post(route('panel.recall.contact', $row->id), ['outcome' => 'will_schedule']);
    rsAs($this, $this->secretary, $this->secretaryEu)->post(route('panel.recall.snooze', $row->id), ['until' => '2026-02-20']);
    rsAs($this, $this->secretary, $this->secretaryEu)->post(route('panel.recall.reopen', $row->id));

    expect(PatientRecallEvent::query()->withoutGlobalScopes()->where('recall_id', $row->id)->orderBy('created_at')->orderBy('id')->pluck('event')->all())
        ->toBe(['calculated', 'contacted', 'snoozed', 'reopened'])
        ->and(DB::table('data_access_logs')->where('resource_id', $row->id)->count())->toBe(3);
});

// ── Dashboard ───────────────────────────────────────────────────────────────

function rsDashboardStats(object $test, User $user, EntityUser $eu): array
{
    $stats = null;

    rsAs($test, $user, $eu)->get(route('panel.dashboard'))->assertOk()->assertInertia(function (AssertableInertia $page) use (&$stats) {
        $stats = $page->toArray()['props']['stats'];
    });

    return $stats;
}

it('o Dashboard traz só a CONTAGEM dos retornos (admin, médico, secretária) e só com a feature no plano', function () {
    rsScenario($this);

    foreach ([[$this->secretary, $this->secretaryEu], [$this->admin, $this->adminEu], [$this->doctorUser, $this->doctorEu]] as [$user, $eu]) {
        $stats = rsDashboardStats($this, $user, $eu);

        expect($stats['recall_to_contact'])->toBe(2)
            ->and($stats['recall_lost'])->toBe(1)
            ->and(json_encode($stats))->not->toContain('ANA SOARES')
            ->and(json_encode($stats))->not->toContain('BRUNO');
    }

    expect(rsDashboardStats($this, $this->financial, $this->financialEu))->not->toHaveKey('recall_to_contact');

    ['entity' => $noPlan] = rcfClinic();
    rcfGrantRecall($noPlan, enabled: false);
    [$user, $eu] = rsMember($noPlan, ClientRule::Admin);

    expect(rsDashboardStats($this, $user, $eu))->not->toHaveKey('recall_to_contact');
});

// ── fronteiras ──────────────────────────────────────────────────────────────

it('fronteiras do prazo (primeiro dia inclusivo): entra no dia do convite, é "Vencido" no dia do vencimento, "Em atraso" depois da tolerância', function () {
    $cases = [
        'ENTRA HOJE'   => ['2026-01-02', 60, 'due_soon'],  // vence 03/03, convite a partir de 01/02 (hoje)
        'ENTRA AMANHA' => ['2026-01-03', 60, null],        // vence 04/03, convite só a partir de 02/02
        'VENCE HOJE'   => ['2025-12-03', 60, 'due'],       // vence 01/02
        'ATRASO'       => ['2025-12-02', 60, 'due'],       // venceu ontem: ainda dentro da tolerância de 30 dias
        'TOLERANCIA'   => ['2025-11-02', 60, 'overdue'],   // venceu 02/01: 30 dias depois, a tolerância acabou
    ];

    foreach ($cases as $name => [$date, $days]) {
        $patient = rsPatient($this->entity, $name);
        rcfRecord($this->entity, $patient, $this->doctor, $date, $days);
        rcfEvaluate($this->entity, $patient, '2026-02-01');
    }

    $rows = collect(rsPage($this, $this->secretary, $this->secretaryEu)['rows']['data'])->keyBy(fn ($row) => $row['patient']['name']);

    expect($rows->keys()->sort()->values()->all())->toBe(['ATRASO', 'ENTRA HOJE', 'TOLERANCIA', 'VENCE HOJE'])
        ->and($rows['ENTRA HOJE']['state'])->toBe('due_soon')
        ->and($rows['VENCE HOJE']['state'])->toBe('due')
        ->and($rows['ATRASO']['state'])->toBe('due')
        ->and($rows['TOLERANCIA']['state'])->toBe('overdue');
});

it('o histórico mostra só o que mudou nos últimos 90 dias', function () {
    $patients = rsScenario($this);

    $recent = rsRow($this, 'ANA SOARES');
    $old    = rsRow($this, 'BRUNO LIMA');

    $recent->forceFill(['lifecycle' => Life::Dismissed->value, 'dismissed_reason' => 'Recente'])->save();
    $old->forceFill(['lifecycle' => Life::Dismissed->value, 'dismissed_reason' => 'Antigo'])->save();
    PatientRecall::query()->withoutGlobalScopes()->whereKey($old->id)->toBase()->update(['updated_at' => now()->subDays(100)]);

    expect(rsNames(rsPage($this, $this->secretary, $this->secretaryEu, ['view' => 'history'])))->toBe(['ANA SOARES'])
        ->and($patients)->not->toBeEmpty();
});

it('"Agendados" e "Para contatar" não se misturam: com agendamento aberto, a linha sai de "Para contatar"', function () {
    $patients = rsScenario($this);
    createScheduleForEntity($this->entity, ['patient_id' => $patients['ANA SOARES']->id, 'date_time' => '2026-02-10 09:00:00']);
    rcfEvaluate($this->entity, $patients['ANA SOARES'], '2026-02-01');

    $props = rsPage($this, $this->secretary, $this->secretaryEu);

    expect(rsNames($props))->toBe(['BRUNO LIMA'])
        ->and($props['counts'])->toMatchArray(['scheduled' => 1, 'due_soon' => 0, 'overdue' => 1, 'to_contact' => 1])
        ->and(rsNames(rsPage($this, $this->secretary, $this->secretaryEu, ['view' => 'scheduled'])))->toBe(['ANA SOARES']);
});

it('fronteira do "perdido de vista": no dia em que o prazo acaba deixa de ser "Para contatar"; na véspera ainda é', function () {
    // Perdido de vista = vencimento + 180 dias + 1: vence 04/08/2025 → perdido a partir de 01/02/2026 (hoje).
    $cases = ['PERDIDO HOJE' => ['2025-06-05', 60], 'PERDE AMANHA' => ['2025-06-06', 60]];

    foreach ($cases as $name => [$date, $days]) {
        $patient = rsPatient($this->entity, $name);
        rcfRecord($this->entity, $patient, $this->doctor, $date, $days);
        rcfEvaluate($this->entity, $patient, '2026-02-01');
    }

    expect(rsNames(rsPage($this, $this->secretary, $this->secretaryEu)))->toBe(['PERDE AMANHA'])
        ->and(rsNames(rsPage($this, $this->secretary, $this->secretaryEu, ['view' => 'lost'])))->toBe(['PERDIDO HOJE']);
});

it('regra suspensa não entra nos números de adiados nem nos de agendados', function () {
    $patients = rsScenario($this);

    $snoozed = rsRow($this, 'ANA SOARES');
    $snoozed->forceFill(['lifecycle' => Life::Snoozed->value, 'snoozed_until' => '2026-03-01', 'rule_suspended' => true])->save();

    $props = rsPage($this, $this->secretary, $this->secretaryEu);

    expect($props['counts']['snoozed'])->toBe(0)
        ->and($props['counts']['suspended'])->toBe(1)
        ->and($patients)->not->toBeEmpty();
});

it('sem acesso a Pacientes (regra trocada no contêiner) o telefone não vai na lista nem no prefill da Agenda', function () {
    rsScenario($this);

    $this->app->bind(PatientAccess::class, fn () => new class() extends PatientAccess {
        public function current(): bool
        {
            return false;
        }
    });

    $props = rsPage($this, $this->secretary, $this->secretaryEu);

    expect($props['phonesVisible'])->toBeFalse()
        ->and(collect($props['rows']['data'])->pluck('patient.phone')->filter()->all())->toBe([])
        ->and(json_encode($props['rows']))->not->toContain('98888-7777');

    $recall = rsRow($this);
    $agenda = null;
    rsAs($this, $this->secretary, $this->secretaryEu)->get(route('panel.schedules.index', ['recall' => $recall->id, 'new' => 1]))->assertOk()
        ->assertInertia(function (AssertableInertia $page) use (&$agenda) {
            $agenda = $page->toArray()['props']['recallPrefill'];
        });

    expect($agenda['cellphone'])->toBeNull()->and($agenda['telephone'])->toBeNull()->and($agenda['full_name'])->toBe('ANA SOARES');
});
