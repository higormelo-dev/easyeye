# Reverb (WebSocket) no ambiente de teste — teste.easyeye.app

Configuração do tempo real (Laravel Reverb + Redis) no ambiente de teste
hospedado na SaveInCloud (plataforma Jelastic), feita em **02/10/2026**.

O tempo real é usado pelo progresso das importações (pacientes, médicos,
agenda e catálogo de medicamentos): o job da fila publica o progresso, o
Reverb entrega ao navegador por WebSocket. **Não existe endpoint HTTP de
status** — se o Reverb ou a fila pararem, a barra de progresso para.

Código relacionado: `App\Events\ImportProgressUpdated`,
`App\Traits\BroadcastsImportProgress`, `app/Broadcasting/*`,
`routes/channels.php`, `resources/js/echo.js`,
`resources/js/composables/useImportProgress.js`.

---

## 1. Topologia

| Nó | Acesso SSH | Papel |
|---|---|---|
| Aplicação (nginx + PHP-FPM) | `ssh 257494-11820@gate.paas.saveincloud.net.br -p 3022` | Laravel em `/var/www/webroot/ROOT`, nginx, worker da fila, Reverb, agendador |
| Redis | `ssh 257492-11820@gate.paas.saveincloud.net.br -p 3022` | Cache, sessão, fila e pub/sub do Reverb (`10.101.23.61:6379`, com senha) |

```
Navegador ──wss://teste.easyeye.app/app──▶ nginx do nó da aplicação (SSL termina aqui, 443)
                                            ├─ /app e /apps ─▶ 127.0.0.1:8080  (Reverb)
                                            └─ resto ───────▶ PHP-FPM (Laravel)

Job da fila ──http://127.0.0.1:8080──▶ Reverb ◀──pub/sub (canal "reverb")──▶ Redis
```

- O Reverb escuta **só em 127.0.0.1** — a porta 8080 não fica exposta.
- O navegador entra pela mesma porta HTTPS do site; o nginx faz o upgrade
  para WebSocket.
- `REVERB_SCALING_ENABLED=true`: as mensagens passam pelo Redis (permite mais
  de um servidor Reverb no futuro).

## 2. O que foi configurado

### 2.1 `.env` (`/var/www/webroot/ROOT/.env`)

Backup do original: `.env.bak-reverb-20261002` (mesma pasta).

```ini
BROADCAST_CONNECTION=reverb

# credenciais geradas no próprio servidor (não são as de desenvolvimento)
REVERB_APP_ID=...
REVERB_APP_KEY=...
REVERB_APP_SECRET=...

# onde o processo Reverb escuta (interno)
REVERB_SERVER_HOST=127.0.0.1
REVERB_SERVER_PORT=8080

# como o LARAVEL (jobs) fala com o Reverb — direto, sem sair da máquina
REVERB_HOST=127.0.0.1
REVERB_PORT=8080
REVERB_SCHEME=http
REVERB_SCALING_ENABLED=true   # usa REDIS_HOST/PORT/PASSWORD já existentes

# como o NAVEGADOR chega ao Reverb — domínio público, via nginx
VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"
VITE_REVERB_HOST=teste.easyeye.app
VITE_REVERB_PORT=443
VITE_REVERB_SCHEME=https
```

> `REVERB_*` (uso interno) e `VITE_REVERB_*` (navegador) são **diferentes**
> neste ambiente de propósito. As `VITE_*` entram no JavaScript **no build** —
> mudou alguma delas, rode `npm run build` de novo.

A configuração e as rotas estão em cache (`bootstrap/cache`): depois de mexer
no `.env`, rode `php artisan config:cache && php artisan route:cache`.

### 2.2 nginx (`/etc/nginx/conf.d/sites-enabled/default.conf`)

Backup do original: `default.conf.bak-reverb-20261002` (mesma pasta — o nome
não termina em `.conf`, então o nginx não o carrega). O bloco abaixo foi
inserido no **topo** do arquivo, que é incluído tanto pelo servidor da porta
80 (`nossl.conf`) quanto pelo da 443 (`ssl.conf`):

