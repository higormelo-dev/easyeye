<?php

declare(strict_types=1);

/**
 * Quem decide se um convite de retorno por WhatsApp pode sair (ADR 0002, item 9; invariantes I1 e I3): os três níveis
 * de chave, o paciente (consentimento, SAIR, bloqueio), os retornos (regra em uso) e o ritmo (janela, intervalo mínimo,
 * teto diário). Relógio fixo: domingo, 01/02/2026, 10:00. O cenário-base é o retorno que o médico pediu em 01/12/2025
 * (30 dias), hoje em atraso e dentro da janela de convite.
 */

use App\Domains\Recall\Enums\{InviteBlocker as Blocker, InviteOrigin};
use App\Domains\Recall\Models\{PatientRecall, PatientRecallBlock, RecallClinicSettings, RecallInvite};
use App\Domains\Recall\Services\{ProtocolGovernanceService, RecallInviteGuard, RecallKillSwitch};
use App\Enums\Eye;
use App\Models\WhatsApp\{WhatsAppMessage, WhatsAppOptOut};
use App\Services\Billing\ClinicServiceGate;
use App\Services\FeatureGateService;
use Carbon\{Carbon, CarbonImmutable};
use Illuminate\Support\Facades\{DB, Notification};

beforeEach(function () {
    Notification::fake();
    $this->travelTo(Carbon::parse('2026-02-01 10:00:00'));

    ['entity' => $this->entity, 'doctor' => $this->doctor] = rcfClinic(recall: true);

    $this->sender  = rcfInviteChannel($this->entity);
    $this->patient = rcfInvitePatient($this->entity);
    $this->recall  = rcfOverdueRecall($this->entity, $this->patient, $this->doctor);
    $this->now     = CarbonImmutable::parse('2026-02-01 10:00:00');
});

function rigDecide(object $test, InviteOrigin $origin = InviteOrigin::Manual, ?object $patient = null)
{
    return app(RecallInviteGuard::class)->decide((string) $test->entity->id, (string) ($patient ?? $test->patient)->id, $origin, $test->now);
}

/** Paciente só com retorno de PROTOCOLO (glaucoma, adotado): sem retorno pedido pelo médico. */
function rigProtocolPatient(object $test, bool $autoContact = false): array
{
    $adoption = rcfAdopt($test->entity, 'glaucoma');
    $adoption->update(['auto_contact' => $autoContact]);

    $patient = rcfInvitePatient($test->entity, 'JOSE PROTOCOLO', '11966665555');
    rcfCondition($test->entity, $patient, 'glaucoma', Eye::Both, '2025-01-15');
    rcfRecord($test->entity, $patient, $test->doctor, '2025-06-01');
    rcfEvaluate($test->entity, $patient, '2026-02-01');

    return [$adoption, $patient];
}

/** Os códigos que impedem, como lista de strings. */
function rigBlockers(object $decision): array
{
    return array_map(static fn (Blocker $blocker): string => $blocker->value, $decision->blockers);
}

/** Um convite anterior do paciente, com a mensagem no status dado e criado há `$daysAgo` dias. */
function rigPastInvite(object $test, int $daysAgo, string $messageStatus = 'sent', ?object $patient = null, string $origin = 'auto'): RecallInvite
{
    $patient ??= $test->patient;
    $at = CarbonImmutable::now()->subDays($daysAgo);

    $invite = RecallInvite::query()->withoutGlobalScopes()->create([
        'entity_id'  => $test->entity->id,
        'patient_id' => $patient->id,
        'wave_key'   => RecallInvite::waveKey((string) $patient->id, $at->toDateString()),
        'origin'     => $origin,
    ]);
    DB::table('recall_invites')->where('id', $invite->id)->update(['created_at' => $at]);

    WhatsAppMessage::create([
        'entity_id'        => $test->entity->id,
        'patient_id'       => $patient->id,
        'recall_invite_id' => $invite->id,
        'direction'        => 'out',
        'kind'             => WhatsAppMessage::KIND_RECALL,
        'phone'            => '5511988887777',
        'body'             => 'x',
        'status'           => $messageStatus,
    ]);

    return $invite->fresh();
}

