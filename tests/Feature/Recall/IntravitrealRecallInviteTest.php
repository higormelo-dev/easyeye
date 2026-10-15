<?php

declare(strict_types=1);

/**
 * O convite de retorno por WhatsApp para quem está em tratamento com injeções intravítreas (ADR 0002, itens 9 e 14): o
 * retorno do curso não vem de protocolo validado, então a elegibilidade do guard é própria — o convite MANUAL sempre
 * pode (a equipe decide), o AUTOMÁTICO segue a mesma chave do retorno pedido pelo médico. Relógio fixo: 01/02/2026.
 */

use App\Domains\Recall\Enums\InviteOrigin;
use App\Domains\Recall\Intravitreal\Models\IntravitrealCourse;
use App\Domains\Recall\Models\{PatientRecall, RecallClinicSettings};
use App\Domains\Recall\Services\RecallInviteGuard;
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
