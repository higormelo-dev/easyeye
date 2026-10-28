#!/usr/bin/env python3
"""Hook PreToolUse (Bash): impede comandos que criam bancos de teste extras.

O projeto usa um único banco de teste, o do phpunit.xml (`easyeye_test`).
Sessões do Claude chegaram a criar ~20 bancos que viraram lixo no Postgres
local (02/10/2026): `easyeye_test_fin_*` "para isolar sessões" e
`<banco>_test_1…16` de um `pest --parallel` (o Laravel cria um banco por
worker e não apaga no fim).

Bloqueia (exit 2 + motivo no stderr, que volta para o Claude):
  - pest / phpunit / paratest / `artisan test` com --parallel;
  - DB_DATABASE apontando para `easyeye_test_*` diferente de `easyeye_test`,
    ou para qualquer outro banco num comando que roda testes (RefreshDatabase
    apagaria os dados dele);
  - `createdb` e `CREATE DATABASE`.
Precisa mesmo de outro banco? Peça ao usuário para rodar o comando.
"""
import json
import re
import sys

TEST_DB = "easyeye_test"

TEST_RUNNER = re.compile(r"(?:\bpest\b|\bphpunit\b|\bparatest\b|\bartisan\s+test\b)")
PARALLEL = re.compile(r"(?<![\w-])--parallel\b")
DB_OVERRIDE = re.compile(r"\bDB_DATABASE\s*=\s*['\"]?([\w.-]+)")
CREATE_DB = re.compile(r"(?:\bcreatedb\b|\bCREATE\s+DATABASE\b)", re.IGNORECASE)


def reasons(command: str) -> list[str]:
    found = []
    runs_tests = bool(TEST_RUNNER.search(command))

    if runs_tests and PARALLEL.search(command):
        found.append(
            "--parallel cria um banco por worker (<banco>_test_N) e não apaga no fim; "
            "rode sem --parallel."
        )

    for db in DB_OVERRIDE.findall(command):
        if db == TEST_DB:
            continue
        if db.startswith(f"{TEST_DB}_") or runs_tests:
            found.append(
                f"DB_DATABASE={db}: use só o banco de teste do phpunit.xml ({TEST_DB}); "
                "não crie nem use bancos de teste extras."
            )

    if CREATE_DB.search(command):
        found.append("criar banco de dados não é permitido aqui; peça ao usuário se for mesmo necessário.")

    return found


def main() -> int:
    try:
        payload = json.load(sys.stdin)
    except (json.JSONDecodeError, ValueError, RecursionError):
        return 0

    # `null`, listas e strings são JSON válido, mas não são um evento de hook.
    if not isinstance(payload, dict):
        return 0

    tool_input = payload.get("tool_input")
    command = tool_input.get("command") if isinstance(tool_input, dict) else None
    if not isinstance(command, str):
        return 0
    found = reasons(command)
    if not found:
        return 0

    print("Bloqueado (bancos de teste extras):\n- " + "\n- ".join(found), file=sys.stderr)
    return 2


if __name__ == "__main__":
    sys.exit(main())
