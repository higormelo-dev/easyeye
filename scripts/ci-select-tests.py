#!/usr/bin/env python3
"""Escolhe os testes do Pest que a CI roda num PR, a partir dos arquivos alterados.

Por quê: a suíte inteira (Feature + Unit + Integration) rodava serial num job só — 40m37s na rodada 38775084843, com o
Pest sozinho em 2.206 s — e rodava duas vezes (push + pull_request). Num PR roda-se só o que a mudança alcança; a suíte
INTEIRA continua rodando em todo push para main/desenv (pós-merge).

Regras (na dúvida, suíte inteira):
  - fora de pull_request, sem base conhecida ou com nome de arquivo estranho  -> inteira;
  - mudou algo de efeito amplo (composer, phpunit.xml, migrations, config, rotas, providers, fábricas, base dos testes)
                                                                                 -> inteira (workflow e scripts, não);
  - teste alterado                              -> ele mesmo (e quem usa o auxiliar de teste alterado, pelo nome);
  - app/Domains/<X>/...                         -> todo teste de <X> (pasta ou `Domains\\<X>\\` no código);
  - qualquer outro app/**/*.php                 -> testes que citam o nome da classe (se forem muitos, é núcleo: inteira);
  - lang/**                                     -> testes de contrato de texto (`*TextsTest.php`) e quem cita o grupo;
  - .github/workflows, scripts/                 -> os contratos de CI (`tests/Unit/Ci/*Test.php`, ~15 s, sem banco): são eles que
                                                   protegem o workflow e este seletor, e sem a regra um PR que muda só o
                                                   workflow passava sem rodar nenhum deles (só o P2ContractsTest);
  - resources/js, docs, e2e                     -> nenhum teste PHP (o Vitest roda sempre inteiro, é rápido).
  - sempre: P2ContractsTest (gera as fixtures que validate-integrator-contracts.php lê);
  - tests/Integration (corrida real, 2 sessões)  -> FORA do PR (cada teste segura 2,5-8 s; rodam no push main/desenv).
Teto de tempo do passo do Pest (`timeout_minutes`): quem sabe quanto vai rodar é este script, não o evento. Seleção de até
SHORT_LIMIT arquivos = SHORT_TIMEOUT (se travar, falha rápida em vez de 6 h); suíte inteira e seleção maior = LONG_TIMEOUT.
Teto por evento (pull_request = curto) estourava em todo PR de efeito amplo, que roda a suíte inteira.
Fatias (`matrix`): o Pest roda serial dentro de cada job, mas a suíte inteira vira jobs paralelos, cada um com o PRÓPRIO
Postgres/Redis (fatias nunca dividem banco). Suíte inteira = Feature+Unit em SHARDS fatias e Integration em SHARDS fatias
(corridas reais lentas não se misturam com o resto). Seleção grande (> SHORT_LIMIT) = só Feature/Unit em SHARDS fatias;
seleção pequena = UMA entrada, sem `--shard`. A fatia é a do `--shard=i/SHARDS` do Pest 4 (Plugins/Shard.php): CLASSES
contíguas, `array_chunk` de tamanho ceil(n/SHARDS). Um índice vazio NÃO sai da matriz: o Pest rodaria `--filter ''`, que
seleciona a suíte inteira. Descoberta estática = a de `pest --list-tests` (1 arquivo `*Test.php` = 1 classe, ordem do
php-file-iterator); tests/Unit/Ci/CiShardMatrixTest.php compara as duas contra o Pest de verdade.
Dono das fixtures: o ÚNICO job cuja fatia tem o ALWAYS (P2ContractsTest) valida e publica as fixtures HTTP (`fixtures`).
Saída em $GITHUB_OUTPUT: `mode`, `pest_args` (a seleção inteira), `timeout_minutes` e `matrix` (JSON de jobs); o workflow só lê.
Uso local: CI_BASE_SHA=<sha> python3 ...
"""
import json
import os
import re
import subprocess
import sys
from pathlib import Path

FULL = 'tests/Feature tests/Unit tests/Integration'
SHARDS = 3  # fatias por grupo; cada fatia é um job com o próprio banco (6 jobs de Pest na suíte inteira)
# (id, diretórios) — a união dos grupos é FULL. Integration (DatabaseMigrations + processos filhos) fica à parte.
FULL_GROUPS = (('feature-unit', ('tests/Feature', 'tests/Unit')), ('integration', ('tests/Integration',)))
SAFE = re.compile(r'^[A-Za-z0-9_./-]+$')
BROAD_FILES = {'composer.json', 'composer.lock', 'phpunit.xml', 'tests/Pest.php', 'tests/TestCase.php', 'artisan'}
BROAD_PREFIXES = ('database/migrations/', 'database/seeders/', 'database/factories/', 'config/', 'routes/', 'bootstrap/',
                  'app/Providers/')
