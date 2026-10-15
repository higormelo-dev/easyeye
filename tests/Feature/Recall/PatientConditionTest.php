<?php

declare(strict_types=1);

/**
 * As condições crônicas do paciente nas mãos do MÉDICO (ADR 0002, item 8): registrar à mão, corrigir, confirmar,
 * descartar e dar alta — cada ato sob a trava do paciente, reavaliando o retorno na hora; e a gaveta do cadastro
 * (JSON) que só o médico com cadastro ativo lê e altera. Relógio fixo: 01/02/2026 (parâmetro do serviço).
 */

use App\Domains\Recall\Enums\{ConditionOrigin, ConditionStatus, RecallLifecycle};
use App\Domains\Recall\Exceptions\PatientConditionException as Refused;
use App\Domains\Recall\Models\{PatientCondition, PatientRecall};
use App\Domains\Recall\Services\{PatientConditionService, ProtocolGovernanceService};
use App\Enums\{ClientRule, DataAccessPurpose, Eye};
use App\Models\{DataAccessLog, EntityUser, User};
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\{DB, Notification};

beforeEach(function () {
    Notification::fake();

    ['entity' => $this->entity, 'doctor' => $this->doctor] = rcfClinic(recall: true);

    $this->doctorEu   = EntityUser::query()->findOrFail($this->doctor->entity_user_id);
    $this->doctorUser = User::query()->findOrFail($this->doctorEu->user_id);
    $this->patient    = rcfPatient($this->entity);
    $this->service    = app(PatientConditionService::class);
    $this->today      = CarbonImmutable::parse('2026-02-01');

    foreach (['secretary' => ClientRule::Secretary, 'admin' => ClientRule::Admin, 'financial' => ClientRule::Financial] as $name => $rule) {
        $user                 = User::factory()->create();
        $this->{$name}        = $user;
        $this->{$name . 'Eu'} = createEntityUser($this->entity, $user, $rule->value);
    }
});

function pcnRefused(callable $call): ?Refused
{
    try {
        $call();
    } catch (Refused $e) {
        return $e;
    }

    return null;
}

function pcnAs(object $test, User $user, EntityUser $eu): object
{
    return $test->actingAs($user)->withSession(panelSession($eu));
}

function pcnUrl(object $test, string $name, ?string $conditionId = null, ?object $patient = null): string
{
    return route("panel.patients.recall-conditions.{$name}", array_filter([$patient?->id ?? $test->patient->id, $conditionId]));
}

/** Uma condição que o SISTEMA detectou e ainda ninguém confirmou (CID forte em prontuário editável → sugerida). */
function pcnSuggested(object $test, string $date = '2026-01-10'): PatientCondition
{
    rcfAdopt($test->entity, 'glaucoma');
    rcfRecord($test->entity, $test->patient, $test->doctor, $date, cids: ['H40.1'], signed: false);
    rcfEvaluate($test->entity, $test->patient);

    return PatientCondition::query()->withoutGlobalScopes()->where('patient_id', $test->patient->id)->sole();
}

// ── registrar à mão ─────────────────────────────────────────────────────────

it('registrar à mão: condição ATIVA do médico, confirmada por ele, e o retorno já nasce (mesma transação)', function () {
    rcfAdopt($this->entity, 'glaucoma');
    rcfRecord($this->entity, $this->patient, $this->doctor, '2026-01-10');

    $row = $this->service->add((string) $this->entity->id, (string) $this->patient->id, 'glaucoma', Eye::Right, CarbonImmutable::parse('2025-12-20'), $this->doctorUser, $this->today);

    expect($row->status)->toBe(ConditionStatus::Active)
        ->and($row->origin)->toBe(ConditionOrigin::Manual)
        ->and($row->eye)->toBe(Eye::Right)
        ->and($row->started_on->toDateString())->toBe('2025-12-20')
        ->and($row->confirmed_by)->toBe((string) $this->doctorUser->id)
        ->and($row->confirmed_at)->not->toBeNull()
        ->and(rcfRecalls($this->patient)->keys()->all())->toContain('consulta', 'campimetria');
});

