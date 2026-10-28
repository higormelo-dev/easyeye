<?php

declare(strict_types=1);

namespace App\Domains\Recall\Engine;

use App\Enums\Eye;
use Carbon\CarbonImmutable;

/**
 * Uma condição crônica ATIVA de um paciente, com o protocolo que a clínica adotou, como o motor a
 * lê: dado puro, sem banco. `content` é o conteúdo NORMALIZADO da versão adotada; `parameters`, o
 * que a clínica ajustou DENTRO da faixa validada (`tasks.<chave>.interval`, `tasks.<chave>.enabled`).
 */
final class RecallCondition
{
    /**
     * @param array<string, mixed> $content
     * @param array<string, mixed> $parameters
     */
    public function __construct(
        public readonly string $id,
        public readonly string $protocolKey,
        public readonly Eye $eye,
        /** Início da condição (data da evidência / início do uso do medicamento); nulo = não se sabe. */
        public readonly ?CarbonImmutable $startedAt,
        public readonly array $content,
        public readonly array $parameters = [],
    ) {
    }

    /** O intervalo que a clínica escolheu para a tarefa (nulo = o padrão validado). */
    public function intervalFor(string $taskKey): ?int
    {
        $value = $this->parameters['tasks'][$taskKey]['interval'] ?? null;

        return is_int($value) ? $value : null;
    }

    /**
     * A tarefa está ligada para esta clínica? Obrigatória sempre está; opcional só se a clínica a
     * ligou (padrão desligada — "a clínica escolhe").
     *
     * @param array<string, mixed> $task
     */
    public function isEnabled(array $task): bool
    {
        return self::taskEnabled($task, $this->parameters);
    }

    /**
     * A MESMA conta, sem precisar de uma condição: quem confere "esta tarefa ainda é calculada?" fora do motor (o guard
     * do convite, que lê a linha de retorno persistida e não pode confiar nela) usa esta função, não uma cópia. Só o
     * booleano `true` liga (string "true", 1 ou ausente = desligada).
     *
     * @param array<string, mixed> $task       a tarefa do conteúdo normalizado do protocolo
     * @param array<string, mixed> $parameters `RecallClinicProtocol::$parameters` (`tasks.<chave>.enabled`)
     */
    public static function taskEnabled(array $task, array $parameters): bool
    {
        if (($task['required'] ?? false) === true) {
            return true;
        }

        return ($parameters['tasks'][$task['key']]['enabled'] ?? false) === true;
    }
}