```nginx
location ~ ^/apps?(/|$) {
    proxy_http_version 1.1;
    proxy_set_header Host $http_host;
    proxy_set_header Scheme $scheme;
    proxy_set_header SERVER_PORT $server_port;
    proxy_set_header REMOTE_ADDR $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "Upgrade";
    proxy_read_timeout 120s;
    proxy_pass http://127.0.0.1:8080;
}
```

Validar e recarregar (o usuário `nginx` tem permissão sem senha):

```bash
/usr/sbin/nginx -t
sudo -n /usr/bin/systemctl reload nginx
```

O arquivo foi adicionado à seção *CUSTOM FILES AND FOLDERS* de
`/etc/jelastic/redeploy.conf`, para **não ser perdido em um redeploy** da
imagem do nó.

#### Host repassado ao PHP sob HTTP/3

O `ssl.conf` do nó anuncia HTTP/3 (`listen 443 quic` + `alt-svc: h3`), e os
navegadores passam a usá-lo depois da primeira visita. Em HTTP/3 o host chega
só no pseudo-cabeçalho `:authority`, não como cabeçalho `Host`. O
`fastcgi_params` padrão não repassa `HTTP_HOST`, então o PHP-FPM recebia só
`SERVER_NAME` = `_` (o `server_name` catch-all). O Laravel montava os links de
paginação como `https://_/panel/...?page=2` (`ERR_NAME_NOT_RESOLVED`).
`route()` e `redirect()` não quebravam porque usam o `APP_URL`
(`URL::forceRootUrl`).

Correção: nos **dois** blocos `location ~ \.php$` do `default.conf`
(backup: `default.conf.bak-http3host-20261002`):

```nginx
fastcgi_param HTTP_HOST $host;
```

Desde o mesmo ajuste, a aplicação também gera os links de paginação a partir
do `APP_URL` quando ele é https (`AppServiceProvider`), sem depender do host da
requisição.

Teste rápido (o `location` tem que mostrar `teste.easyeye.app` nos dois
protocolos):

```bash
for v in --http2 --http3-only; do
  curl -s $v https://teste.easyeye.app/login | grep -o '"location":"[^"]*"'
done
```

### 2.3 Processos (crontab do usuário `nginx`)

Não há supervisor nem systemd disponível para o usuário; os processos longos
são mantidos pelo cron + `flock` (impede duplicata; se um processo cair, o
cron sobe de novo em até 1 minuto). A crontab (`/var/spool/cron/nginx`) já é
preservada em redeploy pela Jelastic.

```cron
# Agendador do Laravel (routes/console.php)
* * * * * cd /var/www/webroot/ROOT && /usr/bin/php artisan schedule:run >> /dev/null 2>&1
# Worker da fila (Redis) — sai a cada 1 h (--max-time) e o cron sobe com o código atual
* * * * * cd /var/www/webroot/ROOT && /usr/bin/flock -n /tmp/easyeye-queue.lock /usr/bin/php artisan queue:work redis --sleep=3 --tries=3 --max-time=3600 --memory=256 -q >> storage/logs/queue-worker.log 2>&1
# Reverb (WebSocket) — só interno
* * * * * cd /var/www/webroot/ROOT && /usr/bin/flock -n /tmp/easyeye-reverb.lock /usr/bin/php artisan reverb:start --host=127.0.0.1 --port=8080 >> storage/logs/reverb.log 2>&1
```

A Jelastic exige uma **linha em branco depois da última entrada** da crontab.

**Um worker só, de propósito.** Os jobs de importação têm `$timeout` de 600 s
(pacientes, médicos, agenda) e 900 s (medicamentos — envio ou download da CMED — e
sincronização de convênios/planos com a ANS), maior que o
`retry_after` da conexão `redis` (90 s, `REDIS_QUEUE_RETRY_AFTER`). Com um
worker isso não importa. Com dois ou mais, um job que passe de 90 s é
entregue de novo a outro worker. Antes de subir mais workers, defina
`REDIS_QUEUE_RETRY_AFTER=960` no `.env` (maior que o maior `$timeout`).

