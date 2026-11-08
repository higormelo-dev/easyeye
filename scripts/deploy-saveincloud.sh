#!/usr/bin/env bash
#
# Deploy do EasyEye num nó SaveInCloud (produção ou teste), pelo git.
#
# Roda o checklist de docs/internal/runbooks/deploy-saveincloud.md e as conferências que
# faltavam no servidor (levantamento de 08/11/2026):
#   - .env e cópias (.env.*) legíveis só pelo dono (600);
#   - remote sem credencial na URL: o repositório é público, a leitura anônima basta;
#   - arquivo NÃO rastreado no servidor que o git recusaria sobrescrever (ex.: config/sentry.php
#     copiado à mão): igual ao que vem, sai; diferente, vai para .easyeye-deploy-backups/;
#   - arquivo rastreado alterado no servidor PARA o deploy (nada é descartado);
#   - arquivos soltos que não são do repositório (ex.: build, vite, 11.12.0) são listados.
#
# Uso, no nó, como o usuário dono dos arquivos (nginx), na raiz da aplicação:
#   scripts/deploy-saveincloud.sh            atualiza para origin/<branch atual> e roda o checklist
#   scripts/deploy-saveincloud.sh --dry-run  só confere e mostra o que faria; não muda nada
#   scripts/deploy-saveincloud.sh --build    força o build do front (npm ci + npm run build)
#
# PHP, COMPOSER e NPM podem apontar para outros binários (ex.: PHP=/usr/bin/php8.4).

set -Eeuo pipefail

DRY_RUN=0
FORCE_BUILD=0

for arg in "$@"; do
    case "$arg" in
        --dry-run) DRY_RUN=1 ;;
        --build) FORCE_BUILD=1 ;;
        -h | --help)
            sed -n '2,19p' "$0"
            exit 0
            ;;
        *)
            echo "Opção desconhecida: $arg (use --help)" >&2
            exit 2
            ;;
    esac
done

PHP=${PHP:-php}
COMPOSER=${COMPOSER:-composer}
NPM=${NPM:-npm}
APP_DIR=${EASYEYE_APP_DIR:-$PWD}
BACKUP_ROOT="$APP_DIR/.easyeye-deploy-backups"
STAMP=$(date +%Y%m%d-%H%M%S)
STEP="início"

say() { printf '==> %s\n' "$*"; }
warn() { printf 'AVISO: %s\n' "$*" >&2; }
die() {
    printf 'ERRO: %s\n' "$*" >&2
    exit 1
}
run() {
    if ((DRY_RUN)); then
        printf '[dry-run] %s\n' "$*"
    else
        "$@"
    fi
}
# Nunca mostra usuário/token de uma URL.
mask() { sed -E 's#//[^@/]*@#//***@#' <<<"$1"; }

trap 'warn "deploy interrompido na etapa: $STEP (o que já foi feito continua; veja a mensagem acima)"' ERR

cd "$APP_DIR"
[[ -f artisan && -d .git ]] || die "rode na raiz da aplicação (com artisan e .git): $APP_DIR"
[[ "$(id -u)" == "$(stat -c '%u' .)" ]] || die "rode como o dono dos arquivos da aplicação (ex.: nginx), não como $(id -un)"

exec 9>.git/easyeye-deploy.lock
flock -n 9 || die "outro deploy está em andamento"

((DRY_RUN)) && say "dry-run: só conferências; nada será alterado"

# 1. Segredos legíveis só pelo dono.
STEP="permissões do .env"
for f in .env .env.*; do
    [[ -f "$f" && "$f" != .env.example ]] || continue
    mode=$(stat -c '%a' "$f")
    if [[ "$mode" != 600 ]]; then
        say "$f estava $mode: passa a 600 (só o dono lê)"
        run chmod 600 "$f"
    fi
done

