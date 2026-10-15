<?php

declare(strict_types=1);

use App\Domains\Recall\Support\{ProposedProtocolDrafts, ProtocolContentSchema, TaskTimeline};
use Carbon\{Carbon, CarbonImmutable};
use Tests\TestCase;

uses(TestCase::class);

/** @return array<string, mixed> tarefa normalizada */
function ttTask(array $overrides = []): array
{
    $content = ProtocolContentSchema::normalize(ProposedProtocolDrafts::all()['glaucoma']['content']);

    return array_replace_recursive($content['tasks'][0], $overrides); // consulta: 4 meses [3–6], 30/30/180
}

/** @return array<string, string> datas da linha do tempo no formato Y-m-d */
function ttDates(array $timeline): array
{
    return array_map(fn (CarbonImmutable $date) => $date->toDateString(), $timeline);
}

it('calcula vencimento e limites a partir da última consulta (4 meses, convite 30 dias antes, tolerância 30, perdido após 180)', function () {
    $timeline = TaskTimeline::from(ttTask(), CarbonImmutable::parse('2026-01-15'));

    expect(ttDates($timeline))->toBe([
        'due_at'       => '2026-05-15',
        'invite_from'  => '2026-04-15',
        'grace_until'  => '2026-06-14',
        'overdue_from' => '2026-06-15',
        'lost_after'   => '2026-11-11',
        'lost_from'    => '2026-11-12',
    ]);
});

it('mês SEM overflow: 31/01 + 4 meses é 31/05, 31/10 + 4 meses é 28/02, 29/02 + 12 meses é 28/02', function () {
    expect(TaskTimeline::from(ttTask(), CarbonImmutable::parse('2026-01-31'))['due_at']->toDateString())->toBe('2026-05-31')
        ->and(TaskTimeline::from(ttTask(), CarbonImmutable::parse('2025-10-31'))['due_at']->toDateString())->toBe('2026-02-28')
        ->and(TaskTimeline::from(ttTask(['interval' => ['unit' => 'months', 'default' => 12, 'min' => 12, 'max' => 12]]), CarbonImmutable::parse('2024-02-29'))['due_at']->toDateString())->toBe('2025-02-28')
        ->and(TaskTimeline::from(ttTask(['interval' => ['default' => 6, 'min' => 6, 'max' => 6]]), CarbonImmutable::parse('2026-08-31'))['due_at']->toDateString())->toBe('2027-02-28');
});

it('semanas e dias', function () {
    $weeks = ttTask(['interval' => ['unit' => 'weeks', 'default' => 8, 'min' => 4, 'max' => 16]]);
    $days  = ttTask(['interval' => ['unit' => 'days', 'default' => 10, 'min' => 1, 'max' => 30]]);

    expect(TaskTimeline::from($weeks, CarbonImmutable::parse('2026-01-01'))['due_at']->toDateString())->toBe('2026-02-26')
        ->and(TaskTimeline::from($days, CarbonImmutable::parse('2026-01-01'))['due_at']->toDateString())->toBe('2026-01-11');
});

it('o intervalo da clínica vale dentro da faixa validada e é recusado fora dela', function () {
    expect(TaskTimeline::from(ttTask(), CarbonImmutable::parse('2026-01-15'), 3)['due_at']->toDateString())->toBe('2026-04-15')
        ->and(TaskTimeline::from(ttTask(), CarbonImmutable::parse('2026-01-15'), 6)['due_at']->toDateString())->toBe('2026-07-15');

    foreach ([2, 7, 0, -1] as $outside) {
        expect(fn () => TaskTimeline::from(ttTask(), CarbonImmutable::parse('2026-01-15'), $outside))
            ->toThrow(InvalidArgumentException::class);
    }
});

it('os limites seguem os prazos da tarefa, inclusive tolerância zero', function () {
    $timeline = TaskTimeline::from(ttTask(['lead_days' => 0, 'grace_days' => 0, 'lost_after_days' => 1]), CarbonImmutable::parse('2026-01-15'));

    expect(ttDates($timeline))->toBe([
        'due_at'       => '2026-05-15',
        'invite_from'  => '2026-05-15',
        'grace_until'  => '2026-05-15',
        'overdue_from' => '2026-05-16',
        'lost_after'   => '2026-05-16',
        'lost_from'    => '2026-05-17',
    ]);
});

it('só conta o dia: a hora da âncora é descartada e a data recebida não é alterada', function () {
    $anchor = Carbon::parse('2026-01-15 23:59:59');

    $timeline = TaskTimeline::from(ttTask(), $anchor);

    expect($timeline['due_at']->toDateTimeString())->toBe('2026-05-15 00:00:00')
        ->and($anchor->toDateTimeString())->toBe('2026-01-15 23:59:59')
        ->and($timeline['due_at'])->toBeInstanceOf(CarbonImmutable::class);
});

it('startsAt: início da tarefa contado do início da condição; nulo quando vale desde o começo', function () {
    $hcq = ProtocolContentSchema::normalize(ProposedProtocolDrafts::all()['hidroxicloroquina']['content']);
    $by  = array_column($hcq['tasks'], null, 'key');

    expect(TaskTimeline::startsAt($by['triagem_campimetria'], CarbonImmutable::parse('2021-03-31'))?->toDateString())->toBe('2026-03-31')
        ->and(TaskTimeline::startsAt($by['basal_campimetria'], CarbonImmutable::parse('2021-03-31')))->toBeNull()
        // 31/08/2020 + 60 meses = 31/08/2025; 29/02/2020 + 60 meses = 28/02/2025 (sem overflow)
        ->and(TaskTimeline::startsAt($by['triagem_oct_macula'], CarbonImmutable::parse('2020-02-29'))?->toDateString())->toBe('2025-02-28');
});