it('registrar à mão: sem início vale (o retorno fica "falta informação"); início futuro, antes de 1900 e protocolo parado, não', function () {
    $adoption = rcfAdopt($this->entity, 'glaucoma');

    $noStart = $this->service->add((string) $this->entity->id, (string) $this->patient->id, 'glaucoma', Eye::Both, null, $this->doctorUser, $this->today);
    expect($noStart->started_on)->toBeNull()
        ->and(rcfRecalls($this->patient)->first()->lifecycle)->toBe(RecallLifecycle::NeedsInfo);

    $this->service->discharge((string) $this->entity->id, (string) $noStart->id, 'Teste: libera para registrar de novo', $this->doctorUser, $this->today);

    foreach ([['2026-02-02', 'futuro'], ['1899-12-31', 'antes de 1900']] as [$date, $label]) {
        expect(pcnRefused(fn () => $this->service->add((string) $this->entity->id, (string) $this->patient->id, 'glaucoma', Eye::Both, CarbonImmutable::parse($date), $this->doctorUser, $this->today))?->reason())
            ->toBe(Refused::INVALID_DATE, $label);
    }

    // Hoje e 1900-01-01 são os limites que valem.
    expect(pcnRefused(fn () => $this->service->add((string) $this->entity->id, (string) $this->patient->id, 'glaucoma', Eye::Left, CarbonImmutable::parse('2026-02-01'), $this->doctorUser, $this->today)))->toBeNull();
    expect(pcnRefused(fn () => $this->service->add((string) $this->entity->id, (string) $this->patient->id, 'glaucoma', Eye::Right, CarbonImmutable::parse('1900-01-01'), $this->doctorUser, $this->today)))->toBeNull();

    // Protocolo que a clínica não usa (nunca adotado) ou que está parado (versão suspensa).
    expect(pcnRefused(fn () => $this->service->add((string) $this->entity->id, (string) $this->patient->id, 'dmri', Eye::Both, null, $this->doctorUser, $this->today))?->reason())->toBe(Refused::PROTOCOL_NOT_ACTIVE);

    app(ProtocolGovernanceService::class)->suspend($adoption->version->fresh(), 'Dúvida clínica sobre o intervalo da campimetria.');
    $other = rcfPatient($this->entity);

    expect(pcnRefused(fn () => $this->service->add((string) $this->entity->id, (string) $other->id, 'glaucoma', Eye::Both, null, $this->doctorUser, $this->today))?->reason())->toBe(Refused::PROTOCOL_NOT_ACTIVE);
});

it('duas condições em aberto do mesmo protocolo não cobrem o mesmo olho: AO vale por todos', function () {
    rcfAdopt($this->entity, 'glaucoma');
    $add = fn (Eye $eye) => pcnRefused(fn () => $this->service->add((string) $this->entity->id, (string) $this->patient->id, 'glaucoma', $eye, null, $this->doctorUser, $this->today))?->reason();

    expect($add(Eye::Right))->toBeNull()
        ->and($add(Eye::Right))->toBe(Refused::ALREADY_OPEN) // o mesmo olho
        ->and($add(Eye::Both))->toBe(Refused::ALREADY_OPEN)  // AO cobre o OD já registrado
        ->and($add(Eye::Left))->toBeNull();                  // o outro olho é livre

    // Outro protocolo no mesmo paciente não conta; e AO já aberto cobre qualquer olho (o contrário do caso acima).
    rcfAdopt($this->entity, 'dmri');
    $addDmri = fn (Eye $eye) => pcnRefused(fn () => $this->service->add((string) $this->entity->id, (string) $this->patient->id, 'dmri', $eye, null, $this->doctorUser, $this->today))?->reason();

    expect($addDmri(Eye::Both))->toBeNull()
        ->and($addDmri(Eye::Right))->toBe(Refused::ALREADY_OPEN)
        ->and($addDmri(Eye::Left))->toBe(Refused::ALREADY_OPEN);
});

it('paciente de outra clínica ou removido: "não encontrado", nada é gravado', function () {
    rcfAdopt($this->entity, 'glaucoma');
    $foreign = rcfPatient(rcfClinic(recall: true)['entity']);

    expect(pcnRefused(fn () => $this->service->add((string) $this->entity->id, (string) $foreign->id, 'glaucoma', Eye::Both, null, $this->doctorUser, $this->today))?->reason())->toBe(Refused::NOT_FOUND);

    DB::table('patients')->where('id', $this->patient->id)->update(['deleted_at' => now()]);

    expect(pcnRefused(fn () => $this->service->add((string) $this->entity->id, (string) $this->patient->id, 'glaucoma', Eye::Both, null, $this->doctorUser, $this->today))?->reason())->toBe(Refused::NOT_FOUND)
        ->and(PatientCondition::query()->withoutGlobalScopes()->count())->toBe(0);
});

// ── confirmar / descartar ───────────────────────────────────────────────────