// ── o cenário-base ──────────────────────────────────────────────────────────

it('com tudo em ordem o convite pode sair: telefone normalizado, primeiro nome e o retorno coberto', function () {
    $decision = rigDecide($this);

    expect($decision->allowed())->toBeTrue()
        ->and($decision->blockers)->toBe([])
        ->and($decision->phone)->toBe('5511988887777')
        ->and($decision->firstName)->toBe('Maria')
        ->and($decision->recalls)->toHaveCount(1)
        ->and((string) $decision->recalls[0]->id)->toBe((string) $this->recall->id);
});

// ── 1. o canal da clínica (três níveis de chave) ────────────────────────────

it('pausa global do EasyEye barra o canal de todas as clínicas', function () {
    app(RecallKillSwitch::class)->pause('Número queimado na Meta', null, null);

    expect(rigBlockers(rigDecide($this)))->toContain('paused_global')
        ->and(app(RecallInviteGuard::class)->channelBlocker((string) $this->entity->id))->toBe(Blocker::PausedGlobal);

    app(RecallKillSwitch::class)->resume();

    expect(rigDecide($this)->allowed())->toBeTrue();
});

it('a chave da clínica no Manager (recall_enabled) desligada, ou clínica sem linha de WhatsApp, barra o canal', function () {
    $this->sender->update(['recall_enabled' => false]);

    expect(rigBlockers(rigDecide($this)))->toContain('clinic_not_enabled');

    $this->sender->delete();

    expect(rigBlockers(rigDecide($this)))->toContain('clinic_not_enabled');
});

it('sem número que consiga enviar (integração inativa) o canal está fechado', function () {
    $this->sender->update(['active' => false]);

    expect(rigBlockers(rigDecide($this)))->toContain('no_sender');
});

it('plano rebaixado sem o recurso de retorno barra o canal, mesmo com as três chaves ligadas', function () {
    expect(rigDecide($this)->allowed())->toBeTrue();

    DB::table('plan_features')->where('feature', 'has_patient_recall')->update(['value' => '0']);
    app(FeatureGateService::class)->forgetCache((string) $this->entity->id);

    $guard = app(RecallInviteGuard::class);

    expect(rigBlockers(rigDecide($this)))->toContain('feature_off')
        ->and($guard->channelBlocker((string) $this->entity->id))->toBe(Blocker::FeatureOff)
        ->and($guard->providerBlocker((string) $this->entity->id))->toBe(Blocker::FeatureOff);
});

it('o acesso da clínica bloqueado por assinatura barra o canal', function () {
    $gate = Mockery::mock(ClinicServiceGate::class);
    $gate->shouldReceive('allowsAutomation')->andReturn(false);
    app()->instance(ClinicServiceGate::class, $gate);

    expect(rigBlockers(rigDecide($this)))->toContain('access_blocked');
});

it('a chave da própria clínica (whatsapp_invites_enabled) desligada barra o canal; o lado do EasyEye segue liberado', function () {
    RecallClinicSettings::query()->withoutGlobalScopes()->where('entity_id', $this->entity->id)->update(['whatsapp_invites_enabled' => false]);

    $guard = app(RecallInviteGuard::class);

    expect(rigBlockers(rigDecide($this)))->toContain('clinic_switch_off')
        ->and($guard->channelBlocker((string) $this->entity->id))->toBe(Blocker::ClinicSwitchOff)
        ->and($guard->providerBlocker((string) $this->entity->id))->toBeNull();
});

// ── 2. o paciente ───────────────────────────────────────────────────────────

