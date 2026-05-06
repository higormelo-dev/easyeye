<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\{Indication, Procedure};
use InvalidArgumentException;

/**
 * F6 — Solicitação de procedimentos + indicações.
 *
 * Centraliza formatação das linhas exibidas na textarea editável do médico
 * e expõe as constantes clínicas (limite de itens + tipos aceitos), evitando
 * que controllers/views espalhem strings mágicas.
 */
class ProcedureSolicitationService
{
    public const MAX_PROCEDURES = 10;

    public const MAX_INDICATIONS = 10;

    /**
     * Tipos canônicos de solicitação (paridade smart_oftal `solicitation_type`).
     *
     * @var array<string, string>
     */
    public const TYPES = [
        'rotina'      => 'Rotina',
        'urgencia'    => 'Urgência',
        'controle'    => 'Controle',
        'comparativo' => 'Comparativo',
    ];

    /**
     * @return array<int, string>
     */
    public static function typeKeys(): array
    {
        return array_keys(self::TYPES);
    }

    public function resolveTypeLabel(?string $type): ?string
    {
        if ($type === null || $type === '') {
            return null;
        }

        $key = strtolower(trim($type));

        if (! isset(self::TYPES[$key])) {
            throw new InvalidArgumentException("Tipo de solicitação inválido: {$type}");
        }

        return self::TYPES[$key];
    }

    /**
     * Formata linha de procedimento — `- Nome (Tipo)` ou `- Nome` quando sem tipo.
     */
    public function formatProcedureLine(Procedure $procedure, ?string $type = null): string
    {
        $label = $this->resolveTypeLabel($type);
        $name  = trim($procedure->name);

        if ($label !== null) {
            return sprintf('- %s (%s)', $name, $label);
        }

        return sprintf('- %s', $name);
    }

    /**
     * Formata linha de indicação — `- Indicação: Descrição`.
     */
    public function formatIndicationLine(Indication $indication): string
    {
        return sprintf('- Indicação: %s', trim($indication->description));
    }

    /**
     * Concatena lista de linhas (procedimentos + indicações já formatadas)
     * preservando newline final único.
     *
     * @param iterable<string> $lines
     */
    public function joinLines(iterable $lines): string
    {
        $rows = [];

        foreach ($lines as $line) {
            $line = trim((string) $line);

            if ($line !== '') {
                $rows[] = $line;
            }
        }

        if ($rows === []) {
            return '';
        }

        return implode("\n", $rows) . "\n";
    }
}