it('confirmar: sugerida vira ativa (quem e quando) e SÓ agora o protocolo gera retorno', function () {
    $suggested = pcnSuggested($this);

    expect($suggested->status)->toBe(ConditionStatus::Suggested)
        ->and(rcfRecalls($this->patient)->keys()->all())->not->toContain('consulta'); // sugerida não gera retorno de protocolo

    $row = $this->service->confirm((string) $this->entity->id, (string) $suggested->id, $this->doctorUser, $this->today);

    expect($row->status)->toBe(ConditionStatus::Active)
        ->and($row->origin)->toBe(ConditionOrigin::Cid) // a origem continua sendo a detecção
        ->and($row->confirmed_by)->toBe((string) $this->doctorUser->id)
        ->and($row->confirmed_at)->not->toBeNull()
        ->and(rcfRecalls($this->patient)->keys()->all())->toContain('consulta', 'campimetria');

    // Já decidida: confirmar de novo (ou descartar) é recusado, sem mexer em nada.
    expect(pcnRefused(fn () => $this->service->confirm((string) $this->entity->id, (string) $suggested->id, $this->doctorUser, $this->today))?->reason())->toBe(Refused::NOT_SUGGESTED)
        ->and(pcnRefused(fn () => $this->service->reject((string) $this->entity->id, (string) $suggested->id, $this->doctorUser, $this->today))?->reason())->toBe(Refused::NOT_SUGGESTED)
        ->and($suggested->fresh()->status)->toBe(ConditionStatus::Active);
});

it('descartar: a sugestão fica descartada para sempre — o detector não a traz de volta', function () {
    $suggested = pcnSuggested($this);

    $row = $this->service->reject((string) $this->entity->id, (string) $suggested->id, $this->doctorUser, $this->today);

    expect($row->status)->toBe(ConditionStatus::Rejected)
        ->and($row->rejected_by)->toBe((string) $this->doctorUser->id)
        ->and($row->rejected_at)->not->toBeNull();

    // Prontuário novo com o mesmo CID, assinado, e reavaliação: continua descartada, nada novo nasce.
    rcfRecord($this->entity, $this->patient, $this->doctor, '2026-01-20', cids: ['H40.1'], signed: true);
    rcfEvaluate($this->entity, $this->patient);

    expect(PatientCondition::query()->withoutGlobalScopes()->where('patient_id', $this->patient->id)->count())->toBe(1)
        ->and($suggested->fresh()->status)->toBe(ConditionStatus::Rejected);
});

// ── alta ────────────────────────────────────────────────────────────────────

it('alta: só de condição ATIVA, com motivo; os retornos dela se encerram', function () {
    rcfAdopt($this->entity, 'glaucoma');
    rcfRecord($this->entity, $this->patient, $this->doctor, '2026-01-10', cids: ['H40.1'], signed: true);
    rcfEvaluate($this->entity, $this->patient);

    $active = PatientCondition::query()->withoutGlobalScopes()->where('patient_id', $this->patient->id)->sole();
    expect($active->status)->toBe(ConditionStatus::Active)
        ->and(rcfRecalls($this->patient)->keys()->all())->toContain('consulta');

    // Motivo curto (depois de juntar espaços) é recusado e nada muda.
    expect(pcnRefused(fn () => $this->service->discharge((string) $this->entity->id, (string) $active->id, " a \n ", $this->doctorUser, $this->today))?->reason())->toBe(Refused::REASON_REQUIRED)
        ->and($active->fresh()->status)->toBe(ConditionStatus::Active);

    // "a b" tem exatamente 3 caracteres: é o mínimo e vale (aparar e juntar espaços antes de contar).
    $row = $this->service->discharge((string) $this->entity->id, (string) $active->id, "  a\n b  ", $this->doctorUser, $this->today);

    expect($row->status)->toBe(ConditionStatus::Discharged)
        ->and($row->discharge_reason)->toBe('a b')
        ->and($row->discharged_by)->toBe((string) $this->doctorUser->id)
        ->and($row->discharged_at)->not->toBeNull();

    $consulta = PatientRecall::query()->withoutGlobalScopes()->where('patient_id', $this->patient->id)->where('task_key', 'consulta')->sole();
    expect($consulta->lifecycle)->toBe(RecallLifecycle::Closed)
        ->and($consulta->closed_reason)->toBe('condition_ended');

    // Só ativa recebe alta: a sugerida precisa ser confirmada ou descartada antes; a que já teve alta, não de novo.
    $other     = rcfPatient($this->entity);
    $suggested = PatientCondition::query()->withoutGlobalScopes()->create([
        'entity_id' => $this->entity->id, 'patient_id' => $other->id, 'protocol_key' => 'glaucoma', 'eye' => 'both',
        'status'    => ConditionStatus::Suggested->value, 'origin' => ConditionOrigin::Cid->value,
    ]);

    expect(pcnRefused(fn () => $this->service->discharge((string) $this->entity->id, (string) $suggested->id, 'Motivo qualquer', $this->doctorUser, $this->today))?->reason())->toBe(Refused::NOT_ACTIVE)
        ->and(pcnRefused(fn () => $this->service->discharge((string) $this->entity->id, (string) $active->id, 'Motivo qualquer', $this->doctorUser, $this->today))?->reason())->toBe(Refused::NOT_ACTIVE);
});

// ── corrigir ────────────────────────────────────────────────────────────────

