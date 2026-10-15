<?php

declare(strict_types=1);

namespace App\Support\Surgery;

use Carbon\{Carbon, CarbonInterface};

/**
 * Data/hora informada pela tela, no fuso da APLICAÇÃO.
 *
 * A tela manda `new Date(x).toISOString()` (termina em `Z`, UTC). `Carbon::parse`
 * mantém o fuso UTC e o Eloquent grava o relógio do próprio Carbon sem converter:
 * 12:30 local virava 15:30 no banco (3 h adiantado em America/Sao_Paulo) —
 * horário de implante (RDC 556), de cirurgia e de avaliação errado, e "data
 * futura" para o que a própria tela preencheu. Todo horário digitado no módulo
 * cirúrgico passa por aqui.
 */
final class LocalTime
{
    public static function parse(CarbonInterface|string|null $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Carbon::parse($value)->setTimezone((string) config('app.timezone'));
    }
}
