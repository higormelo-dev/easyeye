<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;
use JsonException;
use Normalizer;

/**
 * JSON canônico v1 — a forma única em que o conteúdo validado clinicamente
 * (versões de protocolo de recall, conjuntos de revisão, pareceres) é gravado e
 * hasheado. Mesmo valor ⇒ mesmos bytes ⇒ mesmo hash, em qualquer máquina e
 * versão de PHP; é isso que permite provar depois "esta é a regra que o
 * revisor viu".
 *
 * Regras (qualquer mudança é uma NOVA versão do algoritmo — VERSION + vetores
 * dourados novos —, nunca um ajuste aqui):
 *
 * - texto compacto, sem espaço; UTF-8 válido, strings e chaves em NFC;
 * - objeto: chaves ordenadas pelos BYTES UTF-8 (ordem de ponto de código — não a
 *   ordem UTF-16 da RFC 8785, que só difere para chave fora do plano básico, e o
 *   esquema do protocolo só tem chaves ASCII); duas chaves que viram a mesma
 *   depois do NFC são recusadas;
 * - lista: ordem preservada (conjuntos são ordenados e sem repetição pelo
 *   esquema do conteúdo, antes de chegar aqui);
 * - só inteiros (|n| ≤ 2^53 − 1, o que um JavaScript representa sem perda); float
 *   é recusado — percentuais e frações viram inteiros no esquema (dias, meses,
 *   pontos-base);
 * - strings escapam só `"` `\` e os controles U+0000–U+001F (`\b \f \n \r \t`, o
 *   resto `\u00xx` minúsculo); `/`, U+007F, U+2028/2029 e todo o Unicode ficam
 *   literais;
 * - array vazio é `[]`; objeto vazio não existe no esquema (`{}` não é canônico);
 *   array PHP com chaves 0..n−1 em sequência é lista (mapa com essas chaves não
 *   se distingue dele);
 * - objeto PHP, enum, Carbon e afins são recusados: quem chama converte
 *   (`->value`, `toIso8601String()`) e assim escolhe o formato à vista.
 *
 * Hash: `1:` + sha256(domínio + "\n" + texto canônico). O domínio separa usos
 * (um hash de protocolo nunca vale como hash de parecer). A verificação
 * (`matches`) refaz o hash a partir do TEXTO GRAVADO, sem recanonicalizar: assim
 * uma diferença de versão do Unicode entre servidores nunca faz uma versão
 * íntegra parecer adulterada. A normalização acontece na gravação.
 */
final class CanonicalJson
{
    /** Versão do algoritmo — é o prefixo `N:` de todo hash. */
    public const VERSION = 1;

    /** Formato de um hash gravado, para validação e CHECK de coluna. */
    public const HASH_PATTERN = '/^1:[0-9a-f]{64}$/';

    private const MAX_SAFE_INTEGER = 9007199254740991;

    private const MAX_DEPTH = 32;

    private const SHORT_ESCAPES = [
        '"'    => '\\"',
        '\\'   => '\\\\',
        "\x08" => '\\b',
        "\x0c" => '\\f',
        "\n"   => '\\n',
        "\r"   => '\\r',
        "\t"   => '\\t',
    ];

    /**
     * @throws InvalidArgumentException valor fora das regras acima
     */
    public static function encode(mixed $value): string
    {
        return self::write($value, 0);
    }