it('paciente inativo ou removido: indisponível, sem nem olhar os retornos', function () {
    $this->patient->update(['active' => false]);

    $decision = rigDecide($this);

    expect(rigBlockers($decision))->toContain('patient_unavailable')
        ->and($decision->recalls)->toBe([]);

    $this->patient->update(['active' => true]);
    $this->patient->delete();

    expect(rigBlockers(rigDecide($this)))->toContain('patient_unavailable');
});

it('sem a autorização de lembretes de retorno (nunca deu, ou revogou) o convite não sai', function () {
    DB::table('patient_consents')->where('patient_id', $this->patient->id)->update(['status' => 'revoked', 'revoked_at' => now()]);

    expect(rigBlockers(rigDecide($this)))->toContain('no_consent');

    DB::table('patient_consents')->where('patient_id', $this->patient->id)->delete();

    expect(rigBlockers(rigDecide($this)))->toContain('no_consent');
});

it('a autorização de OUTRO tipo, ou de outro paciente, não vale', function () {
    DB::table('patient_consents')->where('patient_id', $this->patient->id)->update(['consent_type' => 'data_collection']);

    expect(rigBlockers(rigDecide($this)))->toContain('no_consent');

    $other = rcfInvitePatient($this->entity, 'OUTRA PESSOA', '11977776666');
    DB::table('patient_consents')->where('patient_id', $this->patient->id)->update(['consent_type' => 'care_reminders', 'revoked_at' => now(), 'status' => 'revoked']);

    expect(rigBlockers(rigDecide($this)))->toContain('no_consent')
        ->and(app(RecallInviteGuard::class)->hasConsent((string) $this->entity->id, (string) $other->id))->toBeTrue();
});

it('celular não marcado como WhatsApp, fixo ou inválido: não sai', function () {
    $person = $this->patient->person;

    $person->update(['whatsapp' => false]);
    expect(rigBlockers(rigDecide($this)))->toContain('no_whatsapp');

    $person->update(['whatsapp' => true, 'cellphone' => '1133334444']);
    expect(rigBlockers(rigDecide($this)))->toContain('invalid_phone');

    $person->update(['cellphone' => '123']);
    expect(rigBlockers(rigDecide($this)))->toContain('invalid_phone');

    $person->update(['cellphone' => '(11) 98888-7777']);
    expect(rigDecide($this)->phone)->toBe('5511988887777');
});

it('quem respondeu SAIR ao número que envia não recebe (mas outro número, sim)', function () {
    WhatsAppOptOut::create(['whatsapp_setting_id' => $this->sender->id, 'phone' => '5511988887777', 'source' => WhatsAppOptOut::SOURCE_KEYWORD, 'opted_out_at' => now()]);

    expect(rigBlockers(rigDecide($this)))->toContain('opted_out');

    WhatsAppOptOut::query()->delete();

    expect(rigDecide($this)->allowed())->toBeTrue();
});

it('qualquer bloqueio de contato ativo barra; o bloqueio liberado, não', function () {
    $block = PatientRecallBlock::query()->withoutGlobalScopes()->create(['entity_id' => $this->entity->id, 'patient_id' => $this->patient->id, 'reason' => 'do_not_contact']);

    expect(rigBlockers(rigDecide($this)))->toContain('contact_blocked');

    $block->update(['released_at' => now()]);

    expect(rigDecide($this)->allowed())->toBeTrue();
});

// ── 3. os retornos ──────────────────────────────────────────────────────────

