#!/usr/bin/env bash
# Roda "composer dev" filtrando o aviso de depreciação que o Composer
# do sistema (pacote Debian) emite no próprio boot, antes de qualquer
# script do Laravel rodar:
#
#   Deprecation Notice: The predefined locally scoped $http_response_header
#   variable is deprecated, call http_get_last_response_headers() instead
#   in /usr/share/php/JsonSchema/Uri/Retrievers/FileGetContents.php:57
#
# Não é código deste projeto: é a lib php-json-schema (pacote Debian
# separado, /usr/share/php/JsonSchema) que o Composer usa pra validar
# o composer.json, ainda sem o fix de compat pra $http_response_header
# depreciada no PHP 8.4+. Confirmado em 16/09/2026: 6.4.1-1 já é a
# versão mais nova disponível no repositório Debian — sem update pra
# aplicar por enquanto, o fix real é no pacote do sistema, fora do
# nosso controle.
#
# `php -d error_reporting=...` NÃO funciona aqui: o próprio Composer
# chama error_reporting(E_ALL) de novo no boot, sobrescrevendo o -d
# (testado e confirmado) — por isso filtra a linha na saída em vez de
# tentar suprimir na origem. grep --line-buffered evita segurar o
# stream (composer dev roda indefinidamente, precisa aparecer em
# tempo real, não só quando o buffer enche).
#
# Uso: scripts/dev.sh (substitui "composer dev")

set -uo pipefail

COMPOSER_BIN="$(command -v composer || true)"

if [ -z "${COMPOSER_BIN}" ]; then
  echo "Erro: composer nao encontrado no PATH." >&2
  exit 1
fi

"${COMPOSER_BIN}" dev 2>&1 | grep --line-buffered -v 'JsonSchema/Uri/Retrievers/FileGetContents.php'
