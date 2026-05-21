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

# ─── 4. Limpeza de caches Laravel em dev ────────────────────────────────────
# Em APP_ENV=local, caches de view/route/config compilados costumam mascarar
# mudanças em arquivos quando você troca de branch ou faz mudanças estruturais.
# Em prod (`config:cache` ativo), pular a limpeza preserva performance.
if [ "${APP_ENV:-production}" = "local" ]; then
    echo "[entrypoint] Modo dev — limpando caches view/route/config..."
    php artisan view:clear  >/dev/null 2>&1 || true
    php artisan route:clear >/dev/null 2>&1 || true
    php artisan config:clear >/dev/null 2>&1 || true
fi

exec "$@"