**Memória do nó.** O OOM killer do kernel mata o worker se o processo
estourar a RAM do nó. Isso acontece mesmo abaixo do `memory_limit` do PHP
(384M aqui), porque bibliotecas como o libxml alocam fora dele. Conferir com
`dmesg | grep -i oom`. Em 02/10/2026 a importação da lista CMED pelo
PhpSpreadsheet chegou a 1,4 GB de RSS e o worker foi morto. A leitura de
XLSX agora é em streaming (`App\Support\Spreadsheet\XlsxStreamReader`):
a lista inteira importa em ~16 s com ~130 MB.

> **Atenção — antes de 02/10/2026 este ambiente não tinha worker de fila nem
> agendador rodando.** Ao ligar o worker, 12 jobs acumulados foram processados
> (8 `NotifyScheduleChangeJob`, 2 `RunAiWorkflowJob`, 2
> `GenerateExamDerivatives`, sem falhas). Com o agendador ligado, rodam neste
> ambiente: `subscriptions:renew` e `billing:retry` (cobranças nos gateways
> configurados), `whatsapp:send-confirmations` e `whatsapp:send-surveys`
> (mensagens a pacientes), expirações de trial/assinatura/convites, avisos de
> IA e `integrator-outbox:publish` (a cada minuto). Lista completa:
> `php artisan schedule:list`. Mantenha gateways e WhatsApp do ambiente de
> teste em modo sandbox / com dados de teste.

### 2.4 Sincronização de convênios e planos com a ANS

Manager → Convênios → **Atualizar agora** (ou o agendador, toda segunda às 04:30) baixa da
ANS a lista de operadoras (~1,5 MB) e a de planos (~75 MB, ~166 mil produtos). O arquivo de
planos vai direto para o disco e é lido em linhas — a 1ª carga (~66 mil planos) leva ~15 s e
usa ~25 MB de memória; as seguintes só regravam o que mudou.

| Variável | Padrão | Efeito |
|---|---|---|
| `ANS_OPERATORS_SYNC_ENABLED` | `false` | Liga a sincronização semanal automática (operadoras + planos). |
| `ANS_PLANS_SYNC_ENABLED` | `true` | `false` pula a etapa de planos (só operadoras). |
| `ANS_PLANS_TIMEOUT` | `600` | Tempo máximo do download dos planos (s). |

Depois de mudar no `.env`: `php artisan config:cache`. O servidor precisa de saída HTTPS para
`dadosabertos.ans.gov.br`; se a ANS estiver fora do ar, a sincronização termina com aviso e
as operadoras podem ser atualizadas por envio manual do CSV (os planos ficam para a próxima).

### 2.5 Sincronização do catálogo de medicamentos (CMED/Anvisa)

Manager → Medicamentos → **Atualizar agora** (ou o agendador, toda terça às 05:00) lê na
página oficial da CMED o link da lista de preços (PMC) mais recente
(`lista_pmc_AAAAMMDD_*.xlsx`, ~13 MB) e baixa também a situação dos registros
(`DADOS_ABERTOS_MEDICAMENTOS.csv`, ~8 MB, diário). A carga completa (~21 mil apresentações)
leva ~11 s e usa ~35 MB de memória; se a lista e os dados abertos não mudaram desde a última
carga, termina em menos de 1 s sem reprocessar (o admin pode marcar "Reprocessar").

- Página da CMED fora do ar ou com layout novo: usa a mesma lista em CSV do portal de dados
  abertos (`TA_PRECO_MEDICAMENTO.csv`, endereço fixo, mas atualizado com atraso) e avisa a
  data da lista na tela.
- Só baixa de `www.gov.br` e `dados.anvisa.gov.br` (HTTPS, também em redirecionamento), com
  teto de 80 MB por arquivo. Os arquivos baixados não ficam no disco (a versão e o link ficam
  no histórico).

| Variável | Padrão | Efeito |
|---|---|---|
| `CMED_SYNC_ENABLED` | `false` | Liga a verificação semanal automática. |
| `CMED_DOWNLOAD_TIMEOUT` | `300` | Tempo máximo de cada download (s). |

