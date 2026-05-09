#!/bin/sh
set -e

# ─── 1. Dependências Composer ────────────────────────────────────────────────
if [ ! -f vendor/autoload.php ]; then
    echo "[entrypoint] vendor/ ausente — rodando composer install..."
    composer install --no-interaction --prefer-dist --optimize-autoloader
elif [ composer.lock -nt vendor/autoload.php ]; then
    echo "[entrypoint] composer.lock mais novo que vendor/ — rodando composer install..."
    composer install --no-interaction --prefer-dist --optimize-autoloader
else
    echo "[entrypoint] vendor/ atualizado, pulando composer install."
fi

# ─── 2. APP_KEY ──────────────────────────────────────────────────────────────
# Gera automaticamente se a variável estiver vazia (primeiro up sem .env configurado)
if [ -z "${APP_KEY}" ]; then
    echo "[entrypoint] APP_KEY ausente — gerando automaticamente..."
    php artisan key:generate --force
fi

# ─── 3. Migrations ───────────────────────────────────────────────────────────
# Controlado por RUN_MIGRATIONS=true no serviço app do docker-compose.yml.
# O worker usa a mesma imagem mas não executa migrate para evitar disputa de lock.
if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
    echo "[entrypoint] Executando migrations..."
    php artisan migrate --force
fi

exec "$@"
