#!/usr/bin/env python3
"""Escolhe os testes do Pest que a CI roda num PR, a partir dos arquivos alterados.

Por quê: a suíte inteira (Feature + Unit + Integration) leva ~40 min e rodava duas vezes (push + pull_request). Num PR
roda-se só o que a mudança alcança; a suíte INTEIRA continua rodando em todo push para main/desenv (pós-merge).

Regras (na dúvida, suíte inteira):
  - fora de pull_request, sem base conhecida ou com nome de arquivo estranho  -> inteira;
  - mudou algo de efeito amplo (composer, phpunit.xml, migrations, config, rotas, providers, fábricas, base dos testes)
                                                                                 -> inteira (workflow e scripts, não);
  - teste alterado                              -> ele mesmo (e quem usa o auxiliar de teste alterado, pelo nome);
  - app/Domains/<X>/...                         -> todo teste de <X> (pasta ou `Domains\\<X>\\` no código);
  - qualquer outro app/**/*.php                 -> testes que citam o nome da classe (se forem muitos, é núcleo: inteira);
  - lang/**                                     -> testes de contrato de texto (`*TextsTest.php`) e quem cita o grupo;
  - resources/js, docs, e2e, workflows          -> nenhum teste PHP (o Vitest roda sempre inteiro, é rápido).
  - sempre: P2ContractsTest (gera as fixtures que validate-integrator-contracts.php lê);
  - tests/Integration (corrida real, 2 sessões)  -> FORA do PR (cada teste segura 2,5-8 s; rodam no push main/desenv).
Teto de tempo do passo do Pest (`timeout_minutes`): quem sabe quanto vai rodar é este script, não o evento. Seleção de até
SHORT_LIMIT arquivos = SHORT_TIMEOUT (se travar, falha rápida em vez de 6 h); suíte inteira e seleção maior = LONG_TIMEOUT.
Teto por evento (pull_request = curto) estourava em todo PR de efeito amplo, que roda a suíte inteira (~45 min).
Saída em $GITHUB_OUTPUT: `mode`, `pest_args` (vazio = não rodar o Pest) e `timeout_minutes`; o workflow só lê.
Uso local: CI_BASE_SHA=<sha> python3 ...
"""
import os
import re
import subprocess
import sys
from pathlib import Path

FULL = 'tests/Feature tests/Unit tests/Integration'
SAFE = re.compile(r'^[A-Za-z0-9_./-]+$')
BROAD_FILES = {'composer.json', 'composer.lock', 'phpunit.xml', 'tests/Pest.php', 'tests/TestCase.php', 'artisan'}
BROAD_PREFIXES = ('database/migrations/', 'database/seeders/', 'database/factories/', 'config/', 'routes/', 'bootstrap/',
                  'app/Providers/')
# Sempre roda no PR: gera as fixtures HTTP (storage/framework/testing/p2) que o passo validate-integrator-contracts.php lê;
# sem ele o passo falha com "Missing generated HTTP fixture".
ALWAYS = {'tests/Feature/Api/Integrators/P2ContractsTest.php'}
CORE_LIMIT = 120  # classe citada por tantos testes é núcleo (Model, Service base): não adianta selecionar
# Teto de tempo (minutos) do passo do Pest. A suíte inteira leva ~45 min na CI (~670 arquivos, ~4 s cada).
SHORT_TIMEOUT = 15  # seleção pequena de PR: se travar, falha rápida
LONG_TIMEOUT = 60   # suíte inteira (push main/desenv ou PR de efeito amplo) e seleção grande; suba antes de chegar a ~50 min
SHORT_LIMIT = 100   # arquivos que cabem em SHORT_TIMEOUT com folga (~7 min); a seleção mais larga já vista foi de 53


def timeout_for(mode: str, test_files: int) -> int:
    """Teto do passo do Pest: curto só para seleção pequena; o resto (suíte inteira, seleção grande) tem o longo."""
    return SHORT_TIMEOUT if mode == 'selected' and test_files <= SHORT_LIMIT else LONG_TIMEOUT


def out(mode: str, args: str, why: str, test_files: int = 0) -> None:
    timeout = timeout_for(mode, test_files)
    print(f'[ci-select-tests] {mode}: {why}')
    print(f'[ci-select-tests] pest_args={args or "(nenhum)"}')
    print(f'[ci-select-tests] timeout_minutes={timeout}')
    if os.environ.get('GITHUB_OUTPUT'):
        with open(os.environ['GITHUB_OUTPUT'], 'a', encoding='utf-8') as fh:
            fh.write(f'mode={mode}\npest_args={args}\ntimeout_minutes={timeout}\n')
    sys.exit(0)


def main() -> None:
    base = os.environ.get('CI_BASE_SHA', '').strip()
    if os.environ.get('CI_EVENT', 'pull_request') != 'pull_request' or not base:
        out('full', FULL, 'não é pull_request (ou sem base): suíte inteira')

    changed = subprocess.run(['git', 'diff', '--name-only', '--diff-filter=ACMR', base, 'HEAD'],
                             capture_output=True, text=True, check=True).stdout.split('\n')
    changed = [p for p in changed if p]

    if any(not SAFE.match(p) for p in changed):
        out('full', FULL, 'nome de arquivo fora do padrão: suíte inteira')

    for p in changed:
        if p in BROAD_FILES or p.startswith(BROAD_PREFIXES) or re.match(r'tests/[A-Za-z]*Fixtures[A-Za-z]*\.php$', p):
            out('full', FULL, f'{p} afeta qualquer teste: suíte inteira')

    tests = {str(f): f.read_text(encoding='utf-8', errors='ignore') for d in ('Feature', 'Unit', 'Integration')
             for f in Path('tests', d).rglob('*Test.php')}
    selected: set[str] = set()

    for p in changed:
        name = Path(p).stem
        if p.startswith('tests/') and p.endswith('Test.php') and p in tests:
            selected.add(p)
        elif p.startswith('tests/') and p.endswith('.php'):  # auxiliar de teste (processo filho, support): quem o cita
            selected |= {t for t, body in tests.items() if name in body}
        elif m := re.match(r'app/Domains/([^/]+)/', p):
            domain = m.group(1)
            selected |= {t for t, body in tests.items()
                         if f'/{domain}/' in t or Path(t).name.startswith(domain) or f'Domains\\{domain}\\' in body}
        elif p.startswith('app/') and p.endswith('.php'):
            hit = {t for t, body in tests.items() if re.search(rf'\b{re.escape(name)}\b', body)}
            if len(hit) > CORE_LIMIT:
                out('full', FULL, f'{p} é citado por {len(hit)} testes (núcleo): suíte inteira')
            selected |= hit
        elif p.startswith('lang/'):
            selected |= {t for t in tests if t.endswith('TextsTest.php')}
            selected |= {t for t, body in tests.items() if f"'{name}." in body or f'"{name}.' in body}

    skipped = {t for t in selected if t.startswith('tests/Integration/')}
    selected -= skipped  # lentos por natureza: valem no push para main/desenv, não a cada PR
    selected |= ALWAYS
    out('selected', ' '.join(sorted(selected)), f'{len(selected)} arquivo(s) de teste alcançados por {len(changed)} arquivo(s) alterado(s); {len(skipped)} de Integration ficam para o push', len(selected))


if __name__ == '__main__':
    main()