    /**
     * Lê um JSON (canônico ou não) para array. Recusa float e profundidade
     * excessiva. NÃO detecta chave repetida (o PHP fica com a última): para
     * saber se um texto é canônico use `isCanonical()`.
     *
     * @throws InvalidArgumentException JSON inválido ou com número fora das regras
     */
    public static function decode(string $text): mixed
    {
        try {
            $value = json_decode($text, true, self::MAX_DEPTH + 1, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidArgumentException('JSON inválido: ' . $e->getMessage(), 0, $e);
        }

        self::assertNoFloat($value);

        return $value;
    }

    /**
     * O texto é exatamente o que `encode()` produziria para o que ele diz?
     * Pega espaço, ordem de chave, escape diferente, chave repetida, float e
     * `{}`. Uso: ao congelar um conteúdo e em testes — não na verificação
     * noturna (ver o PHPDoc da classe).
     */
    public static function isCanonical(string $text): bool
    {
        try {
            return self::encode(self::decode($text)) === $text;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    /**
     * UTF-8 válido e NFC — a normalização que `encode()` aplica a strings e
     * chaves, exposta para o esquema do conteúdo normalizar (e dar `trim`) ANTES
     * de validar tamanhos e unicidade.
     *
     * @throws InvalidArgumentException UTF-8 inválido
     */
    public static function normalizeText(string $text): string
    {
        if (! mb_check_encoding($text, 'UTF-8')) {
            throw new InvalidArgumentException('Texto não é UTF-8 válido.');
        }

        $normalized = Normalizer::normalize($text, Normalizer::FORM_C);

        if ($normalized === false) {
            throw new InvalidArgumentException('Texto não pôde ser normalizado (NFC).');
        }

        return $normalized;
    }

    /**
     * `1:` + sha256(domínio + "\n" + texto canônico).
     *
     * @throws InvalidArgumentException domínio vazio ou com quebra de linha
     */
    public static function hash(string $domain, string $canonicalText): string
    {
        if ($domain === '' || str_contains($domain, "\n")) {
            throw new InvalidArgumentException('Domínio do hash inválido.');
        }

        return self::VERSION . ':' . hash('sha256', $domain . "\n" . $canonicalText);
    }

    /** `encode()` + `hash()` de um valor. */
    public static function hashOf(string $domain, mixed $value): string
    {
        return self::hash($domain, self::encode($value));
    }

    /**
     * O hash gravado bate com o texto gravado? Versão desconhecida do algoritmo
     * nunca bate (o hash calculado leva o prefixo da versão atual, então um `2:…`
     * gravado não é comparado com outro algoritmo); comparação em tempo
     * constante.
     */
    public static function matches(string $domain, string $canonicalText, string $expectedHash): bool
    {
        return hash_equals(self::hash($domain, $canonicalText), $expectedHash);
    }

    private static function write(mixed $value, int $depth): string
    {
        if ($depth > self::MAX_DEPTH) {
            throw new InvalidArgumentException('Estrutura profunda demais para o JSON canônico.');
        }

        return match (true) {
            $value === null   => 'null',
            $value === true   => 'true',
            $value === false  => 'false',
            is_int($value)    => self::writeInteger($value),
            is_string($value) => self::quote(self::normalizeText($value)),
            is_array($value)  => self::writeArray($value, $depth),
            default           => throw new InvalidArgumentException(sprintf(
                'Tipo não aceito no JSON canônico: %s (só null, bool, int, string e array).',
                get_debug_type($value),
            )),
        };
    }

    private static function writeInteger(int $value): string
    {
        if ($value > self::MAX_SAFE_INTEGER || $value < -self::MAX_SAFE_INTEGER) {
            throw new InvalidArgumentException('Inteiro fora do intervalo seguro (±2^53−1) no JSON canônico.');
        }

        return (string) $value;
    }

    /**
     * @param array<array-key, mixed> $value
     */
    private static function writeArray(array $value, int $depth): string
    {
        if (array_is_list($value)) {
            return '[' . implode(',', array_map(
                static fn (mixed $item): string => self::write($item, $depth + 1),
                $value,
            )) . ']';
        }

        $pairs = [];
        $seen  = [];

        foreach ($value as $key => $item) {
            $name = self::normalizeText((string) $key);

            if (isset($seen[$name])) {
                throw new InvalidArgumentException('Chave repetida no JSON canônico (depois do NFC).');
            }

            $seen[$name] = true;
            $pairs[]     = [$name, $item];
        }

        usort($pairs, static fn (array $a, array $b): int => strcmp($a[0], $b[0]));

        return '{' . implode(',', array_map(
            static fn (array $pair): string => self::quote($pair[0]) . ':' . self::write($pair[1], $depth + 1),
            $pairs,
        )) . '}';
    }

    /** Escapa um texto JÁ normalizado. */
    private static function quote(string $text): string
    {
        $escaped = preg_replace_callback(
            '/["\\\\\x00-\x1f]/',
            static fn (array $match): string => self::SHORT_ESCAPES[$match[0]] ?? sprintf('\\u%04x', ord($match[0])),
            $text,
        );

        return '"' . $escaped . '"';
    }

    private static function assertNoFloat(mixed $value): void
    {
        if (is_float($value)) {
            throw new InvalidArgumentException('Número não inteiro no JSON canônico.');
        }

        if (is_array($value)) {
            foreach ($value as $item) {
                self::assertNoFloat($item);
            }
        }
    }
}
