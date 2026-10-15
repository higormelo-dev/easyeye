<?php

declare(strict_types=1);

/**
 * O convite de retorno por WhatsApp (ADR 0002, item 9): a prévia, o envio por uma pessoa, a rodada automática e o
 * envio de verdade pelo job (driver mock — nada sai). Relógio fixo: domingo, 01/02/2026, 10:00.
 */

use App\Domains\Recall\Enums\{InviteBlocker, InviteOrigin};
use App\Domains\Recall\Enums\RecallBlockReason;
use App\Domains\Recall\Exceptions\RecallInviteException;
use App\Domains\Recall\Models\{PatientRecallBlock, PatientRecallEvent, RecallClinicSettings, RecallInvite};
use App\Domains\Recall\Services\{RecallInviteService, RecallKillSwitch};
use App\Enums\Eye;
use App\Jobs\WhatsApp\SendWhatsAppMessageJob;
use App\Models\User;
use App\Models\WhatsApp\{WhatsAppMessage, WhatsAppOptOut};
use App\Services\FeatureGateService;
use Carbon\{Carbon, CarbonImmutable};
use Illuminate\Support\Facades\{DB, Notification, Queue};
use Illuminate\Support\Str;

beforeEach(function () {
    Notification::fake();
    $this->travelTo(Carbon::parse('2026-02-01 10:00:00'));

    ['entity' => $this->entity, 'doctor' => $this->doctor] = rcfClinic(recall: true);

    $this->sender  = rcfInviteChannel($this->entity);
    $this->patient = rcfInvitePatient($this->entity);
    $this->recall  = rcfOverdueRecall($this->entity, $this->patient, $this->doctor);
    $this->service = app(RecallInviteService::class);
    $this->now     = CarbonImmutable::parse('2026-02-01 10:00:00');
});

/** Roda o job de envio na mão (com Queue::fake, dispatchSync só registra o job). */
function risRunJob(WhatsAppMessage $message): void
{
    app()->call([new SendWhatsAppMessageJob((string) $message->id), 'handle']);
}

function risMessage(RecallInvite $invite): WhatsAppMessage
{
    return WhatsAppMessage::query()->where('recall_invite_id', $invite->id)->where('direction', 'out')->sole();
}

/** Outro paciente com retorno vencido (atendimento em `$visit`, 30 dias), pronto para convite. */
function risOtherPatient(object $test, string $name, string $cellphone, string $visit = '2025-12-01', bool $consent = true)
{
    $patient = rcfInvitePatient($test->entity, $name, $cellphone, $consent);
    rcfRecord($test->entity, $patient, $test->doctor, $visit, 30);
    rcfEvaluate($test->entity, $patient, '2026-02-01');

    return $patient;
}

// ── o envio por uma pessoa ──────────────────────────────────────────────────

it('envio por uma pessoa: grava a onda, o item, a mensagem pending e enfileira o job; o evento diz quem mandou', function () {
    Queue::fake();
    $actor = User::factory()->create(['name' => 'Recepcionista Ana']);

    $invite  = $this->service->inviteManual((string) $this->entity->id, (string) $this->recall->id, $actor, $this->now);
    $message = risMessage($invite);
    $event   = PatientRecallEvent::query()->withoutGlobalScopes()->where('recall_id', $this->recall->id)->where('event', 'invite_queued')->sole();

    expect($invite->origin)->toBe(InviteOrigin::Manual)
        ->and($invite->wave_key)->toBe('2026-02-01:' . $this->patient->id)
        ->and($invite->created_by)->toBe((string) $actor->id)
        ->and($invite->recalls()->pluck('patient_recalls.id')->all())->toBe([(string) $this->recall->id])
        ->and($message->kind)->toBe('recall')
        ->and($message->status)->toBe('pending')
        ->and($message->entity_id)->toBe((string) $this->entity->id)
        ->and($message->patient_id)->toBe((string) $this->patient->id)
        ->and($message->schedule_id)->toBeNull()
        ->and($message->phone)->toBe('5511988887777')
        ->and($message->template)->toBe('easyeye_lembrete_retorno')
        ->and($message->payload['template_key'])->toBe('care_recall')
        ->and($event->actor_user_id)->toBe((string) $actor->id)
        ->and($event->actor_label)->toBe((string) $actor->fresh()->name)
        ->and($event->meta)->toBe(['invite_id' => (string) $invite->id, 'origin' => 'manual']);

    Queue::assertPushed(SendWhatsAppMessageJob::class, fn (SendWhatsAppMessageJob $job) => $job->messageId === (string) $message->id);
});

