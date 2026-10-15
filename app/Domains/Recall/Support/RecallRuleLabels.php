<?php

declare(strict_types=1);

namespace App\Domains\Recall\Support;

use App\Domains\Recall\Enums\{IntervalUnit, RecallExamKey, RecallTaskKind};

/**
 * Textos que os componentes compartilhados de regra (RecallRuleCard, diff, exemplos)
 * recebem: o arquivo `recall_rule` + os rótulos dos enums, tirados DOS PRÓPRIOS enums
 * (fonte única — a tela nunca repete uma lista de rótulos que o servidor já tem).
 */
final class RecallRuleLabels
{
    /** @return array<string, mixed> */
    public static function texts(): array
    {
        return (array) trans('recall_rule') + ['labels' => self::labels()];
    }

    /** @return array{kinds: array<string, string>, exams: array<string, string>, units: array<string, string>, units_one: array<string, string>, evidence: array<string, string>, medications: array<string, string>} */
    public static function labels(): array
    {
        $labels = static fn (array $cases): array => array_combine(
            array_map(static fn ($case): string => $case->value, $cases),
            array_map(static fn ($case): string => $case->label(), $cases),
        );

        return [
            'kinds'     => $labels(RecallTaskKind::cases()),
            'exams'     => $labels(RecallExamKey::cases()),
            'units'     => $labels(IntervalUnit::cases()),
            'units_one' => array_combine(
                array_map(static fn (IntervalUnit $unit): string => $unit->value, IntervalUnit::cases()),
                array_map(static fn (IntervalUnit $unit): string => $unit->labelOne(), IntervalUnit::cases()),
            ),
            'evidence' => array_combine(
                ProtocolContentSchema::EVIDENCE_LEVELS,
                array_map(static fn (string $level): string => __('recall_protocol.evidence.' . $level), ProtocolContentSchema::EVIDENCE_LEVELS),
            ),
            'medications' => array_combine(
                array_keys((array) config('recall.medications', [])),
                array_map(static fn (string $key): string => __('recall_rule.medication_names.' . $key), array_keys((array) config('recall.medications', []))),
            ),
        ];
    }
}