Depois de mudar no `.env`: `php artisan config:cache` (o arquivo `config/medicines.php` é novo).

### 2.6 Provedores de IA e catálogo de modelos/preços

Manager → **Provedores de IA**. As chaves de API ficam **só no `.env`** (nunca no banco nem
na tela — a página mostra se a chave está definida, a variável e os 4 últimos caracteres,
para conferir uma troca). Além de OpenAI, Anthropic e Gemini, a lista pronta tem Mistral,
Groq, xAI (Grok), Azure OpenAI e Maritaca (Sabiá) (driver "compatível com OpenAI"): basta a
chave — o Azure também precisa do endereço do recurso.

**LGPD** (detalhes e fontes em `docs/legal/ai-providers-lgpd.md`): a Gemini API fica **fora
dos papéis do assistente** (não recebe dado de paciente; em execução real, se tiver sobrado
num papel salvo, é ignorada). Provedor que leva dado de
paciente para fora do Brasil sem adequação (OpenAI, Anthropic, Groq, xAI, Azure global,
Sabiá sem `-br-sp`) só entra num papel depois de registrado o mecanismo de transferência
(drawer do provedor → Proteção de dados); quem já estava em uso segue, com aviso na tela.
**Ambiente de teste:** se o Gemini estiver num papel, ele deixa de ser chamado após o deploy
(o modo Validado some com um só provedor) — ponha como revisor Azure (Suécia), Mistral,
Sabiá `-br-sp` ou Anthropic (com o registro).

| Variável | Padrão | Efeito |
|---|---|---|
| `OPENAI_API_KEY`, `ANTHROPIC_API_KEY`, `GEMINI_API_KEY` | — | Integrações próprias. |
| `MISTRAL_API_KEY`, `GROQ_API_KEY`, `XAI_API_KEY`, `AZURE_OPENAI_API_KEY`, `MARITACA_API_KEY` | — | Provedores compatíveis. Opcionais: `AI_<PROVEDOR>_MODEL`, `AI_<PROVEDOR>_BASE_URL`, `AI_<PROVEDOR>_TIMEOUT_SECONDS`. |
| `AI_AZURE_OPENAI_BASE_URL` | — (obrigatória com a chave) | `https://{recurso}.openai.azure.com/openai/v1`. O modelo (`AI_AZURE_OPENAI_MODEL`) é o **nome do deployment** — use o nome do modelo (ex.: `gpt-4o-mini`) para a sincronização achar o preço; a API do Azure não lista deployments, então cadastre o modelo em "Novo modelo". |
| `AI_AZURE_OPENAI_DATA_REGION` | `eu` | Região do deployment, para a LGPD: `eu` (Standard regional na UE, ex.: swedencentral), `br` (Provisioned em brazilsouth) ou `global` (Global/Data Zone — exige registro). |
| `AI_MARITACA_MODEL` | `sabia-4-br-sp` | Sufixo `-br-sp` = processamento 100% no Brasil (+30% no preço). Preço oficial em R$ convertido para US$ pela cotação da última recarga de provedor. |
| `AI_CATALOG_SYNC_ENABLED` | `false` | Liga a verificação diária (03:40) do catálogo de modelos/preços. |
| `AI_PRICES_CATALOG_URL` | catálogo LiteLLM (GitHub) | Fonte dos preços (só HTTPS). |
| `AI_CATALOG_MAX_PRICE_CHANGE_FACTOR` | `10` | Variação de preço acima disso (pra cima ou pra baixo) não é aplicada sozinha — vai para revisão. |

**Sincronizar agora** (ou o agendador) lista os modelos pela API de cada provedor com chave
(só leitura, nenhuma chamada gasta tokens) e confere os preços no catálogo público LiteLLM
(~3 MB; ~1,5 s e ~70 MB de memória). Modelos novos entram **inativos** (o admin ativa os que
quiser usar); preço editado à mão no painel fica **travado**; modelo que o provedor deixou de
oferecer é marcado na tela, nunca desativado sozinho. Provedor sem chave: só confere os
preços dos modelos já cadastrados.