it('retorno agendado, adiado, dispensado, encerrado, com regra suspensa ou fora da janela de convite: nada a convidar', function (string $case) {
    $update = static fn (array $values) => DB::table('patient_recalls')->where('id', test()->recall->id)->update($values);

    match ($case) {
        'agendado'         => $update(['scheduled_schedule_id' => createScheduleForEntity($this->entity, ['patient_id' => $this->patient->id, 'date_time' => now()->addDays(10)])['schedule']->id]),
        'adiado'           => $update(['lifecycle' => 'snoozed']),
        'dispensado'       => $update(['lifecycle' => 'dismissed']),
        'encerrado'        => $update(['lifecycle' => 'closed']),
        'regra suspensa'   => $update(['rule_suspended' => true]),
        'ainda cedo'       => $update(['invite_from' => '2026-02-02']),
        'perdido de vista' => $update(['lost_from' => '2026-02-01']),
    };

    $decision = rigDecide($this);

    expect(rigBlockers($decision))->toBe(['nothing_to_invite'])
        ->and($decision->recalls)->toBe([]);
})->with(['agendado', 'adiado', 'dispensado', 'encerrado', 'regra suspensa', 'ainda cedo', 'perdido de vista']);

it('o retorno pedido pelo médico só vale com o recurso ligado na clínica', function () {
    RecallClinicSettings::query()->withoutGlobalScopes()->where('entity_id', $this->entity->id)->update(['doctor_order_enabled' => false]);

    expect(rigBlockers(rigDecide($this)))->toBe(['nothing_to_invite']);
});

it('retorno de protocolo: no envio de uma pessoa vale com a adoção em uso; no automático exige o contato automático ligado', function () {
    [$adoption, $patient] = rigProtocolPatient($this);

    $manual = rigDecide($this, InviteOrigin::Manual, $patient);
    $auto   = rigDecide($this, InviteOrigin::Auto, $patient);

    expect($manual->allowed())->toBeTrue()
        ->and($manual->recalls)->not->toBeEmpty()
        ->and($manual->recalls[0]->source)->toBe('protocol')
        ->and(rigBlockers($auto))->toBe(['nothing_to_invite']);

    $adoption->update(['auto_contact' => true]);

    expect(rigDecide($this, InviteOrigin::Auto, $patient)->allowed())->toBeTrue();
});

it('I3: versão do protocolo suspensa depois da adoção — o retorno de protocolo sai do convite, nos dois modos', function () {
    [$adoption, $patient] = rigProtocolPatient($this, autoContact: true);

    expect(rigDecide($this, InviteOrigin::Manual, $patient)->allowed())->toBeTrue();

    app(ProtocolGovernanceService::class)->suspend($adoption->version->fresh(), 'Dúvida clínica sobre o intervalo da campimetria.');

    // O avaliador ainda NÃO reavaliou: a linha segue "aberta" no espelho — a decisão olha a versão AGORA.
    expect(PatientRecall::query()->withoutGlobalScopes()->where('patient_id', $patient->id)->where('source', 'protocol')->where('lifecycle', 'open')->exists())->toBeTrue()
        ->and(rigBlockers(rigDecide($this, InviteOrigin::Manual, $patient)))->toBe(['nothing_to_invite'])
        ->and(rigBlockers(rigDecide($this, InviteOrigin::Auto, $patient)))->toBe(['nothing_to_invite']);
});

it('no automático, retorno vencido há mais que o limite da clínica sai do convite (vai para a ligação)', function () {
    RecallClinicSettings::query()->withoutGlobalScopes()->where('entity_id', $this->entity->id)->update(['auto_invite_max_overdue_days' => 30]);

    // Vencido em 31/12/2025: 32 dias de atraso em 01/02/2026.
    expect(rigBlockers(rigDecide($this, InviteOrigin::Auto)))->toBe(['nothing_to_invite'])
        ->and(rigDecide($this)->allowed())->toBeTrue(); // uma pessoa ainda pode convidar
});

/** Um convite AUTOMÁTICO do ciclo atual que cobriu o retorno-base, criado em `$date`. */
function rigCycleInvite(object $test, string $date): void
{
    $invite = RecallInvite::query()->withoutGlobalScopes()->create(['entity_id' => $test->entity->id, 'patient_id' => $test->patient->id, 'wave_key' => "ciclo-{$date}", 'origin' => 'auto']);
    DB::table('recall_invites')->where('id', $invite->id)->update(['created_at' => "{$date} 09:00:00"]);
    $invite->recalls()->attach($test->recall->id);
}

