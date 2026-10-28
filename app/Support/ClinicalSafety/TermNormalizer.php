<?php

declare(strict_types=1);

namespace App\Support\ClinicalSafety;

use Illuminate\Support\Str;

/**
 * Normalização ÚNICA dos termos clínicos (Fase 2 / A2 e A4): princípio ativo
 * do catálogo CMED, substância digitada pela recepção, nome livre da
 * medicação e o texto livre da receita passam pelo mesmo caminho — minúsculo,
 * sem acento, só letras/dígitos, sem as palavras de
 * `clinical_safety.ingredient_stopwords` (sais, hidratação, conectivos).
 *
 * A comparação é por SEQUÊNCIA DE PALAVRAS inteiras: "tansulosina" casa com
 * "cloridrato de tansulosina 0,4 mg", mas "iodo" não casa com
 * "iodopovidona" (essa vem como membro da classe). O front recebe a mesma
 * lista de palavras ignoradas (clientCheck) para a checagem na tela.
 */
final class TermNormalizer
{
    /** Termo mais curto que entra na comparação (evita "e", "de", siglas soltas). */
    public const MIN_TERM_LENGTH = 3;

    /** @var array<string, true>|null */
    private static ?array $stopwords = null;

    /** Tags de BLOCO (quebra visual real) — o resto (negrito, span…) é linha. */
    private const BLOCK_TAGS = 'br|p|div|li|ul|ol|table|tr|td|th|h[1-6]|blockquote|hr';

    /** Minúsculo, sem acento, pontuação vira espaço. */
    public static function plain(?string $text): string
    {
        $text = (string) $text;
        // Tag de BLOCO vira espaço (strip_tags colaria "</p><p>Voltaren" em
        // "2026Voltaren"). Tag de LINHA (negrito, itálico, span…) vira nada —
        // "DIPI<strong>RONA</strong>" é uma palavra só, visualmente contínua;
        // virar espaço quebrava a palavra e escapava da checagem de alergia
        // quando o médico formatava parte do nome na receita (13ª revisão
        // Codex, P1).
        $text = preg_replace('/<\/?(?:' . self::BLOCK_TAGS . ')\b[^>]*>/i', ' ', $text) ?? $text;
        $text = html_entity_decode((string) preg_replace('/<[^>]*>/', '', $text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = mb_strtolower(Str::ascii($text), 'UTF-8');
        $text = (string) preg_replace('/[^a-z0-9]+/', ' ', $text);

        return trim((string) preg_replace('/\s+/', ' ', $text));
    }

    /** Palavras significativas do texto (sem as ignoradas), unidas por espaço. */
    public static function core(?string $text): string
    {
        $stop = self::stopwords();

        $tokens = array_filter(
            explode(' ', self::plain($text)),
            fn (string $token) => $token !== '' && ! isset($stop[$token]),
        );

        return implode(' ', $tokens);
    }

    /**
     * Termos de uma substância/princípio ativo — "amoxicilina + clavulanato
     * de potássio" vira dois termos; termos curtos demais saem.
     *
     * @return list<string>
     */
    public static function terms(?string $text): array
    {
        $parts = preg_split('/[+;,\/\n]|\s-\s/u', (string) $text) ?: [];

        $terms = [];

        foreach ($parts as $part) {
            $core = self::core($part);

            if (mb_strlen($core) >= self::MIN_TERM_LENGTH) {
                $terms[$core] = true;
            }
        }

        return array_keys($terms);
    }

    /** O texto (já em core()) contém o termo (core()) como sequência de palavras inteiras? */
    public static function contains(string $haystackCore, string $needleCore): bool
    {
        if ($needleCore === '' || $haystackCore === '') {
            return false;
        }

        return str_contains(' ' . $haystackCore . ' ', ' ' . $needleCore . ' ');
    }

    /** @return list<string> */
    public static function stopwordList(): array
    {
        return array_keys(self::stopwords());
    }

    /** @return array<string, true> */
    private static function stopwords(): array
    {
        if (self::$stopwords === null) {
            $list = [];

            foreach ((array) config('clinical_safety.ingredient_stopwords', []) as $word) {
                $plain = self::plain((string) $word);

                if ($plain !== '') {
                    $list[$plain] = true;
                }
            }

            self::$stopwords = $list;
        }

        return self::$stopwords;
    }

    /** Testes que mudam a configuração em tempo de execução. */
    public static function flush(): void
    {
        self::$stopwords = null;
    }
}
