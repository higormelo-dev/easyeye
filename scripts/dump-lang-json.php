<?php

declare(strict_types=1);

/**
 * Espelha um arquivo de idioma (lang/pt_BR/<grupo>.php) num JSON para os testes de Vitest: os testes de tela
 * usam os textos REAIS, não cópias escritas à mão. Um teste do Pest compara o JSON com o arquivo de idioma e
 * falha quando ficam diferentes.
 *
 * Uso: php scripts/dump-lang-json.php <grupo> <arquivo.json> [locale=pt_BR]
 */
[, $group, $target] = $argv + [null, null, null];
$locale             = $argv[3] ?? 'pt_BR';

if ($group === null || $target === null) {
    fwrite(STDERR, "Uso: php scripts/dump-lang-json.php <grupo> <arquivo.json> [locale]\n");

    exit(1);
}

$texts = require dirname(__DIR__) . "/lang/{$locale}/{$group}.php";

file_put_contents($target, json_encode($texts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n");