it('teto de convites automáticos por ciclo: só os do ciclo atual contam; no teto o robô para e uma pessoa ainda pode', function () {
    // O ciclo começou em 01/12/2025 (invite_from): os convites de antes não contam.
    rigCycleInvite($this, '2025-10-28');
    rigCycleInvite($this, '2025-11-27');
    rigCycleInvite($this, '2025-12-05');
    rigCycleInvite($this, '2025-12-15');

    expect(rigDecide($this, InviteOrigin::Auto)->recalls)->toHaveCount(1); // 2 do ciclo < 3

    rigCycleInvite($this, '2025-12-28');

    $auto   = rigDecide($this, InviteOrigin::Auto);
    $manual = rigDecide($this);

    expect($auto->recalls)->toBe([])
        ->and(rigBlockers($auto))->toBe(['nothing_to_invite'])
        ->and($manual->recalls)->toHaveCount(1);
});

it('o teto por ciclo é para o ROBÔ: convite mandado por uma pessoa não conta', function () {
    foreach (['2025-12-05', '2025-12-15', '2025-12-28'] as $date) {
        $invite = RecallInvite::query()->withoutGlobalScopes()->create(['entity_id' => $this->entity->id, 'patient_id' => $this->patient->id, 'wave_key' => "pessoa-{$date}", 'origin' => 'manual']);
        DB::table('recall_invites')->where('id', $invite->id)->update(['created_at' => "{$date} 09:00:00"]);
        $invite->recalls()->attach($this->recall->id);
    }

    expect(rigDecide($this, InviteOrigin::Auto)->recalls)->toHaveCount(1);
});

// ── 4. o ritmo ──────────────────────────────────────────────────────────────

it('intervalo mínimo entre convites do mesmo paciente: convite recente conta; o cancelado/pulado, não', function () {
    $recent = rigPastInvite($this, 10);

    $decision = rigDecide($this);

    expect(rigBlockers($decision))->toContain('too_soon')
        ->and($decision->pacing['next_allowed_on'])->toBe(CarbonImmutable::now()->subDays(10)->startOfDay()->addDays(30)->toDateString());

    DB::table('whatsapp_messages')->where('recall_invite_id', $recent->id)->update(['status' => 'suppressed']);

    expect(rigDecide($this)->allowed())->toBeTrue();

    DB::table('whatsapp_messages')->where('recall_invite_id', $recent->id)->update(['status' => 'skipped']);

    expect(rigDecide($this)->allowed())->toBeTrue();

    DB::table('whatsapp_messages')->where('recall_invite_id', $recent->id)->update(['status' => 'failed']);

    expect(rigBlockers(rigDecide($this)))->toContain('too_soon'); // falha NÃO libera: não martela o número
});

it('passado o intervalo mínimo o paciente volta a poder receber', function () {
    rigPastInvite($this, 31);

    expect(rigDecide($this)->allowed())->toBeTrue();
});

it('teto diário da clínica: ao atingir, nenhum outro paciente recebe hoje', function () {
    RecallClinicSettings::query()->withoutGlobalScopes()->where('entity_id', $this->entity->id)->update(['daily_invite_cap' => 2]);

    foreach (['A', 'B'] as $i => $letter) {
        $other = rcfInvitePatient($this->entity, "PACIENTE {$letter}", '1197777000' . $i);
        rigPastInvite($this, 0, 'sent', $other);
    }

    $decision = rigDecide($this);

    expect(rigBlockers($decision))->toContain('daily_cap')
        ->and($decision->pacing['used_today'])->toBe(2)
        ->and($decision->pacing['cap_today'])->toBe(2);
});