it('corrigir olho e início: a condição passa a ser DO MÉDICO (o detector não a toca) e o retorno é recalculado', function () {
    $suggested = pcnSuggested($this);

    $row = $this->service->update((string) $this->entity->id, (string) $suggested->id, Eye::Left, CarbonImmutable::parse('2025-06-01'), $this->today);

    expect($row->eye)->toBe(Eye::Left)
        ->and($row->started_on->toDateString())->toBe('2025-06-01')
        ->and($row->origin)->toBe(ConditionOrigin::Manual)
        ->and($row->status)->toBe(ConditionStatus::Suggested)     // corrigir não confirma
        ->and($row->evidence['code'])->toBe('H401');              // a evidência continua guardada

    // Reavaliar com prontuário novo assinado: o detector não mexe no que o médico corrigiu.
    rcfRecord($this->entity, $this->patient, $this->doctor, '2025-01-05', cids: ['H40.1'], signed: true);
    rcfEvaluate($this->entity, $this->patient);

    $again = $row->fresh();
    expect($again->eye)->toBe(Eye::Left)
        ->and($again->started_on->toDateString())->toBe('2025-06-01')
        ->and($again->status)->toBe(ConditionStatus::Suggested);
});

it('corrigir: só condição em aberto; não pode passar a cobrir um olho que outra condição já cobre; o próprio olho vale', function () {
    rcfAdopt($this->entity, 'glaucoma');
    $right = $this->service->add((string) $this->entity->id, (string) $this->patient->id, 'glaucoma', Eye::Right, null, $this->doctorUser, $this->today);
    $left  = $this->service->add((string) $this->entity->id, (string) $this->patient->id, 'glaucoma', Eye::Left, null, $this->doctorUser, $this->today);

    expect(pcnRefused(fn () => $this->service->update((string) $this->entity->id, (string) $left->id, Eye::Right, null, $this->today))?->reason())->toBe(Refused::ALREADY_OPEN)
        ->and(pcnRefused(fn () => $this->service->update((string) $this->entity->id, (string) $left->id, Eye::Both, null, $this->today))?->reason())->toBe(Refused::ALREADY_OPEN)
        // O próprio olho (só mudar o início) não conflita consigo mesma.
        ->and(pcnRefused(fn () => $this->service->update((string) $this->entity->id, (string) $left->id, Eye::Left, CarbonImmutable::parse('2025-11-11'), $this->today)))->toBeNull()
        ->and(pcnRefused(fn () => $this->service->update((string) $this->entity->id, (string) $left->id, Eye::Left, CarbonImmutable::parse('2026-03-01'), $this->today))?->reason())->toBe(Refused::INVALID_DATE);

    $this->service->discharge((string) $this->entity->id, (string) $right->id, 'Alta do olho direito, controlado.', $this->doctorUser, $this->today);

    expect(pcnRefused(fn () => $this->service->update((string) $this->entity->id, (string) $right->id, Eye::Right, null, $this->today))?->reason())->toBe(Refused::NOT_OPEN);
});

it('condição de OUTRA clínica: "não encontrada" em qualquer ato, nada muda', function () {
    $suggested = pcnSuggested($this);
    $other     = rcfClinic(recall: true)['entity'];
    $entityId  = (string) $other->id;
    $id        = (string) $suggested->id;

    foreach ([
        fn () => $this->service->confirm($entityId, $id, $this->doctorUser, $this->today),
        fn () => $this->service->reject($entityId, $id, $this->doctorUser, $this->today),
        fn () => $this->service->discharge($entityId, $id, 'Motivo qualquer', $this->doctorUser, $this->today),
        fn () => $this->service->update($entityId, $id, Eye::Right, null, $this->today),
    ] as $call) {
        expect(pcnRefused($call)?->reason())->toBe(Refused::NOT_FOUND);
    }

    expect($suggested->fresh()->status)->toBe(ConditionStatus::Suggested)
        ->and($suggested->fresh()->eye)->toBe(Eye::Both);
});

it('cada ato deixa a trilha de auditoria da condição (Auditable) e marca o paciente para reavaliação', function () {
    $suggested = pcnSuggested($this);
    DB::table('recall_dirty_patients')->delete();

    $this->service->confirm((string) $this->entity->id, (string) $suggested->id, $this->doctorUser, $this->today);

    expect(DB::table('audit_logs')->where('auditable_id', $suggested->id)->count())->toBeGreaterThanOrEqual(1)
        ->and(DB::table('recall_dirty_patients')->where('patient_id', $this->patient->id)->count())->toBe(1);
});

// ── a gaveta do cadastro (HTTP) ─────────────────────────────────────────────