# Sempre roda no PR: gera as fixtures HTTP (storage/framework/testing/p2) que o passo validate-integrator-contracts.php lê;
# sem ele o passo falha com "Missing generated HTTP fixture".
ALWAYS = {'tests/Feature/Api/Integrators/P2ContractsTest.php'}
CORE_LIMIT = 120  # classe citada por tantos testes é núcleo (Model, Service base): não adianta selecionar
# Teto de tempo (minutos) do passo do Pest, POR JOB: vale para cada fatia da matriz. Base: rodada 38775084843, a suíte
# inteira em UM job (Pest serial, 2.206 s, ~670 arquivos); em 6 fatias cada job leva uma fração, então 60 é folga contra
# travamento, não meta de duração.
SHORT_TIMEOUT = 15  # seleção pequena de PR: se travar, falha rápida
LONG_TIMEOUT = 60   # suíte inteira (push main/desenv ou PR de efeito amplo) e seleção grande; suba se uma fatia chegar perto de ~50 min
SHORT_LIMIT = 100   # arquivos que cabem em SHORT_TIMEOUT com folga; a seleção mais larga já vista foi de 53


def timeout_for(mode: str, test_files: int) -> int:
    """Teto do passo do Pest: curto só para seleção pequena; o resto (suíte inteira, seleção grande) tem o longo."""
    return SHORT_TIMEOUT if mode == 'selected' and test_files <= SHORT_LIMIT else LONG_TIMEOUT


def discover(dirs: tuple[str, ...]) -> list[str]:
    """Arquivos de teste na ordem em que `pest <dirs> --list-tests` lista as classes: cada diretório na ordem dada,
    arquivos pelo caminho completo em ordem de bytes (`sort($files)` do php-file-iterator; `sorted(Path)` ordena por
    partes e trocaria `Foo-x/` e `Foo/`)."""
    return [f for d in dirs for f in sorted(str(f) for f in Path(d).rglob('*Test.php'))]


def pest_shards(files: list[str], total: int) -> list[tuple[int, list[str]]]:
    """As fatias NÃO vazias de `--shard=i/total` do Pest 4: `array_chunk` de tamanho ceil(n/total). Com n=4 e total=3 o
    índice 3 é vazio (e o Pest o rodaria com `--filter ''` = tudo): por isso só os índices com arquivo saem."""
    size = max(1, -(-len(files) // total))
    return [(i + 1, files[i * size:(i + 1) * size]) for i in range(total) if files[i * size:(i + 1) * size]]


def build_matrix(mode: str, files: list[str]) -> dict:
    """Jobs de Pest: `{"include": [{id, pest_args, shard, fixtures}]}`. Falha alto (exit 1) se a matriz ficar vazia ou
    se o dono das fixtures não for exatamente um job: sem ele o validate-integrator-contracts.php nunca roda."""
    if mode == 'selected' and len(files) <= SHORT_LIMIT:
        groups = [('selected', ' '.join(files), files, 1)]
    elif mode == 'selected':
        groups = [('selected', ' '.join(files), files, SHARDS)]
    else:
        groups = [(name, ' '.join(dirs), discover(dirs), SHARDS) for name, dirs in FULL_GROUPS]

    include, owners = [], []
    for name, args, ordered, total in groups:
        for index, chunk in pest_shards(ordered, total):
            entry = {'id': name if total == 1 else f'{name}-{index}', 'pest_args': args,
                     'shard': '' if total == 1 else f'{index}/{total}', 'fixtures': bool(ALWAYS & set(chunk))}
            include.append(entry)
            if entry['fixtures']:
                owners.append((entry['id'], ALWAYS <= set(chunk)))

    if not include or len(owners) != 1 or not owners[0][1]:
        print(f'[ci-select-tests] ERRO: matriz sem dono único das fixtures ({ALWAYS}): {owners or "nenhum job"}', file=sys.stderr)
        sys.exit(1)
    return {'include': include}


def out(mode: str, args: str, why: str, files: list[str] | None = None) -> None:
    files = files or []
    # O conjunto FINAL, não só os alterados: os selecionados vêm de `rglob` no disco e o workflow os expande com
    # `args=($PEST_ARGS)` (word splitting + glob). Todo token tem que ser um caminho seguro sob tests/; senão, suíte inteira.
    if mode == 'selected' and not all(SAFE.match(f) and f.startswith('tests/') for f in files):
        out('full', FULL, 'caminho de teste selecionado fora do padrão: suíte inteira')
    timeout = timeout_for(mode, len(files))
    matrix = json.dumps(build_matrix(mode, files), separators=(',', ':'))
    print(f'[ci-select-tests] {mode}: {why}')
    print(f'[ci-select-tests] pest_args={args or "(nenhum)"}')
    print(f'[ci-select-tests] timeout_minutes={timeout}')
    print(f'[ci-select-tests] jobs={",".join(e["id"] for e in json.loads(matrix)["include"])}')
    if os.environ.get('GITHUB_OUTPUT'):
        with open(os.environ['GITHUB_OUTPUT'], 'a', encoding='utf-8') as fh:
            fh.write(f'mode={mode}\npest_args={args}\ntimeout_minutes={timeout}\nmatrix={matrix}\n')
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
        elif p.startswith(('.github/workflows/', 'scripts/')):  # os contratos de CI protegem exatamente estes arquivos
            selected |= {t for t in tests if t.startswith('tests/Unit/Ci/')}

    skipped = {t for t in selected if t.startswith('tests/Integration/')}
    selected -= skipped  # lentos por natureza: valem no push para main/desenv, não a cada PR
    selected |= ALWAYS
    files = sorted(selected)
    out('selected', ' '.join(files), f'{len(files)} arquivo(s) de teste alcançados por {len(changed)} arquivo(s) alterado(s); {len(skipped)} de Integration ficam para o push', files)


if __name__ == '__main__':
    main()
