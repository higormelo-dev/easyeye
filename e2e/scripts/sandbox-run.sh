#!/usr/bin/env bash
# E2E do Asaas SANDBOX em um comando: ngrok + app na 8086 + webhook registrado + Cypress + limpeza.
#
#   e2e/scripts/sandbox-run.sh [argumentos do `cypress run`]
#   CYPRESS_SANDBOX_PAUSE=1 e2e/scripts/sandbox-run.sh        # pausa o spec para pagar a mão no Asaas
#
# O que faz (e desfaz, mesmo em erro ou Ctrl+C):
#   1. lê o estado EFETIVO do Laravel pelo próprio bootstrap (só leitura; vale APP_ENV/APP_CONFIG_CACHE do shell ou do
#      .env) e exige APP_ENV local/testing e NENHUM config em cache: com cache o Laravel ignora o APP_URL do ambiente e o
#      serve, o webhook e o Cypress passariam a falar de URLs diferentes — o script aborta e manda rodar
#      `php artisan config:clear` (não limpa o cache por você: ele é compartilhado com o `composer dev`); e confere
#      `php artisan billing:gateway-environment asaas` == sandbox (credencial efetiva: a do Manager vence o .env);
#   2. sobe `ngrok http 127.0.0.1:8086` (destino por IP NUMÉRICO: `localhost` é nome resolvível e o `billing:asaas-webhook`
#      não o aceita como prova) com INSTÂNCIA PRÓPRIA: um config temporário move a interface web (API de túneis) para
#      uma porta livre (a 4040 se estiver livre), então a URL vem SÓ do ngrok que este script iniciou — nunca de outro
#      agente ngrok que já esteja rodando — e é a do túnel cujo config.addr aponta para a porta 8086 (teto de 30 s;
#      sem esse túnel, aborta);
#   3. exporta `APP_URL=<url pública>` (valem para o serve, o register/remove do webhook e o Cypress, que leem a MESMA
#      configuração; o .env não é alterado) e sobe `php artisan serve --port=8086 --no-reload` — o `composer dev` (8085)
#      segue intocado. `--no-reload` é o que faz o serve repassar o APP_URL (sem ele o serve descarta as variáveis do
#      ambiente e usaria o .env);
#   4. `php artisan billing:asaas-webhook register --url=<url pública> --ngrok-api=http://127.0.0.1:<porta web do SEU
#      ngrok>` (webhook do sandbox → este túnel). O comando só envia o token a um host PROVADO local: consulta essa API
#      e exige que a url seja o public_url de um túnel apontando para loopback;
#   5. `cypress run` do spec de sandbox NAVEGANDO pela URL pública do túnel (CYPRESS_BASE_URL=https://<túnel>): a origem
#      do navegador é a mesma do APP_URL e dos retornos do checkout. O spec e a cy.task só aceitam esse host se
#      CYPRESS_SANDBOX_TUNNEL_HOST (exportado aqui) bater e a API do ngrok DESTA instância o reportar apontando para a
#      porta 8086; localhost segue valendo. O Cypress usa um User-Agent próprio porque o plano free do ngrok serve uma
#      página de aviso a User-Agent de navegador;
#   6. trap EXIT: `billing:asaas-webhook remove` (3 tentativas), mata o serve e o ngrok, apaga os temporários.
#
# SAÍDA: a do Cypress; 1 = aborto antes do teste; 3 = o Cypress passou mas o WEBHOOK NÃO FOI REMOVIDO. Com o Cypress
# falhando, o código dele é mantido e o aviso de webhook esquecido sai do mesmo jeito. A mensagem traz o comando manual
# — o Asaas continua mandando o token e os eventos para a URL do túnel até ele ser removido.
#
# PRÉ-REQUISITOS: ngrok (com authtoken válido: `ngrok config add-authtoken <token>`), jq e curl no PATH, e o WORKER DA
# FILA já rodando (`composer dev` na 8085 sobe um) — é ele que processa o webhook que o Asaas manda para o túnel; sem
# worker o pagamento chega mas nada é processado e o spec dá timeout. Este script avisa se não achar um worker.
#
# Variáveis opcionais: SANDBOX_PORT (8086), SANDBOX_NGROK_TIMEOUT (30), CYPRESS_SANDBOX_PAUSE (repassada).

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
PORT="${SANDBOX_PORT:-8086}"
NGROK_TIMEOUT="${SANDBOX_NGROK_TIMEOUT:-30}"
NGROK_WEB_PORT=""
NGROK_API=""
LOGDIR="$(mktemp -d)"
NGROK_PID=""
SERVE_PID=""
PUBLIC_URL=""
WEBHOOK_REGISTERED=0
REMOVE_ATTEMPTS=3
EXIT_WEBHOOK_LEFT=3