it('GET: o médico vê as condições com a evidência em UMA frase (sem id de prontuário), os retornos que ela gera e os protocolos da clínica', function () {
    $this->travelTo('2026-02-01 10:00:00');

    rcfAdopt($this->entity, 'glaucoma');
    rcfAdopt($this->entity, 'dmri');
    $record = rcfRecord($this->entity, $this->patient, $this->doctor, '2026-01-10', cids: ['H40.1'], signed: true);
    rcfRecord($this->entity, $this->patient, $this->doctor, '2026-01-12', cids: ['H35.3'], signed: false);
    rcfEvaluate($this->entity, $this->patient);

    $response = pcnAs($this, $this->doctorUser, $this->doctorEu)->getJson(pcnUrl($this, 'index'))->assertOk();
    $data     = $response->json('data');
    $byKey    = collect($data['conditions'])->keyBy('protocol_key');

    expect($byKey->keys()->all())->toEqualCanonicalizing(['glaucoma', 'dmri'])
        // Sugeridas primeiro (o que pede decisão), depois as ativas.
        ->and($data['conditions'][0]['status'])->toBe('suggested')
        ->and($byKey['glaucoma'])->toMatchArray([
            'title'           => 'Glaucoma',
            'eye'             => 'both',
            'status'          => 'active',
            'origin'          => 'cid',
            'started_on'      => '2026-01-10',
            'evidence_text'   => 'CID H40.1 em prontuário assinado (desde 10/01/2026)',
            'protocol_active' => true,
        ])
        ->and($byKey['glaucoma']['can'])->toBe(['confirm' => false, 'reject' => false, 'discharge' => true, 'edit' => true])
        ->and(collect($byKey['glaucoma']['next'])->pluck('label')->all())->toContain('Consulta de retorno')
        ->and($byKey['dmri']['evidence_text'])->toBe('CID H35.3 em prontuário ainda não assinado (desde 12/01/2026)')
        ->and($byKey['dmri']['can'])->toBe(['confirm' => true, 'reject' => true, 'discharge' => false, 'edit' => true])
        ->and($byKey['dmri']['next'])->toBe([]) // sugerida não gera retorno de protocolo
        ->and(collect($data['protocols'])->pluck('key')->all())->toBe(['dmri', 'glaucoma'])
        ->and(collect($data['eyes'])->pluck('value')->all())->toBe(['both', 'right', 'left'])
        ->and($data['t']['title'])->toBe('Condições crônicas');

    // Nada de id de prontuário nem de texto livre na resposta (só código, data e frase).
    expect(json_encode($response->json()))->not->toContain((string) $record->id);

    $log = DataAccessLog::query()->where('resource_id', $this->patient->id)->sole();
    expect($log->purpose)->toBe(DataAccessPurpose::PatientCare)
        ->and((string) $log->patient_id)->toBe((string) $this->patient->id);
});

it('GET: histórico (descartadas e com alta) e frases de medicação e de cadastro manual', function () {
    $this->travelTo('2026-02-01 10:00:00');

    rcfAdopt($this->entity, 'hidroxicloroquina');
    rcfAdopt($this->entity, 'glaucoma');
    rcfMedication($this->entity, $this->patient, 'Plaquenil (hidroxicloroquina 400 mg)', '2025-06-01', confirmed: false);
    rcfEvaluate($this->entity, $this->patient);
    $manual = $this->service->add((string) $this->entity->id, (string) $this->patient->id, 'glaucoma', Eye::Both, null, $this->doctorUser, $this->today);
    $this->service->discharge((string) $this->entity->id, (string) $manual->id, 'Controlado, sem necessidade de retorno', $this->doctorUser, $this->today);

    $data = pcnAs($this, $this->doctorUser, $this->doctorEu)->getJson(pcnUrl($this, 'index'))->assertOk()->json('data');

    expect($data['conditions'])->toHaveCount(1)
        ->and($data['conditions'][0]['evidence_text'])->toBe('Medicação informada, ainda não confirmada: hidroxicloroquina (desde 01/06/2025)')
        ->and($data['history'])->toHaveCount(1)
        ->and($data['history'][0])->toMatchArray([
            'status'           => 'discharged',
            'evidence_text'    => 'Registrada pelo médico',
            'discharge_reason' => 'Controlado, sem necessidade de retorno',
        ])
        ->and($data['history'][0]['can'])->toBe(['confirm' => false, 'reject' => false, 'discharge' => false, 'edit' => false]);
});

it('GET: condição de um protocolo que a clínica deixou de usar avisa que não gera retorno', function () {
    $this->travelTo('2026-02-01 10:00:00');

    rcfAdopt($this->entity, 'glaucoma');
    rcfRecord($this->entity, $this->patient, $this->doctor, '2026-01-10', cids: ['H40.1'], signed: true);
    rcfEvaluate($this->entity, $this->patient);
    DB::table('recall_clinic_protocols')->where('entity_id', $this->entity->id)->update(['deactivated_at' => now()]);

    $data = pcnAs($this, $this->doctorUser, $this->doctorEu)->getJson(pcnUrl($this, 'index'))->assertOk()->json('data');

    expect($data['conditions'][0]['protocol_active'])->toBeFalse()
        ->and($data['conditions'][0]['next'])->toBe([])
        ->and($data['protocols'])->toBe([]);
});