it('a mensagem é NEUTRA: só primeiro nome e clínica, nada de condição, exame ou motivo', function () {
    Queue::fake();

    $message = risMessage($this->service->inviteManual((string) $this->entity->id, (string) $this->recall->id, null, $this->now));

    expect($message->payload['values'])->toBe(['first_name' => 'Maria', 'clinic' => $this->entity->name]);

    $texts = mb_strtolower($message->body . ' ' . json_encode($message->payload, JSON_UNESCAPED_UNICODE) . ' ' . trans('whatsapp.templates.care_recall', [], 'pt_BR') . ' ' . trans('whatsapp.templates.care_recall', [], 'en'));

    foreach (['glaucoma', 'dmri', 'degeneração', 'retinopatia', 'ceratocone', 'hidroxicloroquina', 'campimetria', 'campo visual', 'oct', 'diagnóstico', 'catarata', 'diabetes', 'doença', 'exame', 'cid', 'macular', 'macula', 'ocular', 'visual', 'retina'] as $term) {
        expect(str_contains($texts, $term))->toBeFalse("o texto do convite cita '{$term}'");
    }

    expect($message->body)->toContain('Maria')->toContain($this->entity->name)->toContain('SAIR');
});

it('o 2º convite ao mesmo paciente no mesmo dia é recusado (intervalo mínimo) e não grava nada', function () {
    Queue::fake();

    $this->service->inviteManual((string) $this->entity->id, (string) $this->recall->id, null, $this->now);

    expect(fn () => $this->service->inviteManual((string) $this->entity->id, (string) $this->recall->id, null, $this->now))
        ->toThrow(RecallInviteException::class);

    try {
        $this->service->inviteManual((string) $this->entity->id, (string) $this->recall->id, null, $this->now);
    } catch (RecallInviteException $e) {
        expect($e->reason())->toBe('blocked')
            ->and($e->blockers())->toContain(InviteBlocker::TooSoon);
    }

    expect(RecallInvite::query()->withoutGlobalScopes()->count())->toBe(1)
        ->and(WhatsAppMessage::query()->where('kind', 'recall')->count())->toBe(1);
});

it('a onda cobre todos os retornos do paciente que podem ser convidados', function () {
    Queue::fake();

    $rcf = rcfAdopt($this->entity, 'glaucoma');
    rcfCondition($this->entity, $this->patient, 'glaucoma', Eye::Both, '2025-01-15');
    rcfEvaluate($this->entity, $this->patient, '2026-02-01');

    $invite = $this->service->inviteManual((string) $this->entity->id, (string) $this->recall->id, null, $this->now);

    expect($invite->recalls()->count())->toBeGreaterThan(1)
        ->and($rcf)->not->toBeNull();

    // Um evento por retorno coberto.
    expect(PatientRecallEvent::query()->withoutGlobalScopes()->where('event', 'invite_queued')->count())->toBe($invite->recalls()->count());
});