kill_tree() {
    local pid="$1" child
    for child in $(pgrep -P "$pid" 2>/dev/null); do
        kill_tree "$child"
    done
    kill "$pid" 2>/dev/null || true
}

say() { printf '[sandbox-run] %s\n' "$*" >&2; }
die() { say "ABORTADO: $*"; exit 1; }

port_in_use() { ss -ltn 2>/dev/null | awk '{print $4}' | grep -qE "[:.]$1\$"; }

# Remove o webhook do sandbox. Sai com: 0 removido (ou nada a remover) · 2 a URL foi rejeitada pelo comando (nada
# havia sido enviado à API: não adianta repetir) · outro valor = falhou mesmo após as tentativas.
remove_webhook() {
    local attempt rc=1
    for ((attempt = 1; attempt <= REMOVE_ATTEMPTS; attempt++)); do
        (cd "$ROOT" && php artisan billing:asaas-webhook remove --url="$PUBLIC_URL" >&2)
        rc=$?
        [[ "$rc" -eq 0 || "$rc" -eq 2 ]] && return "$rc"
        say "AVISO: falha ao remover o webhook (tentativa ${attempt}/${REMOVE_ATTEMPTS})."
        ((attempt < REMOVE_ATTEMPTS)) && sleep 2
    done
    return "$rc"
}

cleanup() {
    local status=$?
    set +e
    trap - EXIT INT TERM

    if [[ "$WEBHOOK_REGISTERED" -eq 1 && -n "$PUBLIC_URL" ]]; then
        say "removendo o webhook do sandbox…"
        remove_webhook
        case $? in
            0) ;;
            2) say "AVISO: a URL foi rejeitada pelo comando antes de qualquer chamada à API — nenhum webhook foi registrado." ;;
            *)
                say "ERRO: o webhook do sandbox NÃO foi removido e o Asaas continua enviando eventos (com o token) para ${PUBLIC_URL}."
                say "Remova à mão: cd ${ROOT} && php artisan billing:asaas-webhook remove --url=${PUBLIC_URL}   (confira com: php artisan billing:asaas-webhook list)"
                [[ "$status" -eq 0 ]] && status="$EXIT_WEBHOOK_LEFT"
                ;;
        esac
    fi

    # `artisan serve` roda o `php -S` como filho: derruba a ÁRVORE de cada processo que este script subiu (sem pkill -f).
    for pid in "$SERVE_PID" "$NGROK_PID"; do
        if [[ -n "$pid" ]] && kill -0 "$pid" 2>/dev/null; then
            kill_tree "$pid"
            wait "$pid" 2>/dev/null
        fi
    done

    rm -rf "$LOGDIR"
    exit "$status"
}
trap cleanup EXIT
trap 'exit 130' INT TERM

cd "$ROOT"

# 1) Estado EFETIVO do Laravel, lido pelo próprio bootstrap (só leitura): o ambiente (APP_ENV do shell OU do .env) e o
#    config em cache (o caminho vem de APP_CONFIG_CACHE do shell OU do .env, relativo à raiz — nada de adivinhar no shell:
#    com o cache em outro lugar definido só no .env, o PHP o carrega e o APP_URL do ambiente seria ignorado).
laravel_state() {
    php -r '
        $root = getcwd();
        require $root . "/vendor/autoload.php";
        $app = require $root . "/bootstrap/app.php";
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        echo "\nEASYEYE_ENV:", $app->environment(), "\nEASYEYE_CACHE:", $app->configurationIsCached() ? $app->getCachedConfigPath() : "", "\n";
    ' 2>/dev/null
}
state="$(laravel_state)" || die "não consegui ler o estado do Laravel (o bootstrap falhou). Rode 'php artisan about' e corrija antes."
app_env="$(sed -n 's/^EASYEYE_ENV://p' <<<"$state" | head -n 1)"
config_cache="$(sed -n 's/^EASYEYE_CACHE://p' <<<"$state" | head -n 1)"
[[ -n "$app_env" ]] || die "não consegui ler o estado do Laravel (o bootstrap falhou). Rode 'php artisan about' e corrija antes."