it('só o médico com cadastro ativo lê e altera: admin, secretária, financeiro e médico sem cadastro ativo recebem 403 em toda rota', function () {
    $condition = pcnSuggested($this);

    $routes = [
        ['getJson', pcnUrl($this, 'index'), []],
        ['postJson', pcnUrl($this, 'store'), ['protocol_key' => 'glaucoma', 'eye' => 'left']],
        ['putJson', pcnUrl($this, 'update', (string) $condition->id), ['eye' => 'left']],
        ['postJson', pcnUrl($this, 'confirm', (string) $condition->id), []],
        ['postJson', pcnUrl($this, 'reject', (string) $condition->id), []],
        ['postJson', pcnUrl($this, 'discharge', (string) $condition->id), ['reason' => 'Motivo qualquer']],
    ];

    foreach ([[$this->admin, $this->adminEu], [$this->secretary, $this->secretaryEu], [$this->financial, $this->financialEu]] as [$user, $eu]) {
        foreach ($routes as [$method, $url, $body]) {
            pcnAs($this, $user, $eu)->{$method}($url, $body)->assertForbidden();
        }
    }

    $this->doctor->forceFill(['active' => false])->save();

    foreach ($routes as [$method, $url, $body]) {
        pcnAs($this, $this->doctorUser, $this->doctorEu)->{$method}($url, $body)->assertForbidden();
    }

    expect($condition->fresh()->status)->toBe(ConditionStatus::Suggested)
        ->and(PatientCondition::query()->withoutGlobalScopes()->count())->toBe(1);
});

