<?php

use Illuminate\Support\Arr;

/**
 * NFS-e do SaaS: pt_BR e en do arquivo fiscal.php têm as mesmas chaves, e toda chave `fiscal.*` que o backend referencia
 * (`__('fiscal.…')` / `trans('fiscal.…')` em app/) existe nos dois idiomas. Chave faltando devolveria o texto cru
 * ("fiscal.errors.x") para a clínica ou para o Manager.
 */
function fiscalLangKeys(string $locale): array
{
    return array_keys(Arr::dot(require __DIR__ . "/../../../lang/{$locale}/fiscal.php"));
}

/** @return list<string> chaves (sem o prefixo `fiscal.`) usadas literalmente no código do backend */
function fiscalKeysUsedInBackend(): array
{
    $used = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/../../../app')) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        if (preg_match_all('/(?:__|trans)\(\s*[\'"]fiscal\.([A-Za-z0-9_.]+)[\'"]/', (string) file_get_contents($file->getPathname()), $matches)) {
            array_push($used, ...$matches[1]);
        }
    }

    // Chaves montadas em tempo de execução (enums, justificativa por motivo).
    foreach (['pending_send', 'send_failed', 'processando_autorizacao', 'autorizado', 'erro_autorizacao', 'cancelado', 'none'] as $status) {
        $used[] = "status.{$status}";
    }

    foreach (['municipal', 'nacional'] as $standard) {
        $used[] = "standard.{$standard}";
    }

    foreach (['homologacao', 'producao'] as $environment) {
        $used[] = "environment.{$environment}";
    }

    $used[] = 'cancel.refund_justification';

    return array_values(array_unique($used));
}

it('pt_BR e en do fiscal.php têm exatamente as mesmas chaves', function () {
    $pt = fiscalLangKeys('pt_BR');
    $en = fiscalLangKeys('en');

    expect(array_values(array_diff($pt, $en)))->toBe([])
        ->and(array_values(array_diff($en, $pt)))->toBe([]);
});

it('toda chave fiscal.* usada pelo backend existe nos dois idiomas', function (string $locale) {
    $missing = array_values(array_diff(fiscalKeysUsedInBackend(), fiscalLangKeys($locale)));

    expect($missing)->toBe([]);
})->with(['pt_BR', 'en']);

it('os textos com parâmetro usam os mesmos :placeholders nos dois idiomas', function () {
    $pt = Arr::dot(require __DIR__ . '/../../../lang/pt_BR/fiscal.php');
    $en = Arr::dot(require __DIR__ . '/../../../lang/en/fiscal.php');

    foreach ($pt as $key => $text) {
        preg_match_all('/:[a-z_]+/', (string) $text, $a);
        preg_match_all('/:[a-z_]+/', (string) ($en[$key] ?? ''), $b);

        expect(array_unique($a[0]))->toEqualCanonicalizing(array_unique($b[0]), "placeholders de fiscal.{$key}");
    }
});
