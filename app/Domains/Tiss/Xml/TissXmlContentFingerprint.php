<?php

declare(strict_types=1);

namespace App\Domains\Tiss\Xml;

use DOMDocument;
use DOMXPath;

/**
 * Impressão digital do CONTEÚDO de uma mensagem TISS, sem os nós que o builder
 * preenche com o relógio: data e hora do cabeçalho e o hash do epílogo (md5 do
 * corpo — horário incluído). Duas gerações do mesmo lote, sem guia nem dado
 * cadastral alterado, dão a mesma impressão mesmo com segundos de diferença.
 *
 * Serve para decidir se um XML já enviado à operadora ainda é "a mesma
 * mensagem": a chave de idempotência do envio nasce do hash do arquivo, então
 * regerar por engano mudava a chave e o lote aceito saía de novo.
 */
final class TissXmlContentFingerprint
{
    /** Nós (a partir da raiz, sem namespace) que dependem do relógio da geração. */
    private const VOLATILE_PATHS = [
        ['cabecalho', 'identificacaoTransacao', 'dataRegistroTransacao'],
        ['cabecalho', 'identificacaoTransacao', 'horaRegistroTransacao'],
        ['epilogo'],
    ];

    /**
     * @return string|null null quando o texto não é um XML bem formado (não há o que comparar)
     */
    public static function of(string $xml): ?string
    {
        $dom                     = new DOMDocument();
        $dom->preserveWhiteSpace = false;

        $previous = libxml_use_internal_errors(true);

        try {
            $loaded = $xml !== '' && $dom->loadXML($xml, LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if (! $loaded) {
            return null;
        }

        $xpath = new DOMXPath($dom);

        foreach (self::VOLATILE_PATHS as $segments) {
            // O XML do TISS usa namespace padrão: casa pelo nome local.
            $expression = '/*' . implode('', array_map(static fn (string $name): string => "/*[local-name()='{$name}']", $segments));

            foreach (iterator_to_array($xpath->query($expression) ?: []) as $node) {
                $node->parentNode?->removeChild($node);
            }
        }

        $canonical = $dom->C14N();

        return $canonical === false ? null : hash('sha256', $canonical);
    }
}
