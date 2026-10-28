<?php

declare(strict_types=1);

namespace App\Domains\Recall\Support;

use App\Domains\Recall\Enums\IntervalUnit;
use Carbon\{CarbonImmutable, CarbonInterface};
use InvalidArgumentException;

/**
 * Matemática de datas de UMA tarefa de protocolo, pura (sem banco, sem relógio): a
 * mesma que o avaliador de recall usa para vencimentos e que a tela do Revisor usa
 * para mostrar exemplos — o que ele aprova é o que o sistema calcula.
 *
 * Convenção dos limites (todas são DIAS, nunca horas):
 *
 *   due_at        âncora + intervalo (mês sem overflow)
 *   invite_from   due_at − lead_days            — 1º dia em que se pode convidar
 *   grace_until   due_at + grace_days           — último dia SEM atraso (tolerância)
 *   overdue_from  grace_until + 1               — 1º dia vencido
 *   lost_after    due_at + lost_after_days      — último dia ainda "vencido"
 *   lost_from     lost_after + 1                — 1º dia "perdido de vista"
 *
 * Os `*_from` são o 1º dia inclusivo do estado; `grace_until`/`lost_after` são o
 * último dia do estado anterior — a tela mostra estes (mais naturais de ler), o
 * avaliador compara com aqueles.
 */
final class TaskTimeline
{
    /**
     * @param array<string, mixed> $task     tarefa normalizada (ProtocolContentSchema)
     * @param CarbonInterface      $anchor   data do último ato (ou do início, na 1ª vez)
     * @param ?int                 $interval intervalo escolhido pela clínica na unidade da tarefa; padrão = default
     *
     * @return array{due_at: CarbonImmutable, invite_from: CarbonImmutable, grace_until: CarbonImmutable, overdue_from: CarbonImmutable, lost_after: CarbonImmutable, lost_from: CarbonImmutable}
     *
     * @throws InvalidArgumentException intervalo fora da faixa validada da tarefa
     */
    public static function from(array $task, CarbonInterface $anchor, ?int $interval = null): array
    {
        $range = (array) $task['interval'];
        $value = $interval ?? (int) $range['default'];

        if ($value < (int) $range['min'] || $value > (int) $range['max']) {
            throw new InvalidArgumentException("Intervalo {$value} fora da faixa validada {$range['min']}–{$range['max']} da tarefa {$task['key']}.");
        }

        $due = IntervalUnit::from((string) $range['unit'])->addTo($anchor, $value);

        $graceUntil = $due->addDays((int) $task['grace_days']);
        $lostAfter  = $due->addDays((int) $task['lost_after_days']);

        return [
            'due_at'       => $due,
            'invite_from'  => $due->subDays((int) $task['lead_days']),
            'grace_until'  => $graceUntil,
            'overdue_from' => $graceUntil->addDay(),
            'lost_after'   => $lostAfter,
            'lost_from'    => $lostAfter->addDay(),
        ];
    }

    /**
     * Quando a tarefa COMEÇA a valer, contado do início da condição (ex.: triagem anual
     * da hidroxicloroquina, 60 meses depois do início do uso). Nulo = vale desde o início.
     *
     * @param array<string, mixed> $task
     */
    public static function startsAt(array $task, CarbonInterface $conditionStart): ?CarbonImmutable
    {
        $months = $task['starts_after_months'] ?? null;

        return $months === null ? null : IntervalUnit::Months->addTo($conditionStart, (int) $months);
    }

    /**
     * A linha do tempo com o INÍCIO da tarefa aplicado: a tarefa que só começa depois de N meses (`startsAt`) não vence
     * antes disso — vence no dia em que passa a valer e os demais prazos andam os mesmos dias. É a conta do avaliador
     * (`RecallRuleEngine`) E a da prévia do Revisor (`RecallPreview`): uma só, para o exemplo que ele aprova ser o que o
     * sistema faz (a prévia mostrava a triagem da hidroxicloroquina vencendo 4 anos antes do início dela).
     *
     * `deferred_to` é o dia em que a tarefa passou a valer quando houve deslocamento, e nulo quando nada mudou (sem início
     * da tarefa, ou o vencimento já cai nele ou depois dele).
     *
     * @param array<string, CarbonImmutable> $timeline o resultado de `from()`
     *
     * @return array{timeline: array<string, CarbonImmutable>, deferred_to: ?CarbonImmutable}
     */
    public static function deferToStart(array $timeline, ?CarbonImmutable $startsAt): array
    {
        if ($startsAt === null || ! $timeline['due_at']->lessThan($startsAt)) {
            return ['timeline' => $timeline, 'deferred_to' => null];
        }

        $shift = $timeline['due_at']->diffInDays($startsAt);

        return [
            'timeline'    => array_map(static fn (CarbonImmutable $date): CarbonImmutable => $date->addDays($shift), $timeline),
            'deferred_to' => $startsAt,
        ];
    }
}
