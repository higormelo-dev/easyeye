<?php

declare(strict_types=1);

namespace App\Domains\Recall\Support;

use Carbon\CarbonInterface;

/**
 * Exemplos de datas de um conteúdo de protocolo, para o Manager e para o Revisor:
 * "se o último atendimento foi em 15/01, esta tarefa vence em 15/05, o convite sai em
 * 15/04…". Usa TaskTimeline — o MESMO cálculo do avaliador de recall, inclusive o
 * início da tarefa (`starts_after_months`: a triagem da hidroxicloroquina só vence
 * 60 meses depois do início do uso) —, então o exemplo que o Revisor lê é o que o
 * sistema faz. Não depende de paciente nem de clínica e nunca entra no hash.
 *
 * A data de exemplo vale como o INÍCIO da condição e como a âncora (o primeiro ciclo, sem ato anterior): é onde o
 * início da tarefa pesa; depois do primeiro ato o vencimento já passa dele.
 */
final class RecallPreview
{
    /**
     * @param array<string, mixed> $content conteúdo normalizado
     *
     * @return list<array<string, mixed>>
     */
    public static function examples(array $content, CarbonInterface $anchor): array
    {
        $examples = [];

        foreach ((array) $content['tasks'] as $task) {
            $starts   = TaskTimeline::startsAt($task, $anchor);
            $timeline = TaskTimeline::deferToStart(TaskTimeline::from($task, $anchor), $starts)['timeline'];

            $examples[] = [
                'key'        => $task['key'],
                'kind'       => $task['kind'],
                'exam_key'   => $task['exam_key'],
                'required'   => $task['required'],
                'recurs'     => $task['recurs'],
                'interval'   => $task['interval'],
                'anchor'     => $anchor->toDateString(),
                'starts_at'  => $starts?->toDateString(),
                'due_at'     => $timeline['due_at']->toDateString(),
                'invite'     => $timeline['invite_from']->toDateString(),
                'grace'      => $timeline['grace_until']->toDateString(),
                'overdue'    => $timeline['overdue_from']->toDateString(),
                'lost_after' => $timeline['lost_after']->toDateString(),
                'lost'       => $timeline['lost_from']->toDateString(),
            ];
        }

        return $examples;
    }
}
