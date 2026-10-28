<?php

declare(strict_types=1);

/**
 * O convite de retorno por WhatsApp para quem está em tratamento com injeções intravítreas (ADR 0002, itens 9 e 14): o
 * retorno do curso não vem de protocolo validado, então a elegibilidade do guard é própria — o convite MANUAL sempre
 * pode (a equipe decide), o AUTOMÁTICO segue a mesma chave do retorno pedido pelo médico. Relógio fixo: 01/02/2026.
 */

use App\Domains\Recall\Enums\{InviteBlocker, InviteOrigin, RecallLifecycle};
use App\Domains\Recall\Intravitreal\Models\IntravitrealCourse;
use App\Domains\Recall\Models\{PatientRecall, RecallClinicSettings, RecallInvite};
use App\Domains\Recall\Services\RecallInviteGuard;
use App\Models\WhatsApp\WhatsAppMessage;
use Carbon\{Carbon, CarbonImmutable};
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
    $this->travelTo(Carbon::parse('2026-02-01 10:00:00'));

    ['entity' => $this->entity, 'doctor' => $this->doctor] = rcfClinic(recall: true);

    rcfInviteChannel($this->entity);
    $this->patient = rcfInvitePatient($this->entity);

    // Curso ativo: última aplicação em 01/12/2025, intervalo decidido de 4 semanas → próxima em 29/12/2025 (em atraso).
    IntravitrealCourse::query()->withoutGlobalScopes()->create([
        'entity_id'              => $this->entity->id, 'patient_id' => $this->patient->id, 'eye' => 'right', 'drug_key' => 'aflibercept', 'drug_label' => 'Aflibercepte',
        'regimen'                => 'loading_tae', 'status' => 'active', 'started_on' => '2025-10-01', 'last_applied_on' => '2025-12-01',
        'current_interval_weeks' => 4, 'next_due_on' => '2025-12-29',
    ]);

    rcfEvaluate($this->entity, $this->patient, '2026-02-01');

    $this->recall = PatientRecall::query()->withoutGlobalScopes()->where('patient_id', $this->patient->id)->where('source', 'course')->firstOrFail();
    $this->now    = CarbonImmutable::parse('2026-02-01 10:00:00');
});

function iriDecide(object $test, InviteOrigin $origin)
{
    return app(RecallInviteGuard::class)->decide((string) $test->entity->id, (string) $test->patient->id, $origin, $test->now);
}

function iriBlockers(object $decision): array
{
    return array_map(static fn ($blocker): string => $blocker->value, $decision->blockers);
}

it('o retorno do curso é convidável (manual e automático) sem nenhum protocolo adotado', function () {
    expect($this->recall->state(CarbonImmutable::parse('2026-02-01'))->value)->toBe('overdue')
        ->and(iriBlockers(iriDecide($this, InviteOrigin::Manual)))->toBe([])
        ->and(iriBlockers(iriDecide($this, InviteOrigin::Auto)))->toBe([]);
});

it('com o retorno pedido pelo médico desligado, o convite AUTOMÁTICO do curso para; o manual segue', function () {
    RecallClinicSettings::query()->withoutGlobalScopes()->where('entity_id', $this->entity->id)->update(['doctor_order_enabled' => false]);

    expect(iriBlockers(iriDecide($this, InviteOrigin::Auto)))->not->toBe([])
        ->and(iriBlockers(iriDecide($this, InviteOrigin::Manual)))->toBe([]);
});

it('curso encerrado não gera convite: o retorno se encerra e não sobra o que convidar', function () {
    IntravitrealCourse::query()->withoutGlobalScopes()->where('patient_id', $this->patient->id)->update(['status' => 'discontinued', 'next_due_on' => null, 'ended_on' => '2026-01-20']);
    rcfEvaluate($this->entity, $this->patient, '2026-02-01');

    expect(iriBlockers(iriDecide($this, InviteOrigin::Manual)))->not->toBe([]);
});

/** O curso do paciente, direto no banco (sem o avaliador): é o que a equipe médica faz e o espelho ainda não viu. */
function iriCourse(object $test, array $changes): void
{
    IntravitrealCourse::query()->withoutGlobalScopes()->where('patient_id', $test->patient->id)->update($changes);
}

/** A mensagem de um convite já enfileirado que cobre o retorno do curso. */
function iriQueued(object $test, string $origin = 'manual'): WhatsAppMessage
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

it('curso encerrado ou pausado ANTES de o avaliador rodar: a linha persistida segue aberta, mas o convite não sai (a decisão olha o curso AGORA)', function (string $case) {
    match ($case) {
        'concluído'    => iriCourse($this, ['status' => 'completed', 'next_due_on' => null, 'ended_on' => '2026-01-30']),
        'interrompido' => iriCourse($this, ['status' => 'discontinued', 'next_due_on' => null, 'ended_on' => '2026-01-30']),
        'pausado'      => iriCourse($this, ['status' => 'paused']),
        'sem próxima'  => iriCourse($this, ['next_due_on' => null]),
        'excluído'     => iriCourse($this, ['deleted_at' => now()]),
    };

    // O avaliador não rodou: o espelho ainda mostra o retorno aberto.
    expect($this->recall->fresh()->lifecycle)->toBe(RecallLifecycle::Open);

    foreach ([InviteOrigin::Manual, InviteOrigin::Auto] as $origin) {
        $decision = iriDecide($this, $origin);

        expect($decision->recalls)->toBe([])
            ->and(iriBlockers($decision))->toBe(['nothing_to_invite']);
    }
})->with(['concluído', 'interrompido', 'pausado', 'sem próxima', 'excluído']);

it('stillEligible: curso encerrado depois de enfileirar o convite cancela o envio', function () {
    $message = iriQueued($this);

    expect(app(RecallInviteGuard::class)->stillEligible($message, $this->now))->toBeNull();

    iriCourse($this, ['status' => 'completed', 'next_due_on' => null, 'ended_on' => '2026-02-01']);

    expect($this->recall->fresh()->lifecycle)->toBe(RecallLifecycle::Open)
        ->and(app(RecallInviteGuard::class)->stillEligible($message->fresh(), $this->now))->toBe(InviteBlocker::NothingToInvite);
});

it('o retorno de OUTRO curso do paciente não vale pelo curso ativo (o convite é do curso daquele retorno)', function () {
    $other = IntravitrealCourse::query()->withoutGlobalScopes()->create([
        'entity_id'              => $this->entity->id, 'patient_id' => $this->patient->id, 'eye' => 'left', 'drug_key' => 'aflibercept', 'drug_label' => 'Aflibercepte',
        'regimen'                => 'loading_tae', 'status' => 'active', 'started_on' => '2025-10-01', 'last_applied_on' => '2025-12-01',
        'current_interval_weeks' => 4, 'next_due_on' => '2025-12-29',
    ]);
    rcfEvaluate($this->entity, $this->patient, '2026-02-01');

    // O curso do olho DIREITO acabou; o do esquerdo segue ativo. O retorno do direito não pode sair "pegando carona".
    $right = IntravitrealCourse::query()->withoutGlobalScopes()->where('patient_id', $this->patient->id)->where('eye', 'right')->firstOrFail();
    $right->forceFill(['status' => 'completed', 'next_due_on' => null, 'ended_on' => '2026-01-30'])->saveQuietly();

    $recalls = iriDecide($this, InviteOrigin::Manual)->recalls;

    expect($recalls)->toHaveCount(1)
        ->and((string) $recalls[0]->task_key)->toBe('course:' . $other->id);
});