Depois de mudar o `.env`: `php artisan config:cache` **e** `php artisan queue:restart` (o
worker carrega a config uma vez — sem reiniciar, os jobs de IA seguem com a chave antiga). O
servidor precisa de saída HTTPS para `raw.githubusercontent.com` e para a API de cada
provedor configurado.

## 3. Checklist de deploy

Depois de atualizar o código (`git pull` em `/var/www/webroot/ROOT`):

```bash
cd /var/www/webroot/ROOT
composer install --optimize-autoloader   # em produção: --no-dev
php artisan migrate --force
npm ci && npm run build          # sempre que mudar o front ou alguma VITE_*
php artisan config:cache && php artisan route:cache
php artisan queue:restart        # worker termina o job atual e o cron sobe de novo
php artisan reverb:restart       # Reverb fecha as conexões e o cron sobe de novo
```

`reverb:restart` é obrigatório quando mudar evento, canal
(`routes/channels.php`, `app/Broadcasting`) ou config do Reverb — ele é um
processo longo e não relê o código sozinho.

## 4. Como verificar

No nó da aplicação:

```bash
cd /var/www/webroot/ROOT

# processos de pé
ps -eo pid,etime,args | grep -E "artisan (reverb:start|queue:work)" | grep -v grep
ss -ltn | grep "127.0.0.1:8080"

# handshake WebSocket pelo domínio público → esperado "HTTP/1.1 101" e "pusher:connection_established"
KEY=$(grep "^REVERB_APP_KEY=" .env | cut -d= -f2)
curl -s -i -N --http1.1 --max-time 8 \
  -H "Connection: Upgrade" -H "Upgrade: websocket" -H "Sec-WebSocket-Version: 13" \
  -H "Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==" -H "Origin: https://teste.easyeye.app" \
  "https://teste.easyeye.app/app/${KEY}?protocol=7&client=js&version=8.4.0" | head -12

# fila e falhas
php artisan tinker --execute 'echo app("queue")->connection("redis")->size("default"), PHP_EOL;'
php artisan queue:failed
```

No nó do Redis (pub/sub do Reverb — esperado `reverb` com 1+ assinante):

```bash
REDISCLI_AUTH="$(awk '/^requirepass/{print $2}' /etc/redis.conf)" redis-cli --no-auth-warning pubsub numsub reverb
```

Resultado da validação em 02/10/2026: handshake `101` + `connection_established`
pelo domínio público, broadcast do servidor aceito pelo Reverb, canal `reverb`
com 1 assinante no Redis, fila zerada e nenhum job com falha.

## 5. Problemas comuns

| Sintoma | Causa provável | O que fazer |
|---|---|---|
| Barra de progresso não anda, importação fica "Aguardando" | Worker da fila parado | Ver processo `queue:work`; conferir crontab (`crontab -l`) e `storage/logs/queue-worker.log` |
| Tela mostra "Conexão em tempo real indisponível" | Reverb parado, ou nginx sem o bloco `/app` | `ss -ltn \| grep 8080`; `storage/logs/reverb.log`; conferir o bloco no `default.conf` e `nginx -t` |
| `https://teste.easyeye.app/app/...` responde 502 | Reverb não está escutando em 127.0.0.1:8080 | Aguardar 1 min (cron) ou ver `reverb.log`; outro processo na 8080? |
| Navegador tenta conectar em host/porta errados | Build feito antes de ajustar `VITE_REVERB_*` | Corrigir `.env` e rodar `npm run build` |
| `/broadcasting/auth` responde 403 | Usuário sem permissão na tela, ou clínica da sessão ≠ clínica da importação (comportamento esperado) | Conferir papel do usuário; regra em `app/Broadcasting/ClinicImportChannel.php` |
| Mudança em evento/canal não tem efeito | Reverb e config antigos em memória/cache | `php artisan config:cache && php artisan route:cache && php artisan reverb:restart` |
| Links de paginação apontam para `https://_/...` (`ERR_NAME_NOT_RESOLVED`) | Navegador em HTTP/3 e nginx sem `fastcgi_param HTTP_HOST` | Reaplicar o ajuste de *Host repassado ao PHP sob HTTP/3* (seção 2.2) |
| Importação fica em "Processando" para sempre; medicamentos recusam novo envio ("outra em andamento") | Worker morto no meio (OOM, deploy). Sentry mostra `MaxAttemptsExceededException` do job | Desde 02/10/2026 o `failed()` dos jobs de importação encerra o import sozinho. Registro antigo travado: `php artisan tinker` e marcar `status=failed`, `finished_at=now()` |
| Depois de redeploy do nó, WebSocket parou | `default.conf` voltou ao padrão | Conferir se a linha continua em `/etc/jelastic/redeploy.conf`; reaplicar a seção 2.2 |