it('ramp-up: nos primeiros dias o teto é o degrau do ramp-up, não o da clínica', function () {
    RecallClinicSettings::query()->withoutGlobalScopes()->where('entity_id', $this->entity->id)->update(['daily_invite_cap' => 100, 'ramp_started_on' => '2026-02-01']);

    expect(rigDecide($this)->pacing['cap_today'])->toBe(5);

    RecallClinicSettings::query()->withoutGlobalScopes()->where('entity_id', $this->entity->id)->update(['ramp_started_on' => '2026-01-25']);
    expect(rigDecide($this)->pacing['cap_today'])->toBe(10);

    RecallClinicSettings::query()->withoutGlobalScopes()->where('entity_id', $this->entity->id)->update(['ramp_started_on' => '2026-01-18']);
    expect(rigDecide($this)->pacing['cap_today'])->toBe(20);

    RecallClinicSettings::query()->withoutGlobalScopes()->where('entity_id', $this->entity->id)->update(['ramp_started_on' => '2026-01-10']);
    expect(rigDecide($this)->pacing['cap_today'])->toBe(100);

    RecallClinicSettings::query()->withoutGlobalScopes()->where('entity_id', $this->entity->id)->update(['daily_invite_cap' => 8, 'ramp_started_on' => '2026-01-10']);
    expect(rigDecide($this)->pacing['cap_today'])->toBe(8);
});

it('janela de horário e dias da semana valem só para o envio automático', function () {
    RecallClinicSettings::query()->withoutGlobalScopes()->where('entity_id', $this->entity->id)->update(['invite_window_start' => '11:00', 'invite_window_end' => '12:00']);

    expect(rigBlockers(rigDecide($this, InviteOrigin::Auto)))->toContain('outside_window')
        ->and(rigDecide($this)->allowed())->toBeTrue();

    RecallClinicSettings::query()->withoutGlobalScopes()->where('entity_id', $this->entity->id)->update(['invite_window_start' => '09:00', 'invite_window_end' => '18:00', 'invite_weekdays' => [1, 2, 3, 4, 5]]);

    // 01/02/2026 é domingo (ISO 7).
    expect(rigBlockers(rigDecide($this, InviteOrigin::Auto)))->toContain('outside_window');

    RecallClinicSettings::query()->withoutGlobalScopes()->where('entity_id', $this->entity->id)->update(['invite_weekdays' => [7]]);

    expect(rigDecide($this, InviteOrigin::Auto)->allowed())->toBeTrue();
});

// ── a revalidação no momento do envio ───────────────────────────────────────

/** A mensagem de um convite recém-criado (como o serviço cria) para o paciente-base. */
function rigQueuedMessage(object $test, string $origin = 'manual'): WhatsAppMessage
{
    $invite = RecallInvite::query()->withoutGlobalScopes()->create([
        'entity_id'  => $test->entity->id,
        'patient_id' => $test->patient->id,
        'wave_key'   => RecallInvite::waveKey((string) $test->patient->id, '2026-02-01'),
        'origin'     => $origin,
    ]);
    $invite->recalls()->attach($test->recall->id);

    return WhatsAppMessage::create([
        'entity_id'        => $test->entity->id,
        'patient_id'       => $test->patient->id,
        'recall_invite_id' => $invite->id,
        'direction'        => 'out',
        'kind'             => WhatsAppMessage::KIND_RECALL,
        'phone'            => '5511988887777',
        'body'             => 'x',
        'status'           => 'pending',
    ]);
}

it('stillEligible: onda intacta pode sair', function () {
    expect(app(RecallInviteGuard::class)->stillEligible(rigQueuedMessage($this), $this->now))->toBeNull();
});

