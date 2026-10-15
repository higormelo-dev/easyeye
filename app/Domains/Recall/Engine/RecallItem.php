<?php

declare(strict_types=1);

namespace App\Domains\Recall\Engine;

use App\Enums\Eye;
use Carbon\CarbonImmutable;

/**
 * O que o motor decidiu sobre UMA tarefa de retorno de um paciente: datas e o porquê, sem banco.
 * O avaliador persiste isto em `patient_recalls`; nada aqui depende do relógio (o estado do prazo
 * é calculado com `state($hoje)`).
 */
final class RecallItem
{
    public const SOURCE_PROTOCOL = 'protocol';

    public const SOURCE_DOCTOR_ORDER = 'doctor_order';

    /** A próxima aplicação de um curso de injeções intravítreas (anti-VEGF): o intervalo é decisão do médico. */
    public const SOURCE_COURSE = 'course';

    /** Tarefa do retorno pedido pelo médico (não vem de protocolo). */
    public const TASK_DOCTOR_ORDER = 'doctor_order';

    /**
     * @param array<string, mixed> $meta o que explica a conta (âncora, intervalo usado, limite aplicado...) — vai para o evento
     */
    public function __construct(
        public readonly string $source,
        public readonly ?string $conditionId,
        public readonly string $taskKey,
        public readonly string $kind,
        public readonly ?string $examKey,
        public readonly ?Eye $eye,
        public readonly ?CarbonImmutable $lastDoneAt,
        public readonly ?CarbonImmutable $anchor,
        public readonly ?CarbonImmutable $dueAt,
        public readonly ?CarbonImmutable $inviteFrom,
        public readonly ?CarbonImmutable $overdueFrom,
        public readonly ?CarbonImmutable $lostFrom,
        /** Tarefa que se repete depois de feita? */
        public readonly bool $recurs = true,
        /** Tarefa de uma vez só que já foi feita: não há próximo vencimento. */
        public readonly bool $completed = false,
        /** Falta um dado para calcular (ex.: início do uso do medicamento). */
        public readonly bool $needsInfo = false,
        public readonly array $meta = [],
    ) {
    }

    /** Não dá para calcular as datas: a tela pede o dado que falta. */
    public static function missingData(string $conditionId, string $taskKey, string $kind, ?string $examKey, Eye $eye, string $missing): self
    {
        return new self(self::SOURCE_PROTOCOL, $conditionId, $taskKey, $kind, $examKey, $eye, null, null, null, null, null, null, true, false, true, ['missing' => $missing]);
    }

    /** O estado do prazo hoje (nulo quando não há datas: completa ou falta dado). */
    public function state(CarbonImmutable $today): ?RecallState
    {
        if ($this->completed || $this->needsInfo || $this->dueAt === null) {
            return null;
        }

        return RecallState::at($today, $this->inviteFrom, $this->dueAt, $this->overdueFrom, $this->lostFrom);
    }
}
