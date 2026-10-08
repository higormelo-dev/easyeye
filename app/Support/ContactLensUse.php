<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Uso de lente de contato na anamnese (Fase 2 / A3) — por consulta, gravado
 * em `medical_records.contact_lens_use` (JSON). A tela pré-preenche com o da
 * última consulta do paciente; a calculadora de LC só EXIBE (a lógica v2 do
 * ContactLensCalculator não muda).
 *
 * Formato: {uses, type, wear, hours_per_day, replacement, sleeps_with,
 * complaints}. "Não usa" grava só {uses: false}.
 */
final class ContactLensUse
{
    public const TYPES = ['soft', 'rgp', 'scleral', 'ortho_k', 'cosmetic', 'therapeutic'];

    /** Regime: uso diário (tira para dormir) ou uso estendido. */
    public const WEAR = ['daily_wear', 'extended_wear'];

    /** Descarte. */
    public const REPLACEMENTS = ['daily', 'biweekly', 'monthly', 'quarterly', 'yearly'];

    public const MAX_HOURS = 24;

    public const MAX_COMPLAINTS = 1000;

    /** @return array<string, list<mixed>> */
    public static function validationRules(string $prefix = 'contact_lens_use', bool $sometimes = false): array
    {
        $base = $sometimes ? ['sometimes', 'nullable'] : ['nullable'];

        return [
            $prefix                   => [...$base, 'array'],
            "{$prefix}.uses"          => ['nullable', 'boolean'],
            "{$prefix}.type"          => ['nullable', 'string', 'in:' . implode(',', self::TYPES)],
            "{$prefix}.wear"          => ['nullable', 'string', 'in:' . implode(',', self::WEAR)],
            "{$prefix}.hours_per_day" => ['nullable', 'integer', 'min:0', 'max:' . self::MAX_HOURS],
            "{$prefix}.replacement"   => ['nullable', 'string', 'in:' . implode(',', self::REPLACEMENTS)],
            "{$prefix}.sleeps_with"   => ['nullable', 'boolean'],
            "{$prefix}.complaints"    => ['nullable', 'string', 'max:' . self::MAX_COMPLAINTS],
        ];
    }

    /**
     * Só as chaves conhecidas; "não usa" descarta o resto; nada preenchido → null.
     *
     * @param array<string, mixed>|null $value
     *
     * @return array<string, mixed>|null
     */
    public static function normalize(?array $value): ?array
    {
        if ($value === null) {
            return null;
        }

        $bool = fn ($v) => $v === null || $v === '' ? null : filter_var($v, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        $in   = fn ($v, array $allowed) => in_array($v, $allowed, true) ? $v : null;

        $uses = $bool($value['uses'] ?? null);

        if ($uses === false) {
            return ['uses' => false];
        }

        $hours = $value['hours_per_day'] ?? null;

        $out = [
            'uses'          => $uses,
            'type'          => $in($value['type'] ?? null, self::TYPES),
            'wear'          => $in($value['wear'] ?? null, self::WEAR),
            'hours_per_day' => $hours === null || $hours === '' ? null : max(0, min(self::MAX_HOURS, (int) $hours)),
            'replacement'   => $in($value['replacement'] ?? null, self::REPLACEMENTS),
            'sleeps_with'   => $bool($value['sleeps_with'] ?? null),
            'complaints'    => filled($value['complaints'] ?? null) ? trim((string) $value['complaints']) : null,
        ];

        $filled = array_filter($out, fn ($v) => $v !== null);

        if ($filled === []) {
            return null;
        }

        // Preencheu detalhes sem marcar "usa": usa.
        $out['uses'] ??= true;

        return $out;
    }

    /**
     * Linhas legíveis (rótulo => valor) no idioma atual — PDF, exportação LGPD
     * e histórico da consulta.
     *
     * @param array<string, mixed>|null $value
     *
     * @return list<array{label: string, value: string}>
     */
    public static function rows(?array $value): array
    {
        if (! is_array($value) || ! array_key_exists('uses', $value) || $value['uses'] === null) {
            return [];
        }

        $t = fn (string $key, array $replace = []) => __('actions.medical_records.' . $key, $replace);

        if ($value['uses'] === false) {
            return [['label' => $t('contact_lens_use'), 'value' => $t('contact_lens_use_no')]];
        }

        $rows = [['label' => $t('contact_lens_use'), 'value' => $t('contact_lens_use_yes')]];

        if (filled($value['type'] ?? null)) {
            $rows[] = ['label' => $t('contact_lens_use_type'), 'value' => $t('contact_lens_use_type_' . $value['type'])];
        }

        if (filled($value['wear'] ?? null)) {
            $rows[] = ['label' => $t('contact_lens_use_wear'), 'value' => $t('contact_lens_use_wear_' . $value['wear'])];
        }

        if (isset($value['hours_per_day']) && $value['hours_per_day'] !== null) {
            $rows[] = ['label' => $t('contact_lens_use_hours'), 'value' => $t('contact_lens_use_hours_value', ['hours' => $value['hours_per_day']])];
        }

        if (filled($value['replacement'] ?? null)) {
            $rows[] = ['label' => $t('contact_lens_use_replacement'), 'value' => $t('contact_lens_use_replacement_' . $value['replacement'])];
        }

        if (isset($value['sleeps_with']) && $value['sleeps_with'] !== null) {
            $rows[] = ['label' => $t('contact_lens_use_sleeps'), 'value' => $value['sleeps_with'] ? $t('contact_lens_use_yes') : $t('contact_lens_use_no')];
        }

        if (filled($value['complaints'] ?? null)) {
            $rows[] = ['label' => $t('contact_lens_use_complaints'), 'value' => (string) $value['complaints']];
        }

        return $rows;
    }

    /** Uma linha só ("Usa: gelatinosa · uso diário · 10 h/dia…"). */
    public static function text(?array $value): ?string
    {
        $rows = self::rows($value);

        if ($rows === []) {
            return null;
        }

        return implode(' · ', array_map(fn (array $r) => $r['label'] . ': ' . $r['value'], $rows));
    }
}
