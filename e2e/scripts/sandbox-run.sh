#!/usr/bin/env bash
# E2E do Asaas SANDBOX em um comando: ngrok + app na 8086 + webhook registrado + Cypress + limpeza.
#
#   e2e/scripts/sandbox-run.sh [argumentos do `cypress run`]
#   CYPRESS_SANDBOX_PAUSE=1 e2e/scripts/sandbox-run.sh        # pagamento À MÃO: inclui os casos de cartão (URL no terminal)
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
#   3. MOVE `public/hot` (Vite dev do `composer dev`) para o diretório temporário do script e o restaura na saída: com
#      o hot presente o Blade injeta `http://localhost:5173/…`, que o Chrome bloqueia numa página https (mixed content)
#      e o /register fica em branco. Sem o hot o Laravel serve `public/build` (o front compilado, que precisa existir e
#      estar atual — veja PRÉ-REQUISITOS); a 8085 também passa a servir o build, então não precisa matar o `composer dev`,
#      só perde o HMR durante a rodada. Exporta `APP_URL=<url pública>` (valem para o serve, o register/remove do
#      webhook e o Cypress, que leem a MESMA configuração; o .env não é alterado) e sobe
#      `php artisan serve --port=8086 --no-reload` — o `composer dev` (8085) segue de pé. `--no-reload` é o que faz o
#      serve repassar o APP_URL (sem ele o serve descarta as variáveis do ambiente e usaria o .env);
#   4. `php artisan billing:asaas-webhook register --url=<url pública> --ngrok-api=http://127.0.0.1:<porta web do SEU
#      ngrok>` (webhook do sandbox → este túnel). O comando só envia o token a um host PROVADO local: consulta essa API
#      e exige que a url seja o public_url de um túnel apontando para loopback;
#   5. `cypress run` do spec de sandbox NAVEGANDO pela URL pública do túnel (CYPRESS_BASE_URL=https://<túnel>): a origem
#      do navegador é a mesma do APP_URL e dos retornos do checkout. O spec e a cy.task só aceitam esse host se
#      CYPRESS_SANDBOX_TUNNEL_HOST (exportado aqui) bater e a API do ngrok DESTA instância o reportar apontando para a
#      porta 8086; localhost segue valendo. O Cypress usa um User-Agent próprio porque o plano free do ngrok serve uma
#      página de aviso a User-Agent de navegador;
#   6. trap EXIT/INT/TERM: restaura o `public/hot` sempre que o backup existir (arquivo OU symlink, mesmo pendente; só
#      não sobrescreve um `public/hot` que tenha reaparecido), `billing:asaas-webhook remove` (3 tentativas), mata o
#      serve e o ngrok, apaga os temporários.
#
# PAGAMENTO À MÃO (CYPRESS_SANDBOX_PAUSE=1) — o cartão é pago FORA do Cypress: o "Pagar" do Asaas Checkout é protegido por
# reCAPTCHA Enterprise, que pontua o navegador, e a janela do Cypress (navigator.webdriver, flags de automação e o
# User-Agent 'EasyEye-E2E-Sandbox' que contorna o aviso do ngrok) é recusada — também com o clique de uma pessoa nela. Por isso
# os dois casos de cartão só rodam com PAUSE=1: o spec cadastra, abre a sessão do Asaas só para LER (o valor da fatura),
# IMPRIME a URL do checkout em destaque no terminal e espera o webhook por até 5 min SEM preencher nada; você abre a URL no
# SEU navegador e paga com o cartão de teste (4444 4444 4444 4444 aprovado, 5184 0197 4037 3151 recusado). Depois do webhook
# o spec visita a página de retorno e afirma o status (o redirecionamento do Asaas para ela não é exercitado). Sem PAUSE os
# dois casos de cartão são PULADOS; Pix e boleto rodam como sempre. Não precisa de `--headed`: o Cypress só espera. A
# Identificação do Asaas chega vazia (o Checkout só aceita cliente com endereço completo e o cadastro da clínica não tem
# endereço): você a digita. Detalhes: docs/internal/infra/ngrok-sandbox-asaas.md.
#
# RECUPERAÇÃO — kill -9 (SIGKILL) não roda o trap e deixa o hot movido: `mv <tmp>/hot.easyeye-e2e public/hot` (o caminho
# exato sai no log "public/hot movido para …"; subir `composer dev`/`npm run dev` também recria o arquivo).
#
# RISCO RESIDUAL — NÃO reinicie o `npm run dev`/`composer dev` durante a rodada: o Vite recria o `public/hot` e o Blade
# volta a injetar http://localhost:5173 (página em branco no https; a CSP também reabriria o Vite). Não há vigia em
# background: o script confere o `public/hot` UMA vez logo antes do `cypress run` (reapareceu → aborta, status 1) e no
# cleanup (reapareceu → avisa e mantém o novo). Reiniciar o Vite DEPOIS dessa checagem derruba só aquela rodada.
#
# SEM REALTIME NO MODO TÚNEL: o build aponta o Reverb para localhost:8080 e o túnel só expõe a 8086. Numa página https
# o navegador bloqueia o ws:// (mixed content) e o wss:// não existe — a conexão falha em silêncio no console, e
# VITE_REVERB_SCHEME=http NÃO resolve. O spec espera o webhook pela API (waitForWebhook), então a rodada só perde o
# update ao vivo da tela. Futuro: 2º túnel para a 8080 + VITE_REVERB_* no build.
#
# SAÍDA: a do Cypress; 1 = aborto antes do teste; 3 = o Cypress passou mas o WEBHOOK NÃO FOI REMOVIDO; 4 = o Cypress
# (e o webhook) passaram mas o `public/hot` NÃO FOI RESTAURADO (o HMR do `composer dev` segue fora; o backup fica no
# diretório temporário e a mensagem traz o `mv` manual). Com o Cypress falhando, o código dele é mantido e os avisos de
# webhook esquecido/hot não restaurado saem do mesmo jeito; com o 3 e o 4 juntos, vale o 3. A mensagem do webhook traz o
# comando manual — o Asaas continua mandando o token e os eventos para a URL do túnel até ele ser removido.
#
# PRÉ-REQUISITOS: ngrok (com authtoken válido: `ngrok config add-authtoken <token>`), jq e curl no PATH, e o WORKER DA
# FILA já rodando (`composer dev` na 8085 sobe um) — é ele que processa o webhook que o Asaas manda para o túnel; sem
# worker o pagamento chega mas nada é processado e o spec dá timeout. Este script avisa se não achar um worker. E o BUILD
# do front (`npm run build`): sem `public/build/manifest.json` o script aborta; com o manifest mais velho que algum
# arquivo de resources/js ou resources/css ele só AVISA (o teste roda contra o front antigo).
#
# Variáveis opcionais: SANDBOX_PORT (8086), SANDBOX_NGROK_TIMEOUT (30), CYPRESS_SANDBOX_PAUSE (repassada) e
# CYPRESS_SANDBOX_KEEP=1 (repassada: o spec NÃO apaga as contas/clínicas/clientes que criou — a limpeza é o after() dele,
# via e2e/scripts/clean-sandbox-signups.php, que também roda à mão).

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
EXIT_HOT_LEFT=4
HOT_FILE="$ROOT/public/hot"
# Caminho do backup definido ANTES do mv: um INT/TERM entre o rename e a próxima linha roda o trap já sabendo onde está.
HOT_BACKUP="$LOGDIR/hot.easyeye-e2e"
HOT_RESTORE_FAILED=0

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