it('stillEligible reconfere tudo que pode mudar entre enfileirar e enviar', function (string $case, string $expected) {
    $message = rigQueuedMessage($this, 'auto');

    match ($case) {
        'pausa'       => app(RecallKillSwitch::class)->pause('Modelo reprovado pela Meta', null, null),
        'chave'       => RecallClinicSettings::query()->withoutGlobalScopes()->where('entity_id', $this->entity->id)->update(['whatsapp_invites_enabled' => false]),
        'manager'     => $this->sender->update(['recall_enabled' => false]),
        'consent'     => DB::table('patient_consents')->where('patient_id', $this->patient->id)->update(['status' => 'revoked', 'revoked_at' => now()]),
        'sair'        => WhatsAppOptOut::create(['whatsapp_setting_id' => $this->sender->id, 'phone' => '5511988887777', 'source' => 'keyword', 'opted_out_at' => now()]),
        'bloqueio'    => PatientRecallBlock::query()->withoutGlobalScopes()->create(['entity_id' => $this->entity->id, 'patient_id' => $this->patient->id, 'reason' => 'deceased']),
        'telefone'    => $this->patient->person->update(['cellphone' => '11955554444']),
        'dispensado'  => DB::table('patient_recalls')->where('id', $this->recall->id)->update(['lifecycle' => 'dismissed']),
        'agendado'    => DB::table('patient_recalls')->where('id', $this->recall->id)->update(['scheduled_schedule_id' => createScheduleForEntity($this->entity, ['patient_id' => $this->patient->id, 'date_time' => now()->addDays(10)])['schedule']->id]),
        'suspensa'    => DB::table('patient_recalls')->where('id', $this->recall->id)->update(['rule_suspended' => true]),
        'fora_janela' => RecallClinicSettings::query()->withoutGlobalScopes()->where('entity_id', $this->entity->id)->update(['invite_window_start' => '11:00', 'invite_window_end' => '12:00']),
    };

    expect(app(RecallInviteGuard::class)->stillEligible($message, $this->now)?->value)->toBe($expected);
})->with([
    'pausa global'           => ['pausa', 'paused_global'],
    'chave da clínica'       => ['chave', 'clinic_switch_off'],
    'chave do Manager'       => ['manager', 'clinic_not_enabled'],
    'consentimento retirado' => ['consent', 'no_consent'],
    'respondeu SAIR'         => ['sair', 'opted_out'],
    'bloqueio de contato'    => ['bloqueio', 'contact_blocked'],
    'telefone mudou'         => ['telefone', 'invalid_phone'],
    'retorno dispensado'     => ['dispensado', 'nothing_to_invite'],
    'retorno agendado'       => ['agendado', 'nothing_to_invite'],
    'regra suspensa'         => ['suspensa', 'nothing_to_invite'],
    'saiu da janela (auto)'  => ['fora_janela', 'outside_window'],
]);

it('stillEligible NÃO reconfere o ritmo: intervalo e teto valiam para criar a onda, não para enviá-la', function () {
    $message = rigQueuedMessage($this, 'auto');
    RecallClinicSettings::query()->withoutGlobalScopes()->where('entity_id', $this->entity->id)->update(['daily_invite_cap' => 1, 'min_days_between_invites' => 365, 'max_auto_invites_per_cycle' => 1]);

    expect(app(RecallInviteGuard::class)->stillEligible($message, $this->now))->toBeNull();
});

it('stillEligible: versão do protocolo suspensa depois de enfileirar cancela o envio (I3)', function () {
    [$adoption, $patient] = rigProtocolPatient($this, autoContact: true);

    $this->patient = $patient;
    $this->recall  = PatientRecall::query()->withoutGlobalScopes()->where('patient_id', $patient->id)->where('source', 'protocol')->firstOrFail();
    $message       = rigQueuedMessage($this);
    $message->update(['phone' => '5511966665555']);

    expect(app(RecallInviteGuard::class)->stillEligible($message->fresh(), $this->now))->toBeNull();

    app(ProtocolGovernanceService::class)->suspend($adoption->version->fresh(), 'Dúvida clínica sobre o intervalo da campimetria.');

    expect(app(RecallInviteGuard::class)->stillEligible($message->fresh(), $this->now))->toBe(Blocker::NothingToInvite);
});