it('sem permissão de envio nada é gravado: o motivo vem na exceção', function (callable $break, string $expected) {
    Queue::fake();
    $break($this);

    try {
        $this->service->inviteManual((string) $this->entity->id, (string) $this->recall->id, null, $this->now);
        $this->fail('devia recusar');
    } catch (RecallInviteException $e) {
        expect(array_map(fn ($blocker) => $blocker->value, $e->blockers()))->toContain($expected);
    }

    expect(RecallInvite::query()->withoutGlobalScopes()->count())->toBe(0)
        ->and(WhatsAppMessage::query()->count())->toBe(0);
    Queue::assertNothingPushed();
})->with([
    'sem consentimento' => [fn ($t) => DB::table('patient_consents')->where('patient_id', $t->patient->id)->delete(), 'no_consent'],
    'pausa global'      => [fn ($t) => app(RecallKillSwitch::class)->pause('Número queimado na Meta', null, null), 'paused_global'],
    'respondeu SAIR'    => [fn ($t) => WhatsAppOptOut::create(['whatsapp_setting_id' => $t->sender->id, 'phone' => '5511988887777', 'source' => 'keyword', 'opted_out_at' => now()]), 'opted_out'],
    'chave da clínica'  => [fn ($t) => RecallClinicSettings::query()->withoutGlobalScopes()->where('entity_id', $t->entity->id)->update(['whatsapp_invites_enabled' => false]), 'clinic_switch_off'],
]);

it('retorno de outra clínica, ou inexistente, é "não encontrado" (nada vaza)', function () {
    ['entity' => $other] = rcfClinic(recall: true);
    rcfInviteChannel($other);

    expect(fn () => $this->service->inviteManual((string) $other->id, (string) $this->recall->id, null, $this->now))
        ->toThrow(RecallInviteException::class)
        ->and(fn () => $this->service->preview((string) $other->id, (string) $this->recall->id, $this->now))
        ->toThrow(RecallInviteException::class)
        ->and(fn () => $this->service->inviteManual((string) $this->entity->id, (string) Str::uuid(), null, $this->now))
        ->toThrow(RecallInviteException::class);
});

// ── a prévia ────────────────────────────────────────────────────────────────

it('a prévia mostra o texto exato, o telefone mascarado e o ritmo — sem gravar nada', function () {
    $preview = $this->service->preview((string) $this->entity->id, (string) $this->recall->id, $this->now);

    expect($preview['can_send'])->toBeTrue()
        ->and($preview['blockers'])->toBe([])
        ->and($preview['phone'])->toBe('(11) 9****-7777')
        ->and($preview['recalls'])->toBe(1)
        ->and($preview['message']['body'])->toContain('Maria')->toContain($this->entity->name)
        ->and($preview['message']['footer'])->toContain('SAIR')
        ->and($preview['message']['buttons'])->toBe(['Quero agendar', 'Já agendei'])
        ->and($preview['daily'])->toBe(['used' => 0, 'cap' => 100])
        ->and($preview['last_invite_at'])->toBeNull()
        ->and(RecallInvite::query()->withoutGlobalScopes()->count())->toBe(0)
        ->and(json_encode($preview))->not->toContain('5511988887777');
});

it('a prévia explica por que o convite não sai, com o rótulo de cada impedimento', function () {
    DB::table('patient_consents')->where('patient_id', $this->patient->id)->delete();

    $preview = $this->service->preview((string) $this->entity->id, (string) $this->recall->id, $this->now);

    expect($preview['can_send'])->toBeFalse()
        ->and($preview['blockers'])->toBe([['code' => 'no_consent', 'label' => 'O paciente não autorizou lembretes de retorno por WhatsApp.']]);
});

// ── a rodada automática ─────────────────────────────────────────────────────

it('a rodada automática convida o paciente elegível, em nome do sistema (sem usuário)', function () {
    Queue::fake();

    $report = $this->service->runClinic((string) $this->entity->id, $this->now);
    $invite = RecallInvite::query()->withoutGlobalScopes()->sole();

    expect($report)->toBe(['candidates' => 1, 'queued' => 1, 'skipped' => [], 'errors' => 0, 'stopped' => null])
        ->and($invite->origin)->toBe(InviteOrigin::Auto)
        ->and($invite->created_by)->toBeNull()
        ->and(PatientRecallEvent::query()->withoutGlobalScopes()->where('event', 'invite_queued')->sole()->actor_label)->toBe('system');
    Queue::assertPushed(SendWhatsAppMessageJob::class, 1);
});

