<?php

declare(strict_types=1);

namespace App\Services\Financial\DoctorPayouts;

use App\Support\Money;

/**
 * Divisão do valor do GRUPO entre os participantes de uma regra (E4) — PHP
 * puro, determinístico:
 *  - cada parte = % do grupo com meio centavo para cima (Money::percentageOf);
 *  - o resto (± centavos) vai para o participante de MAIOR % (empate: o
 *    primeiro na ordem da regra) — a soma das partes fecha com o grupo.
 *
 * Ex.: recebido 1.000, regra 60% para o grupo → grupo 600, clínica 400;
 * líder 60% → 360, executor 40% → 240.
 */
final class DoctorPayoutSplit
{
    /** Regra sem participantes: o executor fica com o grupo inteiro. */
    public const EXECUTOR_ONLY = [['role' => 'executor', 'doctor_id' => null, 'percentage' => '100.00']];

    /**
     * @param list<array{role: string, doctor_id: ?string, percentage: string}> $participants
     *
     * @return list<int> centavos por participante, na mesma ordem
     */
    public static function shares(int $groupCents, array $participants): array
    {
        if ($participants === []) {
            return [];
        }

        $shares = array_map(fn (array $participant) => Money::percentageOf($groupCents, $participant['percentage']), $participants);
        $rest   = $groupCents - array_sum($shares);

        if ($rest !== 0) {
            $largest = 0;

            foreach ($participants as $index => $participant) {
                if (Money::toCents($participant['percentage']) > Money::toCents($participants[$largest]['percentage'])) {
                    $largest = $index;
                }
            }

            $shares[$largest] += $rest;
        }

        return $shares;
    }
}
