<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Normalização de nomes vindos de planilhas de importação (cabeçalhos, convênios, planos), compartilhada pelo
 * PatientImportService e pelos serviços do de-para de convênios.
 */
final class ImportNameNormalizer
{
    /**
     * Minúsculo, sem acento; espaço e hífen viram "_"; o resto some. Usada em cabeçalhos e nomes de plano.
     */
    public static function key(string $value): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');
        $value = str_replace([' ', '-'], '_', $value);
        // Remove acentos via transliteração ASCII
        $value = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value;

        return preg_replace('/[^a-z0-9_]/', '', $value) ?? $value;
    }

    /**
     * Chave dos NOMES DE CONVÊNIO (catálogo, de-para e relatório da importação): key() sem separadores, para que
     * "SulAmérica", "SULAMERICA" e "Sul América" sejam o mesmo convênio. Só para convênios: cabeçalhos e planos
     * continuam em key().
     */
    public static function covenantKey(string $value): string
    {
        return str_replace('_', '', self::key($value));
    }

    /**
     * Chave de um nome de convênio VINDO DA PLANILHA: vazio → "" (linha sem convênio, entra como Particular);
     * nome só de símbolos ("-", ".") não tem covenantKey, então ganha "#nome" para nunca colidir com "sem convênio"
     * (e não pode ser mapeado: o PUT do de-para exige covenantKey não vazia).
     */
    public static function covenantSourceKey(string $value): string
    {
        $name = trim($value);

        if ($name === '') {
            return '';
        }

        return self::covenantKey($name) ?: '#' . mb_strtolower($name, 'UTF-8');
    }
}