it('rodar duas vezes no mesmo dia não duplica o convite', function () {
    Queue::fake();

    $this->service->runClinic((string) $this->entity->id, $this->now);
    $second = $this->service->runClinic((string) $this->entity->id, $this->now);

    expect($second['queued'])->toBe(0)
        ->and($second['candidates'])->toBe(0) // quem acabou de ser convidado nem chega a candidato
        ->and(RecallInvite::query()->withoutGlobalScopes()->count())->toBe(1);
});

it('quem não tem consentimento nem WhatsApp não é candidato; o mais atrasado vem primeiro', function () {
    Queue::fake();

    $noConsent = risOtherPatient($this, 'SEM CONSENTIMENTO', '11977770001', consent: false);
    $late      = risOtherPatient($this, 'MAIS ATRASADO', '11977770002', '2025-10-01');
    $noRecall  = rcfInvitePatient($this->entity, 'SEM RETORNO', '11977770003');

    $report = $this->service->runClinic((string) $this->entity->id, $this->now);
    $order  = RecallInvite::query()->withoutGlobalScopes()->orderBy('created_at')->orderBy('id')->pluck('patient_id')->all();

    expect($report['queued'])->toBe(2) // MARIA + MAIS ATRASADO
        ->and($report['candidates'])->toBe(2)
        ->and($order)->toContain((string) $late->id)->toContain((string) $this->patient->id)
        ->and(RecallInvite::query()->withoutGlobalScopes()->whereIn('patient_id', [$noConsent->id, $noRecall->id])->exists())->toBeFalse();
});

it('quem foi convidado há mais que o intervalo mínimo volta a ser candidato da rodada', function () {
    Queue::fake();

    $past = RecallInvite::query()->withoutGlobalScopes()->create(['entity_id' => $this->entity->id, 'patient_id' => $this->patient->id, 'wave_key' => 'antigo', 'origin' => 'auto']);
    DB::table('recall_invites')->where('id', $past->id)->update(['created_at' => CarbonImmutable::now()->subDays(31)]);
    WhatsAppMessage::create(['entity_id' => $this->entity->id, 'patient_id' => $this->patient->id, 'recall_invite_id' => $past->id, 'direction' => 'out', 'kind' => 'recall', 'phone' => '5511988887777', 'body' => 'x', 'status' => 'sent']);

    $report = $this->service->runClinic((string) $this->entity->id, $this->now);

    expect($report['queued'])->toBe(1)
        ->and(RecallInvite::query()->withoutGlobalScopes()->count())->toBe(2);
});

it('o teto diário para a rodada: os demais ficam para amanhã', function () {
    Queue::fake();
    RecallClinicSettings::query()->withoutGlobalScopes()->where('entity_id', $this->entity->id)->update(['daily_invite_cap' => 2]);

    risOtherPatient($this, 'SEGUNDO', '11977770002', '2025-11-01');
    risOtherPatient($this, 'TERCEIRO', '11977770003', '2025-11-15');

    $report = $this->service->runClinic((string) $this->entity->id, $this->now);

    expect($report['queued'])->toBe(2)
        ->and($report['stopped'])->toBe('daily_cap')
        ->and(RecallInvite::query()->withoutGlobalScopes()->count())->toBe(2);

    // No dia seguinte o teto zera e o que sobrou sai.
    $this->travelTo(Carbon::parse('2026-02-02 10:00:00'));
    $next = $this->service->runClinic((string) $this->entity->id, CarbonImmutable::parse('2026-02-02 10:00:00'));

    expect($next['queued'])->toBe(1);
});

it('com o teto do dia curto, as vagas vão para o retorno mais atrasado (o mais antigo primeiro)', function () {
    Queue::fake();
    RecallClinicSettings::query()->withoutGlobalScopes()->where('entity_id', $this->entity->id)->update(['daily_invite_cap' => 1]);

    $oldest = risOtherPatient($this, 'MAIS ANTIGO', '11977770002', '2025-10-20'); // vence em 19/11/2025
    risOtherPatient($this, 'INTERMEDIARIO', '11977770003', '2025-11-20');           // vence em 20/12/2025
    // MARIA (cenário-base) vence em 31/12/2025: a mais nova das três.

    $report = $this->service->runClinic((string) $this->entity->id, $this->now);

    expect($report['queued'])->toBe(1)
        ->and(RecallInvite::query()->withoutGlobalScopes()->sole()->patient_id)->toBe((string) $oldest->id);
});

