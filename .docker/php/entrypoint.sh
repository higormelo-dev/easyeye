#!/bin/sh
set -e

# Instala dependências se vendor/ não existir ou se composer.lock foi alterado
if [ ! -f vendor/autoload.php ]; then
    echo "[entrypoint] vendor/ ausente — rodando composer install..."
    composer install --no-interaction --prefer-dist --optimize-autoloader
elif [ composer.lock -nt vendor/autoload.php ]; then
    echo "[entrypoint] composer.lock mais novo que vendor/ — rodando composer install..."
    composer install --no-interaction --prefer-dist --optimize-autoloader
else
    echo "[entrypoint] vendor/ atualizado, pulando composer install."
fi

exec "$@"