it('sem a feature no plano, as rotas não abrem (JSON 403 do plano)', function () {
    $other = rcfClinic(); // sem a feature has_patient_recall
    $eu    = EntityUser::query()->findOrFail($other['doctor']->entity_user_id);
    $user  = User::query()->findOrFail($eu->user_id);
    $own   = rcfPatient($other['entity']);

    pcnAs($this, $user, $eu)->getJson(route('panel.patients.recall-conditions.index', $own->id))->assertForbidden();
    pcnAs($this, $user, $eu)->postJson(route('panel.patients.recall-conditions.store', $own->id), ['protocol_key' => 'glaucoma', 'eye' => 'both'])->assertForbidden();

    expect(PatientCondition::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('paciente de outra clínica e condição de OUTRO paciente: 404 em toda rota (nada vaza, nada muda)', function () {
    $condition = pcnSuggested($this);
    $mine      = rcfPatient($this->entity); // outro paciente DESTA clínica
    $foreign   = rcfPatient(rcfClinic(recall: true)['entity']);
    $as        = fn () => pcnAs($this, $this->doctorUser, $this->doctorEu);

    $as()->getJson(route('panel.patients.recall-conditions.index', $foreign->id))->assertNotFound();
    $as()->postJson(route('panel.patients.recall-conditions.store', $foreign->id), ['protocol_key' => 'glaucoma', 'eye' => 'both'])->assertNotFound();

    // A condição é do paciente $this->patient: pela URL de outro paciente da MESMA clínica (ou da outra), 404.
    foreach ([$mine, $foreign] as $patient) {
        $as()->putJson(pcnUrl($this, 'update', (string) $condition->id, $patient), ['eye' => 'right'])->assertNotFound();
        $as()->postJson(pcnUrl($this, 'confirm', (string) $condition->id, $patient))->assertNotFound();
        $as()->postJson(pcnUrl($this, 'reject', (string) $condition->id, $patient))->assertNotFound();
        $as()->postJson(pcnUrl($this, 'discharge', (string) $condition->id, $patient), ['reason' => 'Motivo qualquer'])->assertNotFound();
    }

    expect($condition->fresh()->status)->toBe(ConditionStatus::Suggested)
        ->and($condition->fresh()->eye)->toBe(Eye::Both);
});

it('os atos pelo HTTP: registrar (201), corrigir, confirmar, descartar e dar alta — cada resposta traz a situação inteira atualizada', function () {
    $this->travelTo('2026-02-01 10:00:00');
    $as = fn () => pcnAs($this, $this->doctorUser, $this->doctorEu);

    rcfAdopt($this->entity, 'glaucoma');
    rcfAdopt($this->entity, 'dmri');
    rcfRecord($this->entity, $this->patient, $this->doctor, '2026-01-10', cids: ['H35.3'], signed: false);
    rcfEvaluate($this->entity, $this->patient);
    $suggested = PatientCondition::query()->withoutGlobalScopes()->where('protocol_key', 'dmri')->sole();

    // Registrar à mão
    $created = $as()->postJson(pcnUrl($this, 'store'), ['protocol_key' => 'glaucoma', 'eye' => 'right', 'started_on' => '2025-12-01'])->assertCreated();
    expect($created->json('message'))->toBe('Condição registrada.')
        ->and(collect($created->json('data.conditions'))->pluck('protocol_key')->all())->toContain('glaucoma', 'dmri');

    $manualId = collect($created->json('data.conditions'))->firstWhere('protocol_key', 'glaucoma')['id'];

    // Corrigir
    $updated = $as()->putJson(pcnUrl($this, 'update', $manualId), ['eye' => 'left', 'started_on' => null])->assertOk();
    expect($updated->json('message'))->toBe('Condição salva.')
        ->and(collect($updated->json('data.conditions'))->firstWhere('id', $manualId))->toMatchArray(['eye' => 'left', 'started_on' => null]);

    // Confirmar a sugerida
    $confirmed = $as()->postJson(pcnUrl($this, 'confirm', (string) $suggested->id))->assertOk();
    expect($confirmed->json('message'))->toBe('Condição confirmada.')
        ->and(collect($confirmed->json('data.conditions'))->firstWhere('id', (string) $suggested->id)['status'])->toBe('active');

    // Dar alta (motivo obrigatório: 422 pelo FormRequest) e depois com motivo
    $as()->postJson(pcnUrl($this, 'discharge', $manualId), ['reason' => 'ab'])->assertUnprocessable()->assertJsonValidationErrors('reason');
    $discharged = $as()->postJson(pcnUrl($this, 'discharge', $manualId), ['reason' => 'Controlado, sem necessidade de retorno'])->assertOk();
    expect($discharged->json('message'))->toBe('Alta registrada.')
        ->and(collect($discharged->json('data.history'))->pluck('id')->all())->toContain($manualId);

    // Descartar uma sugerida
    $other = PatientCondition::query()->withoutGlobalScopes()->create([
        'entity_id' => $this->entity->id, 'patient_id' => $this->patient->id, 'protocol_key' => 'glaucoma', 'eye' => 'both',
        'status'    => ConditionStatus::Suggested->value, 'origin' => ConditionOrigin::Cid->value,
    ]);
    $rejected = $as()->postJson(pcnUrl($this, 'reject', (string) $other->id))->assertOk();
    expect($rejected->json('message'))->toBe('Condição descartada.')
        ->and($other->fresh()->status)->toBe(ConditionStatus::Rejected);
});

it('recusas de negócio voltam como 422 com a mensagem traduzida e o código; validação do corpo, 422 do Laravel', function () {
    $as = fn () => pcnAs($this, $this->doctorUser, $this->doctorEu);
    rcfAdopt($this->entity, 'glaucoma');

    $as()->postJson(pcnUrl($this, 'store'), ['protocol_key' => 'dmri', 'eye' => 'both'])
        ->assertUnprocessable()
        ->assertJson(['reason' => 'protocol_not_active', 'message' => 'A clínica não usa este protocolo (ou ele está parado). Ative-o em Configurar retornos.']);

    $as()->postJson(pcnUrl($this, 'store'), ['protocol_key' => 'glaucoma', 'eye' => 'right'])->assertCreated();
    $as()->postJson(pcnUrl($this, 'store'), ['protocol_key' => 'glaucoma', 'eye' => 'both'])
        ->assertUnprocessable()
        ->assertJson(['reason' => 'already_open']);

    $as()->postJson(pcnUrl($this, 'store'), ['protocol_key' => 'glaucoma', 'eye' => 'left', 'started_on' => '2999-01-01'])
        ->assertUnprocessable()
        ->assertJson(['reason' => 'invalid_date']);

    // Corpo inválido: o Laravel valida antes do serviço.
    $as()->postJson(pcnUrl($this, 'store'), ['protocol_key' => '', 'eye' => 'meio', 'started_on' => '01/02/2026'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['protocol_key', 'eye', 'started_on']);

    expect(PatientCondition::query()->withoutGlobalScopes()->count())->toBe(1);
});

it('GET: o histórico lembra só as últimas 20 decididas; as dispensadas não entram nos próximos retornos', function () {
    $this->travelTo('2026-02-01 10:00:00');

    rcfAdopt($this->entity, 'glaucoma');
    $dismissed = $this->service->add((string) $this->entity->id, (string) $this->patient->id, 'glaucoma', Eye::Both, CarbonImmutable::parse('2025-12-01'), $this->doctorUser, $this->today);

    // O retorno que a equipe dispensou não é "próximo retorno" da condição.
    PatientRecall::query()->withoutGlobalScopes()->where('patient_id', $this->patient->id)->where('task_key', 'consulta')->update(['lifecycle' => RecallLifecycle::Dismissed->value]);

    $labels = collect(pcnAs($this, $this->doctorUser, $this->doctorEu)->getJson(pcnUrl($this, 'index'))->assertOk()->json('data.conditions.0.next'))->pluck('label')->all();
    expect($labels)->not->toContain('Consulta de retorno')
        ->and($labels)->toContain('Exame: Campo visual (campimetria)'); // o que segue em aberto continua listado

    // 22 condições já decididas (descartadas): a gaveta mostra 20.
    foreach (range(1, 22) as $number) {
        PatientCondition::query()->withoutGlobalScopes()->create([
            'entity_id' => $this->entity->id, 'patient_id' => $this->patient->id, 'protocol_key' => 'dmri', 'eye' => 'both',
            'status'    => ConditionStatus::Rejected->value, 'origin' => ConditionOrigin::Cid->value, 'rejected_at' => now()->subMinutes($number),
        ]);
    }

    $data = pcnAs($this, $this->doctorUser, $this->doctorEu)->getJson(pcnUrl($this, 'index'))->assertOk()->json('data');

    expect($data['history'])->toHaveCount(20)
        ->and($data['conditions'])->toHaveCount(1)
        ->and($dismissed->fresh()->status)->toBe(ConditionStatus::Active);
});

it('a página de Pacientes só libera as condições na gaveta ao médico com cadastro ativo, num plano com o recall', function () {
    $prop = fn (User $user, EntityUser $eu) => pcnAs($this, $user, $eu)->get(route('panel.patients.index'))->assertOk()->viewData('page')['props']['recallConditions'];

    expect($prop($this->doctorUser, $this->doctorEu))->toBeTrue()
        ->and($prop($this->secretary, $this->secretaryEu))->toBeFalse()
        ->and($prop($this->admin, $this->adminEu))->toBeFalse();

    // Médico sem cadastro ativo: a rota recusaria; a gaveta nem oferece o cartão.
    $this->doctor->forceFill(['active' => false])->save();
    expect($prop($this->doctorUser, $this->doctorEu))->toBeFalse();

    // Médico de uma clínica SEM a feature no plano.
    $other = rcfClinic();
    $eu    = EntityUser::query()->findOrFail($other['doctor']->entity_user_id);
    expect($prop(User::query()->findOrFail($eu->user_id), $eu))->toBeFalse();
});

it('GET: medicação CONFIRMADA vira condição ativa com a frase "em uso"; versão suspensa tira o protocolo da lista e avisa', function () {
    $this->travelTo('2026-02-01 10:00:00');

    $adoption = rcfAdopt($this->entity, 'hidroxicloroquina');
    rcfMedication($this->entity, $this->patient, 'Plaquenil (hidroxicloroquina 400 mg)', '2025-06-01', confirmed: true);
    rcfEvaluate($this->entity, $this->patient);

    $data = pcnAs($this, $this->doctorUser, $this->doctorEu)->getJson(pcnUrl($this, 'index'))->assertOk()->json('data');

    expect($data['conditions'][0])->toMatchArray([
        'status'          => 'active',
        'origin'          => 'medication',
        'evidence_text'   => 'Medicação em uso: hidroxicloroquina (desde 01/06/2025)',
        'protocol_active' => true,
    ])
        ->and(collect($data['protocols'])->pluck('key')->all())->toBe(['hidroxicloroquina']);

    // A EasyEye suspende a versão adotada: a condição fica, o protocolo sai da lista e a gaveta avisa que não gera retorno.
    app(ProtocolGovernanceService::class)->suspend($adoption->version->fresh(), 'Dúvida clínica sobre o intervalo do exame.');

    $paused = pcnAs($this, $this->doctorUser, $this->doctorEu)->getJson(pcnUrl($this, 'index'))->assertOk()->json('data');

    expect($paused['conditions'])->toHaveCount(1)
        ->and($paused['conditions'][0]['protocol_active'])->toBeFalse()
        ->and($paused['conditions'][0]['next'])->toBe([])
        ->and($paused['protocols'])->toBe([]);
});

it('GET: condição de OUTRA clínica com o mesmo id de paciente nunca aparece na gaveta', function () {
    rcfAdopt($this->entity, 'glaucoma');
    $other = rcfClinic(recall: true)['entity'];

    PatientCondition::query()->withoutGlobalScopes()->create([
        'entity_id' => $other->id, 'patient_id' => $this->patient->id, 'protocol_key' => 'glaucoma', 'eye' => 'both',
        'status'    => ConditionStatus::Active->value, 'origin' => ConditionOrigin::Manual->value,
    ]);

    $data = pcnAs($this, $this->doctorUser, $this->doctorEu)->getJson(pcnUrl($this, 'index'))->assertOk()->json('data');

    expect($data['conditions'])->toBe([])->and($data['history'])->toBe([]);
});
