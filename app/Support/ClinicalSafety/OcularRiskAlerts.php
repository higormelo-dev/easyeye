<?php

declare(strict_types=1);

namespace App\Support\ClinicalSafety;

use Carbon\CarbonInterface;

/**
 * Alertas de risco ocular (Fase 2 / A2): medicação em uso cujo princípio
 * ativo está numa regra de `config/clinical_safety.php` (alfa-1 bloqueador →
 * íris flácida na catarata etc.). Sem regra que bata, nada — a tela não
 * mostra "sem alertas".
 *
 * Cada entrada é uma medicação: o princípio ativo (ou, sem ele, o nome
 * livre — "Tansulosina 0,4 mg" digitado também alerta) e o rótulo exibido.
 */
final class OcularRiskAlerts
{
    /**
     * Medicação SUSPENSA (`suspended_on`) só alerta nas regras cujo risco
     * persiste depois de parar (`persists_after_stop` no config): sempre, ou
     * até N dias após a suspensão. Descartada nem chega aqui.
     *
     * @param iterable<array{text: ?string, label: string, origin?: string, suspended_on?: ?CarbonInterface}> $entries
     *
     * @return list<array{key: string, group: string, alert: string, source: string, medications: list<string>, origin: string}>
     */
    public static function for(iterable $entries): array
    {
        $rules = ClinicalRules::riskRules();
        $hits  = [];

        foreach ($entries as $entry) {
            $core = TermNormalizer::core($entry['text'] ?? '');

            if ($core === '') {
                continue;
            }

            foreach ($rules as $key => $rule) {
                if (! self::stillAtRisk($rule, $entry['suspended_on'] ?? null)) {
                    continue;
                }

                foreach ($rule['ingredients'] as $ingredient) {
                    if (! TermNormalizer::contains($core, $ingredient)) {
                        continue;
                    }

                    $hits[$key] ??= [
                        'key'         => $key,
                        'group'       => $rule['group'],
                        'alert'       => $rule['alert'],
                        'source'      => $rule['source'],
                        'medications' => [],
                        // Lista estruturada vence o texto livre (o médico já confirmou).
                        'origin' => $entry['origin'] ?? 'list',
                    ];

                    if (! in_array($entry['label'], $hits[$key]['medications'], true)) {
                        $hits[$key]['medications'][] = $entry['label'];
                    }

                    if (($entry['origin'] ?? 'list') === 'list') {
                        $hits[$key]['origin'] = 'list';
                    }

                    break;
                }
            }
        }

        return array_values($hits);
    }

    /** @param array{persists_after_stop?: true|int|null} $rule */
    private static function stillAtRisk(array $rule, ?CarbonInterface $suspendedOn): bool
    {
        if ($suspendedOn === null) {
            return true;
        }

        $persists = $rule['persists_after_stop'] ?? null;

        return $persists === true
            || (is_int($persists) && $suspendedOn->copy()->startOfDay()->addDays($persists)->gte(today()));
    }
}