# Só local/testing: o mesmo guard do billing:asaas-webhook, aqui ANTES de subir ngrok/serve.
[[ "$app_env" == "local" || "$app_env" == "testing" ]] || die "APP_ENV='${app_env}': este fluxo só roda com APP_ENV local ou testing."

# Config em cache = o Laravel ignora o APP_URL (e qualquer variável) do ambiente: o serve, o webhook e o Cypress usariam
# URLs diferentes. Não limpa por você (o cache é compartilhado com o `composer dev`).
if [[ -n "$config_cache" ]]; then
    die "há config em cache (${config_cache}): o Laravel ignora o APP_URL do ambiente. Rode 'php artisan config:clear' (e, se o 'composer dev' depender do cache, 'php artisan config:cache' depois) e tente de novo."
fi

# Só sandbox (a MESMA credencial que o spec confere pela cy.task asaasSandboxConfigured).
environment="$(php artisan billing:gateway-environment asaas 2>/dev/null || true)"
[[ "$environment" == "sandbox" ]] || die "billing:gateway-environment asaas respondeu '${environment:-erro}' — este fluxo só roda com a credencial efetiva de SANDBOX (\$aact_hmlg_) e ASAAS_BASE_URL do sandbox."

command -v ngrok >/dev/null 2>&1 || die "ngrok não encontrado no PATH."
command -v curl >/dev/null 2>&1 || die "curl não encontrado no PATH."
command -v jq >/dev/null 2>&1 || die "jq não encontrado no PATH."

if port_in_use "$PORT"; then
    die "a porta ${PORT} já está em uso (outro serve?). Libere-a ou use SANDBOX_PORT=<outra>."
fi

if ! pgrep -f "artisan queue:(work|listen)" >/dev/null 2>&1; then
    say "AVISO: nenhum worker de fila encontrado (queue:work). O webhook do Asaas só é processado com um worker — suba o 'composer dev' (8085) antes."
fi

# 2) ngrok → URL pública, SÓ da instância que este script inicia.
#    A interface web do agente (API de túneis) vai para uma porta livre via config temporário, mesclado ao config padrão
#    (que tem o authtoken). Assim, outro ngrok já rodando na 4040 não é lido por engano.
ngrok_default_config="$(ngrok config check 2>/dev/null | sed -n 's/^Valid configuration file at //p' | head -n 1 || true)"
[[ -n "$ngrok_default_config" && -f "$ngrok_default_config" ]] || die "não achei o config padrão do ngrok (ngrok config check). Configure o authtoken: ngrok config add-authtoken <token> (https://dashboard.ngrok.com/get-started/your-authtoken)."

for candidate in $(seq 4040 4139); do
    if ! port_in_use "$candidate"; then
        NGROK_WEB_PORT="$candidate"
        break
    fi
done
[[ -n "$NGROK_WEB_PORT" ]] || die "nenhuma porta livre entre 4040 e 4139 para a interface web do ngrok."
NGROK_API="http://127.0.0.1:${NGROK_WEB_PORT}/api/tunnels"
printf 'version: "3"\nagent:\n  web_addr: 127.0.0.1:%s\n' "$NGROK_WEB_PORT" >"$LOGDIR/ngrok-web.yml"

say "subindo ngrok http 127.0.0.1:${PORT} (API de túneis em ${NGROK_API})…"
ngrok http "127.0.0.1:${PORT}" --log=stdout --config "$ngrok_default_config" --config "$LOGDIR/ngrok-web.yml" >"$LOGDIR/ngrok.log" 2>&1 &
NGROK_PID=$!

