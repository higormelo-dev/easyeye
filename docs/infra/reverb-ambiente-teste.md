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