# 2. Credencial na URL do remote: qualquer um com acesso ao nó lê o token.
STEP="remote do git"
url=$(git remote get-url origin)
if [[ "$url" =~ ^(https?://)[^/@]+@(.+)$ ]]; then
    clean="${BASH_REMATCH[1]}${BASH_REMATCH[2]}"
    if GIT_TERMINAL_PROMPT=0 timeout 30 git ls-remote --exit-code "$clean" HEAD >/dev/null 2>&1; then
        say "o remote tinha credencial na URL e o repositório abre sem ela: passa a $(mask "$clean")"
        run git remote set-url origin "$clean"
        warn "revogue no GitHub o token que estava na URL (Settings > Developer settings > Personal access tokens): ele ficou exposto a quem acessa o nó"
    else
        warn "a URL do remote tem credencial ($(mask "$url")) e o repositório não abriu sem ela: troque por deploy key SSH somente leitura (git remote set-url origin git@github.com:<dono>/<repo>.git) e revogue o token"
    fi
fi

# 3. O que vem do git.
STEP="git fetch"
branch=$(git rev-parse --abbrev-ref HEAD)
[[ "$branch" != HEAD ]] || die "o checkout está sem branch (HEAD destacado): resolva à mão"
GIT_TERMINAL_PROMPT=0 git fetch --quiet origin "$branch"
current=$(git rev-parse HEAD)
target=$(git rev-parse "origin/$branch")

if [[ "$current" == "$target" ]]; then
    say "código já está em ${target:0:8} (origin/$branch): nada a atualizar"
    exit 0
fi

git merge-base --is-ancestor "$current" "$target" ||
    die "o servidor tem commit que não está em origin/$branch (histórico divergiu): resolva à mão; o código não foi alterado"

STEP="alterações locais"
if ! git diff --quiet || ! git diff --cached --quiet; then
    git status --short --untracked-files=no >&2
    die "há arquivos rastreados alterados no servidor (acima): guarde ou desfaça e rode de novo; o código não foi alterado"
fi

# 4. Arquivo solto exatamente onde o git vai criar um arquivo: o merge recusaria.
STEP="arquivos soltos no caminho do git"
handled=()
while IFS= read -r -d '' path; do
    [[ -e "$path" || -L "$path" ]] || continue
    git ls-files --error-unmatch -- "$path" >/dev/null 2>&1 && continue
    handled+=("$path")

    if [[ -f "$path" ]] && git show "$target:$path" 2>/dev/null | cmp -s - "$path"; then
        say "$path: não rastreado e igual ao que vem — sai para o git criar"
        run rm -f -- "$path"
    else
        dest="$BACKUP_ROOT/$STAMP/$path"
        warn "$path: não rastreado e diferente do que vem — guardado em ${dest#"$APP_DIR"/}"
        run mkdir -p -- "$(dirname -- "$dest")"
        run mv -- "$path" "$dest"
    fi
done < <(git diff -z --name-only --diff-filter=AR "$current" "$target")

STEP="arquivos que não são do repositório"
stray=()
while IFS= read -r path; do
    for done_path in ${handled[@]+"${handled[@]}"}; do # bash < 4.4: array vazio + set -u
        [[ "$path" == "$done_path" ]] && continue 2
    done
    stray+=("$path")
done < <(git ls-files --others --exclude-standard --directory)
if ((${#stray[@]})); then
    warn "arquivos que não são do repositório (avalie e remova à mão): ${stray[*]}"
fi

# 5. Código novo.
STEP="git merge"
say "atualizando ${current:0:8} -> ${target:0:8} (origin/$branch)"
run git merge --ff-only --quiet "$target"
changed=$(git diff --name-only "$current" "$target")

# 6. Checklist da aplicação (mesma ordem do runbook).
STEP="composer install"
app_env=$("$PHP" artisan env --no-ansi 2>/dev/null | grep -o '\[[A-Za-z0-9_-]*\]' | tr -d '[]' || true)
composer_args=(install --no-interaction --prefer-dist --optimize-autoloader)
if [[ "$app_env" == production ]]; then
    composer_args+=(--no-dev)
fi
run "$COMPOSER" "${composer_args[@]}"

STEP="migrations"
run "$PHP" artisan config:clear # migra com o .env atual, não com o cache antigo
run "$PHP" artisan migrate --force

STEP="build do front"
if ((FORCE_BUILD)) || grep -qE '^(resources/(js|css|scss|sass|views)/|package(-lock)?\.json$|vite\.config\.|tailwind\.config\.|postcss\.config\.)' <<<"$changed"; then
    run "$NPM" ci
    run "$NPM" run build
else
    say "front sem mudança: sem build (use --build para forçar)"
fi

STEP="caches"
run "$PHP" artisan optimize # config, eventos, rotas e views (sem apagar o cache da aplicação)

STEP="agendador, filas e Reverb"
run "$PHP" artisan schedule:clear-cache
run "$PHP" artisan queue:restart
run "$PHP" artisan reverb:restart

STEP="fim"
say "deploy concluído em ${target:0:8} (origin/$branch)"