# Só vale o túnel https cujo config.addr termina em :<porta do serve>.
select_tunnel() {
    curl -fsS --max-time 2 "$NGROK_API" 2>/dev/null | jq -r --arg port ":${PORT}" '
        [.tunnels[]?
            | select((.public_url // "") | startswith("https://"))
            | select((.config.addr // "") | sub("/+$"; "") | endswith($port))
            | .public_url][0] // empty' 2>/dev/null
}

deadline=$((SECONDS + NGROK_TIMEOUT))
while ((SECONDS < deadline)); do
    if ! kill -0 "$NGROK_PID" 2>/dev/null; then
        tail -n 8 "$LOGDIR/ngrok.log" >&2 || true
        die "o ngrok encerrou antes de abrir o túnel. Authtoken inválido/ausente? Rode: ngrok config add-authtoken <token> (https://dashboard.ngrok.com/get-started/your-authtoken)."
    fi

    PUBLIC_URL="$(select_tunnel || true)"
    [[ -n "$PUBLIC_URL" ]] && break
    sleep 1
done

if [[ -z "$PUBLIC_URL" ]]; then
    tail -n 8 "$LOGDIR/ngrok.log" >&2 || true
    die "nenhum túnel https apontando para a porta ${PORT} na API do ngrok iniciado por este script (${NGROK_API}) em ${NGROK_TIMEOUT}s. Confira o authtoken (ngrok config add-authtoken <token>) e a rede."
fi

# Defesa extra: só https://<host>, sem porta, caminho nem credenciais (o webhook leva o token ao host registrado).
[[ "$PUBLIC_URL" =~ ^https://[a-z0-9.-]+$ ]] || die "a URL pública do ngrok tem formato inesperado: ${PUBLIC_URL}"
say "túnel: ${PUBLIC_URL}"

# A MESMA configuração para tudo o que roda daqui em diante (serve, register/remove, Cypress e as tasks dele).
export APP_URL="$PUBLIC_URL"
export CYPRESS_SANDBOX_TUNNEL_HOST="${PUBLIC_URL#https://}"
export CYPRESS_SANDBOX_PORT="$PORT"
export CYPRESS_SANDBOX_NGROK_API="$NGROK_API"

# 3) App na porta do túnel (o .env não é tocado).
say "subindo php artisan serve na ${PORT}…"
php artisan serve --host=127.0.0.1 --port="$PORT" --no-reload >"$LOGDIR/serve.log" 2>&1 &
SERVE_PID=$!

for _ in $(seq 1 20); do
    kill -0 "$SERVE_PID" 2>/dev/null || { tail -n 8 "$LOGDIR/serve.log" >&2 || true; die "o php artisan serve encerrou."; }
    curl -s -o /dev/null --max-time 2 "http://127.0.0.1:${PORT}/login" && break
    sleep 1
done
curl -s -o /dev/null --max-time 2 "http://127.0.0.1:${PORT}/login" || die "o app não respondeu em http://127.0.0.1:${PORT}."

# 4) Webhook do sandbox → túnel (removido no trap, mesmo se o register falhar no meio).
WEBHOOK_REGISTERED=1
php artisan billing:asaas-webhook register --url="$PUBLIC_URL" --ngrok-api="http://127.0.0.1:${NGROK_WEB_PORT}" >&2 || die "não foi possível registrar o webhook do sandbox."

# 5) Cypress pela URL pública do túnel (origem única com o APP_URL; o guard do spec/cy.task confere o host e a porta).
say "rodando o Cypress do sandbox contra ${PUBLIC_URL}…"
cypress_status=0
(
    cd "$ROOT/e2e"
    env -u ELECTRON_RUN_AS_NODE \
        CYPRESS_SANDBOX=1 \
        CYPRESS_BASE_URL="$PUBLIC_URL" \
        ${CYPRESS_SANDBOX_PAUSE:+CYPRESS_SANDBOX_PAUSE="$CYPRESS_SANDBOX_PAUSE"} \
        npx cypress run --browser chrome "$@"
) || cypress_status=$?

# 6) trap EXIT desfaz tudo; o status é o do Cypress (3 se o webhook ficou).
exit "$cypress_status"
