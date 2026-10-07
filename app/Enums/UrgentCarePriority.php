<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Prioridade na fila do pronto atendimento (sem classificação de risco
 * completa — decisão do dono). Ordem da fila: menor valor primeiro, depois a
 * chegada.
 *
 *  - Imediata: risco à visão agora (queimadura química, trauma, perda súbita
 *    de visão) — passa na frente de todos.
 *  - Prioritária por lei (Lei 10.048/2000 e Estatuto da Pessoa Idosa): pessoas
 *    com 60 anos ou mais, gestantes, lactantes, pessoas com criança de colo,
 *    pessoas com deficiência, pessoas com mobilidade reduzida e obesos.
 *  - Normal.
 */
enum UrgentCarePriority: int
{
    case Immediate = 1;
    case Legal     = 2;
    case Normal    = 3;

    /** Idade a partir da qual a prioridade legal é sugerida. */
    public const LEGAL_PRIORITY_AGE = 60;

    public function label(): string
    {
        return __('urgent_care.priority_' . $this->key());
    }

    public function hint(): string
    {
        return __('urgent_care.priority_' . $this->key() . '_hint');
    }

    public function key(): string
    {
        return match ($this) {
            self::Immediate => 'immediate',
            self::Legal     => 'legal',
            self::Normal    => 'normal',
        };
    }

    /** @return list<array{value: int, key: string, label: string, hint: string}> */
    public static function options(): array
    {
        return array_map(fn (self $p) => [
            'value' => $p->value,
            'key'   => $p->key(),
            'label' => $p->label(),
            'hint'  => $p->hint(),
        ], self::cases());
    }
}
