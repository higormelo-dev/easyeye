<?php

declare(strict_types=1);

/**
 * Contrato do arquivo de Novidades (resources/changelog/*.md): a primeira
 * `## x.y.z` é a versão atual; pt_BR é a fonte da verdade e o en acompanha.
 * Também fixa o modo seguro do Markdown (HTML cru e links perigosos não saem).
 */

use App\Support\Changelog;
use Tests\TestCase;

uses(TestCase::class);

describe('Changelog::versionFromMarkdown', function () {
    it('lê a versão do título, com ou sem "v"', function () {
        expect(Changelog::versionFromMarkdown("## 1.2.3 — 01/01/2026\n- a"))->toBe('1.2.3')
            ->and(Changelog::versionFromMarkdown("## v2.0.10\n"))->toBe('2.0.10')
            ->and(Changelog::versionFromMarkdown('##   v0.9.0 — hoje'))->toBe('0.9.0');
    });

    it('com várias seções, vale a primeira (a mais nova, no topo)', function () {
        $md = "# Novidades\n\n## 1.3.0 — 18/10/2026\n- a\n\n## 1.2.0 — 10/10/2026\n- b\n\n## 1.1.0\n";

        expect(Changelog::versionFromMarkdown($md))->toBe('1.3.0');
    });

    it('sem título de versão (ou com formato diferente), devolve null', function () {
        expect(Changelog::versionFromMarkdown(''))->toBeNull()
            ->and(Changelog::versionFromMarkdown("Só texto\n- item\n"))->toBeNull()
            ->and(Changelog::versionFromMarkdown("### 1.2.3\n"))->toBeNull()
            ->and(Changelog::versionFromMarkdown("## 1.2\n"))->toBeNull()
            ->and(Changelog::versionFromMarkdown("## Próximas novidades\n"))->toBeNull()
            ->and(Changelog::versionFromMarkdown("texto ## 1.2.3\n"))->toBeNull();
    });

    it('ignora o comentário de formato no topo do arquivo', function () {
        $md = "<!--\nExemplo: ## 9.9.9\n-->\n\n## 1.0.0 — 01/01/2026\n";

        expect(Changelog::versionFromMarkdown($md))->toBe('1.0.0');
    });
});

describe('arquivos de Novidades', function () {
    it('pt_BR e en existem e têm a MESMA versão atual no topo', function () {
        $pt = Changelog::versionFromMarkdown((string) Changelog::markdown('pt_BR'));
        $en = Changelog::versionFromMarkdown((string) Changelog::markdown('en'));

        expect($pt)->toMatch('/^\d+\.\d+\.\d+$/')
            ->and($en)->toBe($pt)
            ->and(Changelog::latestVersion())->toBe($pt);
    });

    it('pt_BR e en listam as mesmas versões, na mesma ordem', function () {
        $versions = fn (string $locale): array => preg_match_all('/^##\s+v?(\d+\.\d+\.\d+)\b/m', (string) Changelog::markdown($locale), $m) ? $m[1] : [];

        expect($versions('en'))->toBe($versions('pt_BR'))->and($versions('pt_BR'))->not->toBeEmpty();
    });

    it('as versões estão em ordem decrescente (mais nova no topo)', function () {
        preg_match_all('/^##\s+v?(\d+\.\d+\.\d+)\b/m', (string) Changelog::markdown('pt_BR'), $m);
        $sorted = $m[1];
        usort($sorted, 'version_compare');

        expect($m[1])->toBe(array_reverse($sorted));
    });

    it('idioma sem arquivo cai no pt_BR', function () {
        expect(Changelog::markdown('es'))->toBe(Changelog::markdown('pt_BR'))
            ->and(Changelog::markdown(null))->toBe(Changelog::markdown('pt_BR'))
            // nome de arquivo nunca vem de fora
            ->and(Changelog::markdown('../../.env'))->toBe(Changelog::markdown('pt_BR'));
    });
});

describe('Changelog::render (modo seguro)', function () {
    it('gera títulos e listas reais', function () {
        $html = Changelog::render("## 1.0.0\n\n- um\n- **dois**\n");

        expect($html)->toContain('<h2')->and($html)->toContain('<ul>')->and($html)->toContain('<li>um</li>')->and($html)->toContain('<strong>dois</strong>');
    });

    it('descarta HTML cru, handlers e scripts', function () {
        $html = Changelog::render("<script>alert(1)</script>\n\n<img src=x onerror=alert(1)>\n\n<iframe src=\"https://evil.test\"></iframe>\n\ntexto <b onclick=\"x()\">ok</b>\n");

        expect($html)->not->toContain('<script')->and($html)->not->toContain('onerror')->and($html)->not->toContain('<iframe')
            ->and($html)->not->toContain('onclick')->and($html)->not->toContain('alert(1)')->and($html)->toContain('texto');
    });

    it('o purificador do projeto também atua: esquemas fora de http(s)/mailto saem do href', function () {
        // ftp: o CommonMark deixa passar; só o purificador (perfil medical) corta.
        expect(Changelog::render('[arquivo](ftp://exemplo.test/x)'))->not->toContain('ftp://');
    });

    it('neutraliza links javascript:/data: e mantém http(s) e mailto', function () {
        $html = Changelog::render('[a](javascript:alert(1)) [b](data:text/html;base64,PHNjcmlwdD4=) [c](https://easyeye.app) [d](mailto:suporte@easyeye.app)');

        expect($html)->not->toContain('javascript:')->and($html)->not->toContain('data:')
            ->and($html)->toContain('href="https://easyeye.app"')->and($html)->toContain('href="mailto:suporte@easyeye.app"');
    });
});
