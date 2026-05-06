<?php

declare(strict_types=1);

namespace App\Services;

use InvalidArgumentException;

/**
 * F8 — Receituário de Catarata.
 *
 * Centraliza tradução de payload (eye/template/date/time) para os
 * placeholders dos templates `RECEITUÁRIO DE CATARATA`. Mantém o
 * controller fino e isola validação semântica/labels PT-BR.
 */
class SurgerySchedulingDocService
{
    /**
     * Olhos canônicos aceitos no payload → label PT-BR usado no documento.
     *
     * @var array<string, string>
     */
    public const EYE_LABELS = [
        'right' => 'OLHO DIREITO',
        'left'  => 'OLHO ESQUERDO',
        'both'  => 'AMBOS OS OLHOS',
    ];

    /**
     * Mapeamento `template payload` → `slug do ReportSettingContent`.
     *
     * Aceita tanto identificadores legacy (1/2/3 — paridade smart_oftal)
     * quanto slugs canônicos.
     *
     * @var array<string, string>
     */
    public const TEMPLATE_SLUGS = [
        '1'                     => 'pre_operatorio',
        '2'                     => 'pos_operatorio',
        '3'                     => 'instrucoes_cirurgicas',
        'pre_operatorio'        => 'pre_operatorio',
        'pos_operatorio'        => 'pos_operatorio',
        'instrucoes_cirurgicas' => 'instrucoes_cirurgicas',
    ];

    public const DEFAULT_TEMPLATE_SLUG = 'pre_operatorio';

    /**
     * Chaves aceitas em validação client-side / server-side.
     *
     * @return array<int, string>
     */
    public static function eyeKeys(): array
    {
        return array_keys(self::EYE_LABELS);
    }

    /**
     * @return array<int, string>
     */
    public static function templateKeys(): array
    {
        return array_keys(self::TEMPLATE_SLUGS);
    }

    public function resolveEyeLabel(string $eye): string
    {
        $key = strtolower(trim($eye));

        if (! isset(self::EYE_LABELS[$key])) {
            throw new InvalidArgumentException("Olho inválido: {$eye}");
        }

        return self::EYE_LABELS[$key];
    }

    public function resolveTemplateSlug(?string $template): string
    {
        if ($template === null || $template === '') {
            return self::DEFAULT_TEMPLATE_SLUG;
        }

        $key = strtolower(trim($template));

        return self::TEMPLATE_SLUGS[$key] ?? self::DEFAULT_TEMPLATE_SLUG;
    }

    /**
     * Constrói os replacements customizados aplicados sobre o template.
     *
     * `eye` é exigido por todos os 3 templates (placeholder OLHO_OPERADO).
     * `date`/`time` só aparecem em `instrucoes_cirurgicas` — quando ausentes
     * caem como string vazia (template original já tolera).
     *
     * @return array<string, string>
     */
    public function buildReplacements(string $eye, ?string $date, ?string $time): array
    {
        return [
            '{{OLHO_OPERADO}}'  => $this->resolveEyeLabel($eye),
            '{{DATA_CIRURGIA}}' => $this->normalizeDate($date),
            '{{HORA_CIRURGIA}}' => $this->normalizeTime($time),
        ];
    }

    private function normalizeDate(?string $date): string
    {
        if ($date === null) {
            return '';
        }

        $trimmed = trim($date);

        return $trimmed === '' ? '' : $trimmed;
    }

    private function normalizeTime(?string $time): string
    {
        if ($time === null) {
            return '';
        }

        $trimmed = trim($time);

        return $trimmed === '' ? '' : $trimmed;
    }
}
