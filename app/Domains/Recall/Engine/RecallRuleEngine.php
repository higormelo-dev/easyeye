<?php

declare(strict_types=1);

namespace App\Domains\Recall\Engine;

use App\Domains\Recall\Enums\{IntervalUnit, RecallTaskKind};
use App\Domains\Recall\Support\TaskTimeline;
use App\Enums\Eye;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * O motor de datas do recall (ADR 0002): puro — recebe a condição (com o protocolo que a clínica
 * adotou), os fatos do paciente e o dia de hoje, e devolve o que está pendente. Sem banco, sem
 * relógio, sem efeito colateral: o avaliador (RecallEvaluator) carrega os fatos e grava o resultado.
 *
 * A conta das datas é a de `TaskTimeline` — a MESMA que a tela do Revisor usa nos exemplos —, então
 * o que ele aprovou é o que o sistema faz. Regras que ficam aqui:
 *  - o "último feito" é o ato mais recente DESDE o início da condição (ato antes do diagnóstico não é
 *    parte deste acompanhamento) e nunca no futuro;
 *  - sem ato nenhum, a âncora é o início da condição;
 *  - tarefa que só começa depois de N meses não vence antes disso (`starts_after_months`);
 *  - tarefa de uma vez só (`recurs: false`) já feita não tem próximo vencimento;
 *  - exame em AO só vale com os dois olhos cobertos (a data é a do olho mais antigo);
 *  - tarefa opcional só roda se a clínica a ligou.
 */
final class RecallRuleEngine
{
    /**
     * @return list<RecallItem> um item por tarefa ligada; `treatment_course` fica para a série de aplicações
     */
    public function evaluateCondition(RecallCondition $condition, RecallFacts $facts, CarbonImmutable $today): array
    {
        $items = [];

        foreach ((array) $condition->content['tasks'] as $task) {
            $kind = RecallTaskKind::from((string) $task['kind']);

            if ($kind === RecallTaskKind::TreatmentCourse || ! $condition->isEnabled($task)) {
                continue;
            }

            $items[] = $this->task($condition, $task, $kind, $facts, $today->startOfDay());
        }

        return $items;
    }

    /**
     * O retorno que o MÉDICO pediu no último atendimento (`follow_up_days`): vence na data do atendimento +
     * dias. `$cap` é o vencimento máximo que os protocolos adotados aceitam para o retorno pedido
     * (`doctor_order.cap_to_max`): acima dele o pedido é LIMITADO e o item sai sinalizado.
     *
     * @param array{lead_days: int, grace_days: int, lost_after_days: int} $operational prazos operacionais (config)
     */
    public function doctorOrder(?CarbonImmutable $consultedOn, ?int $followUpDays, ?CarbonImmutable $cap, array $operational, CarbonImmutable $today): ?RecallItem
    {
        if ($consultedOn === null || $followUpDays === null || $followUpDays < 1) {
            return null;
        }

        $anchor = $consultedOn->startOfDay();
        $asked  = $anchor->addDays($followUpDays);
        $due    = $cap !== null && $asked->greaterThan($cap->startOfDay()) ? $cap->startOfDay() : $asked;
        $grace  = $due->addDays($operational['grace_days']);
        $lost   = $due->addDays($operational['lost_after_days']);

        return new RecallItem(
            RecallItem::SOURCE_DOCTOR_ORDER,
            null,
            RecallItem::TASK_DOCTOR_ORDER,
            RecallItem::TASK_DOCTOR_ORDER,
            null,
            null,
            $anchor,
            $anchor,
            $due,
            $due->subDays($operational['lead_days']),
            $grace->addDay(),
            $lost->addDay(),
            true,
            false,
            false,
            ['follow_up_days' => $followUpDays, 'capped' => $due->notEqualTo($asked), 'asked_due_at' => $asked->toDateString()],
        );
    }

    /**
     * A próxima aplicação de um curso de injeções intravítreas: vence no dia que o médico decidiu (`$nextDue` = última
     * aplicação + intervalo decidido). Não depende de protocolo validado — é o retorno que o médico pediu para o
     * tratamento do olho. Prazos operacionais mais curtos que os da consulta de rotina: aplicação atrasada pesa mais.
     *
     * @param array{lead_days: int, grace_days: int, lost_after_days: int} $operational
     */
    public function courseItem(string $courseId, Eye $eye, string $drug, ?CarbonImmutable $lastApplied, CarbonImmutable $startedOn, CarbonImmutable $nextDue, ?int $intervalWeeks, array $operational): RecallItem
    {
        $due    = $nextDue->startOfDay();
        $anchor = ($lastApplied ?? $startedOn)->startOfDay();
        $grace  = $due->addDays($operational['grace_days']);
        $lost   = $due->addDays($operational['lost_after_days']);

        return new RecallItem(
            RecallItem::SOURCE_COURSE,
            null,
            RecallItem::courseTaskKey($courseId),
            RecallTaskKind::TreatmentCourse->value,
            null,
            $eye,
            $lastApplied?->startOfDay(),
            $anchor,
            $due,
            $due->subDays($operational['lead_days']),
            $grace->addDay(),
            $lost->addDay(),
            true,
            false,
            false,
            ['course_id' => $courseId, 'drug' => $drug, 'interval_weeks' => $intervalWeeks],
        );
    }

