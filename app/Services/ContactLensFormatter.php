<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Texto do cálculo de lentes de contato gravado na consulta, para o PDF e a
 * exportação LGPD — o mesmo formato da tela (contactLens.js: formatPower,
 * formatLens, contactLensSummary) e os mesmos textos
 * (actions.medical_records.contact_lens_*).
 *
 *   v2: potência SUGERIDA por olho com o tipo ("OD −2,50 (esférica) · OE
 *       −4,75 / −1,75 × 180° (tórica)"), cálculo teórico, linha e avisos.
 *   v1 (gravado antes da v2): formato antigo, como estava ("-6.00 → -5.60").
 *
 * Nunca recalcula: mostra o que foi gravado (e assinado).
 */
final class ContactLensFormatter
{
    private const MINUS = "\u{2212}";

    /** Gravado antes da v2 (vértice só no esférico + equivalente esférico separado). */
    public function isLegacy(?array $calc): bool
    {
        return $calc !== null && ($calc['version'] ?? null) !== ContactLensCalculator::VERSION;
    }

    public function hasResult(?array $calc): bool
    {
        if ($calc === null) {
            return false;
        }

        if ($this->isLegacy($calc)) {
            return collect(['vertex_od_result', 'vertex_oe_result', 'se_od_result', 'se_oe_result'])
                ->contains(fn (string $key): bool => ($calc[$key] ?? null) !== null);
        }

        return ! empty($calc['results']['od']) || ! empty($calc['results']['oe']);
    }

    /**
     * Linhas [key, label, value] — v2: sugerida, teórico, linha de lentes e
     * avisos (`$detailed`); v1: as duas linhas antigas.
     *
     * @return list<array{key: string, label: string, value: string}>
     */
    public function rows(?array $calc, bool $detailed = true): array
    {
        if (! $this->hasResult($calc)) {
            return [];
        }

        if ($this->isLegacy($calc)) {
            // Rótulo legado, para não confundir com a lógica atual (valores como estão).
            return array_map(fn (array $row): array => [...$row, 'label' => "{$row['label']} (" . $this->t('contact_lens_legacy') . ')'], $this->legacyRows($calc));
        }

        $eyes = $this->eyes($calc);
        $rows = [[
            'key'   => 'suggested',
            'label' => $this->t($detailed ? 'contact_lens_suggested' : 'contact_lens_suggested_short'),
            'value' => $this->join(array_map(fn ($eye) => $eye[0] . ' ' . $this->suggestion($eye[1]), $eyes)),
        ]];

        if (! $detailed) {
            return $rows;
        }

        $rows[] = [
            'key'   => 'theoretical',
            'label' => $this->t('contact_lens_theoretical_label', ['mm' => $this->vertexMm($calc['vertex_distance_mm'] ?? null)]),
            'value' => $this->join(array_map(fn ($eye) => $eye[0] . ' ' . $this->theoretical($eye[1]), $eyes)),
        ];
        $rows[] = [
            'key'   => 'profile',
            'label' => $this->t('contact_lens_profile'),
            'value' => $this->profileLabel($calc['profile'] ?? null),
        ];

        $notes = array_filter(array_map(function (array $eye) use ($calc): ?string {
            $codes = $eye[1]['notes'] ?? [];

            return $codes === [] ? null : $eye[0] . ': ' . implode(' ', array_map(
                fn (string $code): string => $this->noteText($code, (string) ($calc['profile'] ?? '')),
                $codes,
            ));
        }, $eyes));

        if ($notes !== []) {
            $rows[] = ['key' => 'notes', 'label' => $this->t('contact_lens_notes'), 'value' => $this->join($notes)];
        }

        return $rows;
    }

    /** Uma linha de texto ("rótulo: valor; …") — exportação LGPD. null sem cálculo. */
    public function text(?array $calc): ?string
    {
        $rows = $this->rows($calc);

        return $rows === [] ? null : implode('; ', array_map(fn (array $row): string => "{$row['label']}: {$row['value']}", $rows));
    }

    /**
     * Dioptria no idioma, sinal sempre explícito e menos tipográfico:
     * pt-BR "−2,50" / "+5,25", en "−2.50"; zero sem sinal; vazio "—".
     */
    public function power(mixed $value): string
    {
        if ($value === null || ! is_numeric($value)) {
            return '—';
        }

        $n    = (float) $value;
        $sign = $n > 0 ? '+' : ($n < 0 ? self::MINUS : '');

        return $sign . number_format(abs($n), 2, $this->decimalSeparator(), '');
    }

    /** Esférica "−2,50 D", tórica "−4,75 / −1,75 × 180°", plano "Plano (0,00)"; componente ausente "—". */
    public function lens(?array $lens, string $type, bool $unit = true): string
    {
        if ($lens === null) {
            return '—';
        }

        $isPlano = (float) ($lens['sphere'] ?? 0) == 0.0;
        $sphere  = $isPlano ? $this->t('contact_lens_plano', ['value' => $this->power(0)]) : $this->power($lens['sphere']);

        if ($type !== 'toric') {
            return $isPlano || ! $unit ? $sphere : "{$sphere} D";
        }

        $cylinder = ($lens['cylinder'] ?? null) === null ? '—' : $this->power($lens['cylinder']);
        $axis     = ($lens['axis'] ?? null) === null ? '—' : "{$lens['axis']}°";

        return "{$sphere} / {$cylinder} × {$axis}";
    }

    /** Cálculo teórico do olho (esférica pelo EE quando havia cilindro; tórica completa) + vértice. */
    public function theoretical(array $result): string
    {
        $th = $result['theoretical'] ?? [];

        if (($result['type'] ?? null) === 'toric') {
            $axis = ($th['axis'] ?? null) === null ? '—' : "{$th['axis']}°";
            $text = $this->power($th['sphere'] ?? null) . ' / ' . $this->power($th['cylinder'] ?? null) . " × {$axis}";
        } elseif ((float) ($th['cylinder'] ?? 0) != 0.0) {
            $text = $this->power($th['se'] ?? null) . ' D (' . $this->t('contact_lens_se_suffix') . ')';
        } else {
            $text = $this->power($th['sphere'] ?? null) . ' D';
        }

        $vertex = ($result['vertex_applied'] ?? false)
            ? $this->t('contact_lens_vertex_applied')
            : $this->t('contact_lens_vertex_not_applied');

        return "{$text} — {$vertex}";
    }

    /** Texto curto de um aviso. Fora da faixa na linha estendida não sugere "considere a estendida". */
    public function noteText(string $code, string $profile): string
    {
        $key = $profile === 'extended' && in_array($code, ['out_of_range', 'cylinder_out_of_range'], true)
            ? "{$code}_extended"
            : $code;

        return $this->t("contact_lens_note_{$key}");
    }

    public function profileLabel(mixed $profile): string
    {
        return $this->t($profile === 'extended' ? 'contact_lens_profile_extended' : 'contact_lens_profile_standard');
    }

    /** "−2,50 (esférica)"; sem sugestão → texto curto. */
    private function suggestion(array $result): string
    {
        if (empty($result['suggested'])) {
            return $this->t('contact_lens_no_lens');
        }

        $type = mb_strtolower($this->t(($result['type'] ?? null) === 'toric' ? 'contact_lens_type_toric' : 'contact_lens_type_spherical'));

        return $this->lens($result['suggested'], (string) $result['type'], unit: false) . " ({$type})";
    }

    /** @return list<array{0: string, 1: array}> */
    private function eyes(array $calc): array
    {
        return array_values(array_filter([
            [$this->t('od'), $calc['results']['od'] ?? null],
            [$this->t('oe'), $calc['results']['oe'] ?? null],
        ], fn (array $eye): bool => is_array($eye[1])));
    }

    /**
     * v1: as duas linhas antigas, com a notação de então ("-6.00 → -5.60").
     *
     * @return list<array{key: string, label: string, value: string}>
     */
    private function legacyRows(array $calc): array
    {
        $diopter = static fn ($v): string => $v === null ? '—' : (($v > 0 ? '+' : '') . number_format((float) $v, 2, '.', ''));
        $eyes    = function (array $pairs) use ($diopter): string {
            return $this->join(array_map(
                fn (array $p): string => $p[1] === false ? "{$p[0]}: {$diopter($p[2])}" : "{$p[0]}: {$diopter($p[1])} → {$diopter($p[2])}",
                array_values(array_filter($pairs, fn (array $p): bool => $p[2] !== null)),
            ));
        };
        $od = $this->t('od');
        $oe = $this->t('oe');

        return array_values(array_filter([
            [
                'key'   => 'vertex',
                'label' => $this->t('contact_lens_vertex_label', ['mm' => $this->vertexMm($calc['vertex_distance_mm'] ?? null)]),
                'value' => $eyes([[$od, $calc['vertex_od'] ?? null, $calc['vertex_od_result'] ?? null], [$oe, $calc['vertex_oe'] ?? null, $calc['vertex_oe_result'] ?? null]]),
            ],
            [
                'key'   => 'se',
                'label' => $this->t('contact_lens_se_title'),
                'value' => $eyes([[$od, false, $calc['se_od_result'] ?? null], [$oe, false, $calc['se_oe_result'] ?? null]]),
            ],
        ], fn (array $row): bool => $row['value'] !== ''));
    }

    /** Distância ao vértice no idioma, sem zeros à toa ("12", "12,5"). */
    private function vertexMm(mixed $value): string
    {
        $n = is_numeric($value) ? (float) $value : ContactLensCalculator::DEFAULT_VERTEX_MM;

        return rtrim(rtrim(number_format($n, 2, $this->decimalSeparator(), ''), '0'), $this->decimalSeparator());
    }

    /** @param list<string> $parts */
    private function join(array $parts): string
    {
        return implode('  ·  ', $parts);
    }

    private function decimalSeparator(): string
    {
        return str_starts_with(app()->getLocale(), 'pt') ? ',' : '.';
    }

    /** @param array<string, string> $replace */
    private function t(string $key, array $replace = []): string
    {
        return (string) __("actions.medical_records.{$key}", $replace);
    }
}