# Existe como arquivo OU como link (um symlink pendente — ex.: public/hot -> hot.url relativo, que fica pendente ao sair
# da pasta — é "inexistente" para `-e`, e tratá-lo assim apagaria o único backup ou sobrescreveria o hot novo).
exists_or_link() { [[ -e "$1" || -L "$1" ]]; }

# Devolve o public/hot que o script moveu (o Vite dev volta a valer). Restaura SEMPRE que o backup existir (não depende de
# o mv ter terminado de ser registrado em variável) e só se ninguém recriou o public/hot nesse meio-tempo (o Vite dev
# reescreve o dele ao subir: o mais novo é o que vale).
restore_hot() {
    exists_or_link "$HOT_BACKUP" || return 0

    if exists_or_link "$HOT_FILE"; then
        say "AVISO: public/hot existe de novo (Vite dev reiniciado?) — mantive o atual e descartei o backup movido."
    elif mv "$HOT_BACKUP" "$HOT_FILE"; then
        say "public/hot restaurado."
    else
        say "ERRO: não consegui restaurar o public/hot. Restaure à mão: mv ${HOT_BACKUP} ${HOT_FILE}"
        HOT_RESTORE_FAILED=1
    fi
}

cleanup() {
    local status=$?
    set +e
    trap - EXIT INT TERM

    # Primeiro: é o que deixa o ambiente de dev como estava, e um 2º Ctrl+C durante a remoção do webhook já não o alcança.
    restore_hot

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

    # O hot que não voltou fica no LOGDIR para a recuperação manual da mensagem acima: não apaga. Cypress verde com o HMR
    # não devolvido não sai 0: o código 4 só vale quando nada pior (status não-zero anterior) já foi decidido.
    if [[ "$HOT_RESTORE_FAILED" -eq 1 ]]; then
        [[ "$status" -eq 0 ]] && status="$EXIT_HOT_LEFT"
    else
        rm -rf "$LOGDIR"
    fi
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

# Front: sem o public/hot (movido mais abaixo) o Blade lê public/build — sem o manifest não há front nenhum.
[[ -f public/build/manifest.json ]] || die "public/build/manifest.json não existe: o public/hot é movido durante a rodada e, sem o build, o app fica sem front. Rode 'npm run build' e tente de novo."
newer_source="$(find resources/js resources/css -newer public/build/manifest.json -type f 2>/dev/null | head -n 1 || true)"
if [[ -n "$newer_source" ]]; then
    say "AVISO: build desatualizado, front antigo no teste (${newer_source} é mais novo que public/build/manifest.json): npm run build"
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

# 3) public/hot fora do caminho ANTES do serve: com ele o Blade injeta http://localhost:5173 (mixed content no túnel https).
#    Restaurado no cleanup (EXIT/INT/TERM); só um SIGKILL o deixa aqui — a recuperação está no cabeçalho.
if exists_or_link "$HOT_FILE"; then
    mv "$HOT_FILE" "$HOT_BACKUP" || die "não consegui mover o public/hot para ${LOGDIR}."
    say "public/hot movido para ${HOT_BACKUP} (a 8085 e a ${PORT} servem public/build, sem HMR, até o fim da rodada). Se o script morrer sem rodar o trap: mv ${HOT_BACKUP} ${HOT_FILE}"
fi

# App na porta do túnel (o .env não é tocado).
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
#    Antes: um Vite dev reiniciado depois do mv recria o public/hot e o Blade volta a injetar http://localhost:5173 (página
#    em branco no https). Não há vigia em background — só esta checagem e a do cleanup (que mantém o hot novo).
if exists_or_link "$HOT_FILE"; then
    die "o public/hot existe de novo antes do Cypress (Vite dev reiniciado? npm run dev / composer dev): o Blade voltaria a injetar http://localhost:5173 e o túnel https bloquearia (mixed content). Mantive o novo; pare o Vite dev (ou deixe-o parado) e rode de novo."
fi
if [[ "${CYPRESS_SANDBOX_PAUSE:-}" == "1" ]]; then
    say "CYPRESS_SANDBOX_PAUSE=1: Pix/boleto — pague no painel do Asaas Sandbox; cartão — abra no SEU navegador a URL que o spec imprime (bloco ====). O spec espera até 5 min e não pausa."
fi
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