    /**
     * O vencimento mais distante que o protocolo aceita para o retorno pedido pelo médico, ou nulo se ele não
     * limita (`cap_to_max` desligado ou sem tarefa de consulta): a âncora + o intervalo MÁXIMO validado da
     * consulta.
     */
    public function consultationCap(RecallCondition $condition, CarbonImmutable $anchor): ?CarbonImmutable
    {
        if (($condition->content['doctor_order']['cap_to_max'] ?? false) !== true) {
            return null;
        }

        foreach ((array) $condition->content['tasks'] as $task) {
            if ($task['kind'] === RecallTaskKind::Consultation->value) {
                return IntervalUnit::from((string) $task['interval']['unit'])->addTo($anchor, (int) $task['interval']['max']);
            }
        }

        return null;
    }

    /** @param array<string, mixed> $task */
    private function task(RecallCondition $condition, array $task, RecallTaskKind $kind, RecallFacts $facts, CarbonImmutable $today): RecallItem
    {
        $key     = (string) $task['key'];
        $examKey = $kind === RecallTaskKind::Exam ? (string) $task['exam_key'] : null;
        $start   = $condition->startedAt?->startOfDay();
        $recurs  = (bool) $task['recurs'];

        if ($start === null) {
            return RecallItem::missingData($condition->id, $key, $kind->value, $examKey, $condition->eye, 'started_on');
        }

        $lastDone = $kind === RecallTaskKind::Exam
            ? $facts->lastExam((string) $examKey, $condition->eye, $start, $today)
            : $facts->lastConsultation($start, $today);

        // Uma tarefa de uma vez só que já foi feita não tem próximo vencimento.
        if (! $recurs && $lastDone !== null) {
            return new RecallItem(RecallItem::SOURCE_PROTOCOL, $condition->id, $key, $kind->value, $examKey, $condition->eye, $lastDone, $lastDone, null, null, null, null, false, true, false, ['reason' => 'done_once']);
        }

        $anchor            = $lastDone ?? $start;
        [$timeline, $meta] = $this->timeline($condition, $task, $anchor);

        // A tarefa só passa a valer em `starts_at`: vence nesse dia (e os demais prazos andam junto) — a conta da prévia do Revisor.
        ['timeline' => $timeline, 'deferred_to' => $deferredTo] = TaskTimeline::deferToStart($timeline, TaskTimeline::startsAt($task, $start));

        if ($deferredTo !== null) {
            $meta['starts_at'] = $deferredTo->toDateString();
        }

        return new RecallItem(
            RecallItem::SOURCE_PROTOCOL,
            $condition->id,
            $key,
            $kind->value,
            $examKey,
            $condition->eye,
            $lastDone,
            $anchor,
            $timeline['due_at'],
            $timeline['invite_from'],
            $timeline['overdue_from'],
            $timeline['lost_from'],
            $recurs,
            false,
            false,
            $meta + ['anchor' => $anchor->toDateString(), 'anchor_is_start' => $lastDone === null],
        );
    }

    /**
     * As datas da tarefa com o intervalo da clínica. Intervalo fora da faixa validada (parâmetro adulterado,
     * migração mal feita) NUNCA derruba a avaliação nem usa um número não validado: cai no padrão e sinaliza.
     *
     * @param array<string, mixed> $task
     *
     * @return array{0: array<string, CarbonImmutable>, 1: array<string, mixed>}
     */
    private function timeline(RecallCondition $condition, array $task, CarbonImmutable $anchor): array
    {
        $interval = $condition->intervalFor((string) $task['key']);

        try {
            $timeline = TaskTimeline::from($task, $anchor, $interval);

            return [$timeline, ['interval' => $interval ?? (int) $task['interval']['default'], 'unit' => $task['interval']['unit']]];
        } catch (InvalidArgumentException) {
            return [
                TaskTimeline::from($task, $anchor),
                ['interval' => (int) $task['interval']['default'], 'unit' => $task['interval']['unit'], 'interval_fallback' => true],
            ];
        }
    }
}