## 6. Como desfazer

```bash
cd /var/www/webroot/ROOT
cp -p .env.bak-reverb-20261002 .env && php artisan config:cache
cp -p /etc/nginx/conf.d/sites-enabled/default.conf.bak-reverb-20261002 /etc/nginx/conf.d/sites-enabled/default.conf
/usr/sbin/nginx -t && sudo -n /usr/bin/systemctl reload nginx
crontab -e   # remover as 3 entradas da EasyEye (manter a linha em branco final)
php artisan reverb:restart; php artisan queue:restart
```

(Os backups da crontab e do `redeploy.conf` ficaram em `/tmp`, que pode ser
limpo em reinício do nó — os da seção acima ficam junto dos originais.)

## 7. Segurança — pendências encontradas na configuração

1. **Token do GitHub na URL do remoto** (`git remote -v` em
   `/var/www/webroot/ROOT` mostra `https://usuario:ghp_…@github.com/...`).
   Qualquer pessoa com acesso ao nó lê o token. Revogar o token no GitHub e
   trocar por *deploy key* SSH somente leitura ou credential helper.
2. **Redis** escuta em `0.0.0.0` com `protected-mode no`, protegido só por
   senha. Confirmar no painel que a porta 6379 não está aberta para a
   internet (somente rede interna do ambiente).
3. `config/reverb.php` aceita qualquer origem (`allowed_origins => ['*']`).
   Sugestão: tornar configurável por variável e restringir a
   `teste.easyeye.app` neste ambiente.
4. Arquivos soltos não rastreados na raiz do app (`11.12.0` e `build`) —
   provavelmente resto de comando digitado errado; avaliar e remover.
5. **Allowlist de hosts desligada neste ambiente.** O middleware
   `TrustHosts` do Laravel não age quando `APP_ENV=testing` (o framework
   trata esse nome como "rodando testes"). Com `trustProxies(at: '*')` e o
   nó recebendo o tráfego direto, um cliente que envia
   `X-Forwarded-Host: outro-dominio` faz a aplicação usar esse host nas URLs
   montadas a partir da requisição. Verificado em 02/10/2026: o `location`
   da tela de login refletiu o host forjado. URLs de `route()`/`redirect()`
   e os links de paginação usam o `APP_URL` e não são afetados. Pelo mesmo
   motivo, `X-Forwarded-For` enviado pelo cliente é aceito como IP de origem
   (afeta *rate limit* por IP e o IP gravado na auditoria). Em produção
   (`APP_ENV=production`) a allowlist de hosts age, mas o `X-Forwarded-For`
   continua confiável para qualquer origem se o nó também recebe tráfego
   direto. Sugestão: restringir `trustProxies` aos IPs do balanceador (ou
   `127.0.0.1` quando não há balanceador) e usar um nome de ambiente próprio
   (ex.: `staging`) no servidor de teste, depois de revisar os pontos que
   checam `environment('testing')` (ex.: Sentry).

## 8. Produção

Mesmo desenho, ajustando `VITE_REVERB_HOST` para o domínio de produção e
gerando credenciais próprias. Para mais de ~1.000 conexões simultâneas:
extensão `ext-uv` no PHP (o loop padrão é limitado a 1.024 arquivos), e
`worker_rlimit_nofile`/`worker_connections` do nginx acima do número de
conexões esperado (hoje 2048). Referências: documentação oficial do
[Reverb](https://laravel.com/docs/12.x/reverb) e de
[Broadcasting](https://laravel.com/docs/12.x/broadcasting).
