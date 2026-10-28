#!/usr/bin/env bash

set -euo pipefail

if ! command -v docker >/dev/null 2>&1; then
  echo "Erro: docker nao encontrado no PATH." >&2
  exit 1
fi

if docker compose version >/dev/null 2>&1; then
  COMPOSE=(docker compose)
elif command -v docker-compose >/dev/null 2>&1; then
  COMPOSE=(docker-compose)
else
  echo "Erro: docker compose nao encontrado." >&2
  exit 1
fi

SERVICE="${AI_TEST_SERVICE:-app}"

if [ "$#" -gt 0 ]; then
  TEST_TARGETS=("$@")
else
  TEST_TARGETS=("tests/Feature/AI" "tests/Unit/AI")
fi

echo "==> Rodando testes de IA no container '${SERVICE}'"
echo "==> Alvos: ${TEST_TARGETS[*]}"

if "${COMPOSE[@]}" ps -q "${SERVICE}" | grep -q .; then
  "${COMPOSE[@]}" exec -T "${SERVICE}" php artisan test "${TEST_TARGETS[@]}"
else
  "${COMPOSE[@]}" run --rm "${SERVICE}" php artisan test "${TEST_TARGETS[@]}"
fi