it('canal fechado ou fora da janela: a rodada nem começa e diz por quê', function () {
    Queue::fake();

    app(RecallKillSwitch::class)->pause('Modelo reprovado pela Meta', null, null);
    expect($this->service->runClinic((string) $this->entity->id, $this->now)['stopped'])->toBe('paused_global');

    app(RecallKillSwitch::class)->resume();
    RecallClinicSettings::query()->withoutGlobalScopes()->where('entity_id', $this->entity->id)->update(['invite_window_start' => '11:00', 'invite_window_end' => '12:00']);

    expect($this->service->runClinic((string) $this->entity->id, $this->now)['stopped'])->toBe('outside_window')
        ->and(RecallInvite::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('plano sem o recurso de retorno: a rodada nem começa e nada é gravado', function () {
    Queue::fake();
    DB::table('plan_features')->where('feature', 'has_patient_recall')->update(['value' => '0']);
    app(FeatureGateService::class)->forgetCache((string) $this->entity->id);

    $report = $this->service->runClinic((string) $this->entity->id, $this->now);

    expect($report['stopped'])->toBe('feature_off')
        ->and($report['queued'])->toBe(0)
        ->and(RecallInvite::query()->withoutGlobalScopes()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('--dry-run mostra o que sairia sem gravar nem enfileirar', function () {
    Queue::fake();

    $report = $this->service->runClinic((string) $this->entity->id, $this->now, dryRun: true);

    expect($report['queued'])->toBe(1)
        ->and(RecallInvite::query()->withoutGlobalScopes()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('quem foi convidado há pouco (ou esgotou o teto do ciclo) sai do topo da fila: os demais são alcançados', function () {
    Queue::fake();
    config()->set('recall.invites.max_per_run_per_clinic', 2);

    foreach ([['A', '11977770001', '2025-09-01'], ['B', '11977770002', '2025-09-02']] as [$name, $phone, $visit]) {
        $old  = risOtherPatient($this, "ANTIGO {$name}", $phone, $visit);
        $past = RecallInvite::query()->withoutGlobalScopes()->create(['entity_id' => $this->entity->id, 'patient_id' => $old->id, 'wave_key' => "antigo-{$name}", 'origin' => 'auto']);
        DB::table('recall_invites')->where('id', $past->id)->update(['created_at' => CarbonImmutable::now()->subDays(5)]);
        WhatsAppMessage::create(['entity_id' => $this->entity->id, 'patient_id' => $old->id, 'recall_invite_id' => $past->id, 'direction' => 'out', 'kind' => 'recall', 'phone' => '5511977770001', 'body' => 'x', 'status' => 'sent']);
    }

    // Os dois mais atrasados já foram convidados há 5 dias: sem o corte, ocupariam as 2 vagas da rodada para sempre.
    $report = $this->service->runClinic((string) $this->entity->id, $this->now);

    expect($report['queued'])->toBe(1)
        ->and(RecallInvite::query()->withoutGlobalScopes()->where('patient_id', $this->patient->id)->exists())->toBeTrue();
});

it('quem o guard recusa (celular fixo, SAIR) não ocupa a vaga da rodada: os elegíveis mais adiante são alcançados', function () {
    Queue::fake();
    config()->set('recall.invites.max_per_run_per_clinic', 2);

    // Os dois mais atrasados são recusados pelo guard (não por filtro do SQL); a vaga da rodada é de ONDA enfileirada.
    risOtherPatient($this, 'TELEFONE FIXO', '1133334444', '2025-08-01');
    $optedOut = risOtherPatient($this, 'DISSE SAIR', '11977770002', '2025-08-02');
    WhatsAppOptOut::create(['whatsapp_setting_id' => $this->sender->id, 'phone' => '5511977770002', 'source' => 'keyword', 'opted_out_at' => now()]);
    $next = risOtherPatient($this, 'ELEGIVEL ADIANTE', '11977770003', '2025-12-02');

    $report = $this->service->runClinic((string) $this->entity->id, $this->now);

    expect($report['queued'])->toBe(2) // MARIA + ELEGIVEL ADIANTE
        ->and($report['skipped'])->toHaveKeys(['invalid_phone', 'opted_out'])
        ->and(RecallInvite::query()->withoutGlobalScopes()->where('patient_id', $next->id)->exists())->toBeTrue()
        ->and(RecallInvite::query()->withoutGlobalScopes()->where('patient_id', $optedOut->id)->exists())->toBeFalse();
});

it('o teto de ondas da rodada é de convites enfileirados: ao chegar nele a rodada para', function () {
    Queue::fake();
    config()->set('recall.invites.max_per_run_per_clinic', 1);

    risOtherPatient($this, 'SEGUNDO', '11977770002', '2025-12-02');

    $report = $this->service->runClinic((string) $this->entity->id, $this->now);

    expect($report['queued'])->toBe(1)
        ->and(RecallInvite::query()->withoutGlobalScopes()->count())->toBe(1);
});

it('o teto do ciclo conta como o guard: convite cancelado/pulado não pesa, enviado pesa', function (string $status, int $queued) {
    Queue::fake();
    RecallClinicSettings::query()->withoutGlobalScopes()->where('entity_id', $this->entity->id)->update(['max_auto_invites_per_cycle' => 2, 'min_days_between_invites' => 7]);

    foreach ([20, 21] as $daysAgo) {
        $at     = CarbonImmutable::now()->subDays($daysAgo);
        $invite = RecallInvite::query()->withoutGlobalScopes()->create([
            'entity_id' => $this->entity->id, 'patient_id' => $this->patient->id, 'origin' => 'auto',
            'wave_key'  => RecallInvite::waveKey((string) $this->patient->id, $at->toDateString()),
        ]);
        DB::table('recall_invites')->where('id', $invite->id)->update(['created_at' => $at]);
        $invite->recalls()->attach((string) $this->recall->id);
        WhatsAppMessage::create([
            'entity_id' => $this->entity->id, 'patient_id' => $this->patient->id, 'recall_invite_id' => $invite->id,
            'direction' => 'out', 'kind' => 'recall', 'phone' => '5511988887777', 'body' => 'x', 'status' => $status,
        ]);
    }

    expect($this->service->runClinic((string) $this->entity->id, $this->now)['queued'])->toBe($queued);
})->with([
    'suprimido' => ['suppressed', 1],
    'pulado'    => ['skipped', 1],
    'enviado'   => ['sent', 0],
]);

it('paciente com bloqueio de contato nem chega a candidato da rodada', function () {
    Queue::fake();
    $blocked = risOtherPatient($this, 'NAO CONTATAR', '11977770002', '2025-10-01');
    PatientRecallBlock::query()->withoutGlobalScopes()->create([
        'entity_id' => $this->entity->id, 'patient_id' => $blocked->id, 'reason' => RecallBlockReason::DoNotContact->value,
    ]);

    $report = $this->service->runClinic((string) $this->entity->id, $this->now);

    expect($report['candidates'])->toBe(1)
        ->and($report['queued'])->toBe(1)
        ->and(RecallInvite::query()->withoutGlobalScopes()->where('patient_id', $blocked->id)->exists())->toBeFalse();
});

// ── o envio de verdade (job, driver mock) ───────────────────────────────────

it('o job envia o convite pelo número da clínica e a mensagem fica enviada', function () {
    $invite  = $this->service->inviteManual((string) $this->entity->id, (string) $this->recall->id, null, $this->now); // fila sync: já foi
    $message = risMessage($invite);

    expect($message->status)->toBe('sent')
        ->and($message->sent_at)->not->toBeNull()
        ->and($message->whatsapp_setting_id)->toBe((string) $this->sender->id)
        ->and($message->provider_message_id)->toStartWith('mock-');
});

it('o job cancela (suppressed) o convite que deixou de valer entre enfileirar e enviar — e não envia', function (callable $change, string $code) {
    Queue::fake();
    $invite = $this->service->inviteManual((string) $this->entity->id, (string) $this->recall->id, null, $this->now);
    $change($this);

    risRunJob(risMessage($invite));

    $message = risMessage($invite)->fresh();

    expect($message->status)->toBe('suppressed')
        ->and($message->error_code)->toBe("recall_{$code}")
        ->and($message->sent_at)->toBeNull()
        ->and($message->provider_message_id)->toBeNull();
})->with([
    'consentimento retirado' => [fn ($t) => DB::table('patient_consents')->where('patient_id', $t->patient->id)->update(['status' => 'revoked', 'revoked_at' => now()]), 'no_consent'],
    'pausa global'           => [fn ($t) => app(RecallKillSwitch::class)->pause('Modelo reprovado pela Meta', null, null), 'paused_global'],
    'respondeu SAIR'         => [fn ($t) => WhatsAppOptOut::create(['whatsapp_setting_id' => $t->sender->id, 'phone' => '5511988887777', 'source' => 'keyword', 'opted_out_at' => now()]), 'opted_out'],
    'retorno dispensado'     => [fn ($t) => DB::table('patient_recalls')->where('id', $t->recall->id)->update(['lifecycle' => 'dismissed']), 'nothing_to_invite'],
    'chave do Manager'       => [fn ($t) => $t->sender->update(['recall_enabled' => false]), 'clinic_not_enabled'],
    // Sem forgetCache de propósito: o worker é um processo longo e o plano lido na criação da onda ainda está no cache.
    'plano sem o recurso' => [fn ($t) => DB::table('plan_features')->where('feature', 'has_patient_recall')->update(['value' => '0']), 'feature_off'],
]);

it('o paciente voltou com o convite na fila: o retorno anda para o ciclo seguinte (segue aberto) e o convite velho é cancelado', function () {
    Queue::fake();
    $invite = $this->service->inviteManual((string) $this->entity->id, (string) $this->recall->id, null, $this->now);

    // Atendimento novo ANTES de o job rodar: o avaliador empurra a MESMA linha para o próximo ciclo (retorno em 90 dias).
    rcfRecord($this->entity, $this->patient, $this->doctor, '2026-02-01', 90);
    rcfEvaluate($this->entity, $this->patient, '2026-02-01');

    $recall = $this->recall->fresh();
    expect($recall->lifecycle->value)->toBe('open')
        ->and($recall->invite_from->toDateString())->toBeGreaterThan('2026-02-01');

    risRunJob(risMessage($invite));

    expect(risMessage($invite)->fresh())
        ->status->toBe('suppressed')
        ->error_code->toBe('recall_nothing_to_invite')
        ->sent_at->toBeNull();
});

it('as datas de convite do retorno são reconferidas no envio: ainda não chegou na janela, já perdido de vista ou (no automático) atrasado demais', function (array $dates, string $origin) {
    Queue::fake();
    $invite = $origin === 'auto'
        ? (function () {
            $this->service->runClinic((string) $this->entity->id, $this->now);

            return RecallInvite::query()->withoutGlobalScopes()->sole();
        })->call($this)
        : $this->service->inviteManual((string) $this->entity->id, (string) $this->recall->id, null, $this->now);

    DB::table('patient_recalls')->where('id', $this->recall->id)->update($dates);

    risRunJob(risMessage($invite));

    expect(risMessage($invite)->fresh())->status->toBe('suppressed')->error_code->toBe('recall_nothing_to_invite');
})->with([
    'ainda não na janela'    => [['invite_from' => '2026-03-01', 'lost_from' => '2026-09-01', 'due_at' => '2026-04-01'], 'manual'],
    'perdido de vista'       => [['invite_from' => '2025-08-01', 'lost_from' => '2026-02-01', 'due_at' => '2025-09-01'], 'manual'],
    'atrasado demais (robô)' => [['due_at' => '2025-06-01'], 'auto'],
]);

it('retorno dentro das datas continua saindo (a reconferência não cancela o convite bom)', function () {
    Queue::fake();
    $invite = $this->service->inviteManual((string) $this->entity->id, (string) $this->recall->id, null, $this->now);

    risRunJob(risMessage($invite));

    expect(risMessage($invite)->fresh())->status->toBe('sent');
});

it('convite automático fora da janela de horário não é cancelado nem enviado: segue pending', function () {
    Queue::fake();
    $invite = $this->service->runClinic((string) $this->entity->id, $this->now);
    expect($invite['queued'])->toBe(1);

    RecallClinicSettings::query()->withoutGlobalScopes()->where('entity_id', $this->entity->id)->update(['invite_window_start' => '11:00', 'invite_window_end' => '12:00']);

    $message = WhatsAppMessage::query()->where('kind', 'recall')->sole();
    risRunJob($message);

    expect($message->fresh()->status)->toBe('pending');
});

// ── recall:send-invites e recall:requeue-pending ────────────────────────────

it('recall:send-invites percorre só as clínicas com o canal ligado nos três níveis', function () {
    Queue::fake();

    ['entity' => $off] = rcfClinic(recall: true);
    rcfInviteChannel($off, ['whatsapp_invites_enabled' => false]);
    $other = rcfInvitePatient($off, 'OUTRA CLINICA', '11977770001');
    rcfOverdueRecall($off, $other, createDoctorForEntity($off));

    $this->artisan('recall:send-invites')->assertSuccessful();

    expect(RecallInvite::query()->withoutGlobalScopes()->where('entity_id', $this->entity->id)->count())->toBe(1)
        ->and(RecallInvite::query()->withoutGlobalScopes()->where('entity_id', $off->id)->count())->toBe(0);

    $this->artisan('recall:send-invites', ['--entity' => (string) $off->id])->assertSuccessful();
    expect(RecallInvite::query()->withoutGlobalScopes()->where('entity_id', $off->id)->count())->toBe(0);
});

it('recall:send-invites com a pausa global não faz nada', function () {
    Queue::fake();
    app(RecallKillSwitch::class)->pause('Modelo reprovado pela Meta', null, null);

    $this->artisan('recall:send-invites')->assertSuccessful()->expectsOutputToContain('pausados');

    expect(RecallInvite::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('recall:requeue-pending reenfileira o pending parado, cancela o que passou do prazo e deixa o recente em paz', function () {
    Queue::fake();

    $make = function (int $minutesAgo, string $status = 'pending') {
        $patient = rcfInvitePatient($this->entity, 'P' . Str::random(4), '119' . random_int(10000000, 99999999));
        $invite  = RecallInvite::query()->withoutGlobalScopes()->create(['entity_id' => $this->entity->id, 'patient_id' => $patient->id, 'wave_key' => 'k-' . Str::random(8), 'origin' => 'auto']);
        $message = WhatsAppMessage::create(['entity_id' => $this->entity->id, 'patient_id' => $patient->id, 'recall_invite_id' => $invite->id, 'direction' => 'out', 'kind' => 'recall', 'phone' => '5511988887777', 'body' => 'x', 'status' => $status]);
        DB::table('whatsapp_messages')->where('id', $message->id)->update(['created_at' => now()->subMinutes($minutesAgo)]);

        return (string) $message->id;
    };

    $fresh   = $make(2);
    $stuck   = $make(30);
    $expired = $make(60 * 24 * 4);
    $sent    = $make(60, 'sent');

    $this->artisan('recall:requeue-pending')->assertSuccessful();

    Queue::assertPushed(SendWhatsAppMessageJob::class, 1);
    Queue::assertPushed(SendWhatsAppMessageJob::class, fn (SendWhatsAppMessageJob $job) => $job->messageId === $stuck);

    expect(WhatsAppMessage::find($fresh)->status)->toBe('pending')
        ->and(WhatsAppMessage::find($stuck)->status)->toBe('pending')
        ->and(WhatsAppMessage::find($expired)->status)->toBe('suppressed')
        ->and(WhatsAppMessage::find($expired)->error_code)->toBe('recall_expired')
        ->and(WhatsAppMessage::find($sent)->status)->toBe('sent');
});
