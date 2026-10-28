# Runbook — filas, workers e agendador

Origem: plano de performance F0+F1 (`docs/internal/planos/performance-f0-f1-plano.md`, §3.4–§3.9) · achados #1, #2, #16 e #18 de `docs/internal/planos/performance-filas-jobs.md`.

**Hipótese de invalidação:** refazer a leitura se mudar um `$timeout`/`retry_after`, a lista de filas de `.docker/supervisor/easyeye-worker.conf` ou das linhas de cron do ambiente de teste, ou se surgir um job `ShouldQueue` novo. Os testes `QueueWorkerSmokeTest` e `QueuePriorityTest` falham de propósito quando essas peças divergem.

## 1. Por que três filas

Antes, OTP do cadastro, webhook de pagamento, WhatsApp, IA, derivados de exame e importações de 10–15 min caíam numa fila FIFO só, com um worker. Duas importações paradas = fila parada por 10 min; o código de verificação (vale 10 min) vencia atrás delas. Agora:

| Fila | O que vai nela | Quem consome |
|---|---|---|
| `critical` | Código de verificação (OTP) do cadastro (`SendPhoneVerificationCodeJob`), mensagem recebida do WhatsApp (`ProcessWhatsAppInboundJob`), webhook de pagamento (`ProcessBillingWebhookJob`) | **fast-critical** (primeiro da lista) e, por último, o **fast-tiss** |
| `default` | IA, billing que não é webhook (cancelamento, renovação, retry, estorno), fiscal, WhatsApp (envio, status, aviso do SaaS, aviso cirúrgico), derivados de exame, e-mail da agenda, notificações e listeners | **fast-critical** (logo depois de `critical`) e **fast-tiss** (depois das TISS) |
| `tiss-xml`, `tiss-send`, `tiss-returns` | TISS (a fila é escolhida pelos services, `config/tiss.php`) | **fast-tiss** (primeiro) e **fast-critical** |
| `bulk` | 6 importações (CID-10, convênios, médicos, medicamentos, pacientes, agenda), posologia, catálogo de modelos de IA | worker **bulk**, só ele |

Mapa completo job a job: plano §3.5 e §3.6. Nenhum job `ShouldQueue` de `App\Jobs` fica sem fila nomeada (o `QueueWorkerSmokeTest` trava isso).

## 2. Conexões e `retry_after`

| Conexão | `retry_after` | Para quê | Maior job |
|---|---:|---|---|
| `redis` | **240 s** | workers fast | 180 s (IA); WhatsApp 150 s; TISS 120 s |
| `redis-bulk` | **960 s** | worker bulk | 900 s (medicamentos/CMED, convênios/ANS) |
| `database` | 960 s | dev/testes sem Redis e padrão do Docker (fast e bulk leem a mesma tabela `jobs`) | todos |

Regra: `retry_after` **sempre acima** do maior `$timeout` dos jobs que passam pela conexão. No Redis a reserva é um ZSET (`queues:{fila}:reserved`, score = agora + `retry_after`); vencida, o job volta para a fila e **outro** worker pega o **mesmo** job enquanto o primeiro ainda roda. O `retry_after` também é o tempo que um worker morto (OOM, `kill -9`) leva para devolver o job: 4 min no fast (era 16), 16 min no bulk.

**Os dois lados da fila precisam enxergar o mesmo Redis.** Os jobs bulk são *despachados* pela conexão padrão (`redis`) e só *consumidos* por `redis-bulk`. As duas usam o mesmo `REDIS_QUEUE_CONNECTION` (mesmo DB e prefixo). Se o consumidor apontar para outro DB, a fila `bulk` fica invisível e as importações ficam em "Aguardando". Não mude `REDIS_QUEUE_CONNECTION` só de um lado.

Chaves (`.env`; padrões em `config/`):

| Chave | Padrão | Observação |
|---|---|---|
| `BULK_QUEUE` | `bulk` | `config/queue.php` (`bulk_queue`); mude junto com o `--queue` do worker bulk |
| `REDIS_QUEUE_RETRY_AFTER` | `240` | só para ajustar; nunca abaixo de 190 |
| `REDIS_BULK_QUEUE_RETRY_AFTER` | `960` | só para ajustar; nunca abaixo de 900 |
| `BILLING_WEBHOOK_QUEUE` | `critical` | `.env` antigo com `default` desliga a prioridade do webhook: troque |
| `BILLING_JOBS_QUEUE` | `default` | os 7 jobs de billing que não são webhook |
| `WHATSAPP_QUEUE` | `default` | envio e status |
| `WHATSAPP_VERIFICATION_QUEUE` | `critical` | OTP |
| `WHATSAPP_INBOUND_QUEUE` | `critical` | mensagem recebida |
| `AI_QUEUE`, `FISCAL_QUEUE`, `TISS_QUEUE_XML/SEND/RETURNS` | `default`, `default`, `tiss-*` | sem mudança |

Trocar o nome de qualquer fila exige atualizar o `--queue` do worker (Supervisor **e** cron). O `QueueWorkerSmokeTest` compara as filas configuradas com as dos comandos e reprova se faltar alguma.

## 3. Workers

A conexão é **argumento posicional** do `queue:work` no Laravel 12: `queue:work redis-bulk --queue=bulk`. Não existe `--connection=`.

**Docker (Supervisor, 3 processos)** — `.docker/supervisor/easyeye-worker.conf`, 3 programas de 1 processo cada (mesma contagem de antes: 2 fast + 1 bulk):

| Programa | Filas (nessa ordem) | Log |
|---|---|---|
| `easyeye-worker-fast-critical` | `critical`, `default`, `tiss-xml`, `tiss-send`, `tiss-returns` | `easyeye-fast-critical.log` |
| `easyeye-worker-fast-tiss` | `tiss-xml`, `tiss-send`, `tiss-returns`, `default`, `critical` | `easyeye-fast-tiss.log` |
| `easyeye-worker-bulk` | `bulk` | `easyeye-bulk.log` |

Por que dois fast com ordens diferentes: uma rajada sustentada em `critical` (ex.: inbound do WhatsApp) prende quem lê `critical` primeiro, e `default` cheia continuamente prende quem lê `default` antes do TISS. Aqui cada fila tem um consumidor que a prioriza ou a atende logo após uma fila de volume baixo: `critical` é a primeira do `fast-critical`; as três filas TISS (lote esparso de 90–120 s) são as primeiras do `fast-tiss`, e `default` vem logo depois delas, então não fica órfã (o `fast-critical` também a atende, depois de `critical`). Os fast (`--timeout=190`, `--tries=3`, `--memory=256`, `stopwaitsecs=240`) usam a conexão `SUPERVISOR_QUEUE_CONNECTION` (compose: `QUEUE_CONNECTION_DOCKER`). O bulk (`--timeout=900`, `--tries=1`, `--memory=512`, `stopwaitsecs=960`) usa `SUPERVISOR_BULK_CONNECTION`, que o `.docker/php/entrypoint.sh` deriva da conexão efetiva: **`redis` → `redis-bulk`** (retry_after 960) e **`database` → `database`** (fast e bulk leem a mesma tabela `jobs`; retry_after 960 ≥ 900). O Redis não está no compose (é externo, `host.docker.internal`), então o padrão `database` continua funcionando e as importações têm consumidor nos dois modos.

**SaveInCloud (cron + `flock`, 3 processos)** — padrão: **3 linhas** de cron, uma por programa, cada uma com `flock` e log próprios e `--queue=` explícito (decisão O1, reversível pelo dono; texto completo, deploy e vigilância em `docs/internal/infra/reverb-ambiente-teste.md`, §2.3):

```cron
* * * * * cd /var/www/webroot/ROOT && /usr/bin/flock -n /tmp/easyeye-queue-fast-critical.lock /usr/bin/php artisan queue:work redis --queue=critical,default,tiss-xml,tiss-send,tiss-returns --sleep=1 --timeout=190 --tries=3 --max-time=3600 --memory=256 -q >> storage/logs/queue-worker-fast-critical.log 2>&1
* * * * * cd /var/www/webroot/ROOT && /usr/bin/flock -n /tmp/easyeye-queue-fast-tiss.lock /usr/bin/php artisan queue:work redis --queue=tiss-xml,tiss-send,tiss-returns,default,critical --sleep=1 --timeout=190 --tries=3 --max-time=3600 --memory=256 -q >> storage/logs/queue-worker-fast-tiss.log 2>&1
* * * * * cd /var/www/webroot/ROOT && /usr/bin/flock -n /tmp/easyeye-queue-bulk.lock /usr/bin/php artisan queue:work redis-bulk --queue=bulk --sleep=3 --timeout=900 --tries=1 --max-time=3600 --memory=512 -q >> storage/logs/queue-worker-bulk.log 2>&1
```

Aplicar: editar o crontab → `php artisan schedule:clear-cache` → `php artisan queue:restart` (a crontab não vem do `git pull`). **Com 1 fast só, `default`/TISS podem ficar sem consumo sob rajada de `critical`; não usar.** Mesmo com os dois fast, a `critical` só ganha *entre* jobs: um job TISS de 120 s atrasa um OTP no processo que o pegou (o outro fast atende o OTP).

**Monitoramento (sem serviço novo):** `redis-cli llen queues:critical`, `redis-cli llen queues:default` e `redis-cli llen queues:tiss-xml` (com o prefixo da aplicação, ex.: `easyeye_database_queues:critical`; `redis-cli --scan --pattern '*queues:*'` mostra as chaves). `default` ou TISS crescendo enquanto `critical` não zera = só há um fast rodando (ou o crontab ainda tem a linha antiga); o `queue:monitor` da §5 alerta quando passa de `--max`.

**Local** — o `queue:listen` do `composer dev` lê só `default`. Para OTP/webhook: `queue:listen --queue=critical,default`; para importações, um segundo worker `queue:work redis-bulk --queue=bulk` (ou `queue:work database --queue=bulk` quando a fila local é a tabela `jobs`).

## 4. Agendador (`routes/console.php`)

- `onOneServer()` em toda entrada: com `CACHE_STORE=redis` só um nó roda cada tarefa; em um nó só é inócuo (confirme o cache em produção).
- `runInBackground()` nas tarefas que chamam gateway, WhatsApp, Focus NFe ou varrem muito (13): `billing:dunning`, `billing:reconcile-overdue`, `billing:check-refunds`, `billing:gateway-health`, `whatsapp:send-confirmations`, `whatsapp:send-surveys`, `recall:send-invites`, `recall:evaluate-dirty`, `recall:reconcile`, `recall:verify-integrity`, `integrator-outbox:publish`, `fiscal:nfse-sync`, `fiscal:nfse-reissue`. O agendador não espera por elas. Só existe em `Schedule::command` (callback e job não suportam).
- `withoutOverlapping(10)` nas frequentes e `withoutOverlapping(30)` em `recall:send-invites` (pode passar de 10 min); as diárias mantêm o padrão de 24 h. O mutex é **liberado no fim de cada execução** (`schedule:finish`); o prazo só vale se o processo morrer antes. Depois de um deploy ou kill, `php artisan schedule:clear-cache` solta mutex órfãos (rode fora de uma tarefa longa).
- `queue:prune-failed --hours=720` às 04:20: `failed_jobs` guarda 30 dias.
- `php artisan schedule:list` mostra as entradas; `tests/Feature/Queue/SchedulePriorityTest.php` trava cron, segundo plano, prazo do mutex e `onOneServer` de cada uma.

## 4.1 Risco residual (prioridade fixa)

**Aceite do dono: pendente (22/10).** Os dois fast usam prioridade *fixa* (ordem do `--queue`), que atenua mas não elimina a starvation. Com `critical` **e** `tiss-xml` continuamente cheias ao mesmo tempo, o `fast-critical` fica preso em `critical` e o `fast-tiss` em `tiss-xml`: `default`, `tiss-send` e `tiss-returns` não têm consumidor até uma das duas esvaziar.

Por que é improvável (premissas, não garantias):
- **`critical`:** o OTP tem rate limit por telefone; o inbound do WhatsApp é limitado pelo provedor (taxa de entrega do Gupshup); o webhook de pagamento é idempotente e esparso.
- **`tiss-xml`:** um XML por lote, disparado por um usuário (`GenerateTissBatchXmlJob` é `ShouldBeUnique`), não por evento automático.

Sinal: `redis-cli llen queues:default` crescendo **enquanto** `redis-cli llen queues:critical` e `redis-cli llen queues:tiss-xml` estão acima de 0 por mais de N minutos (sugestão: 10; ajuste pela experiência). `queue:monitor` (§5) alerta quando uma fila passa de `--max`.

Correção definitiva (fase seguinte, se o dono não aceitar o risco): **Horizon com `balance=auto`** (rotação entre filas, sem prioridade fixa) ou **um worker por fila**. Nenhuma das duas faz parte desta entrega.

## 5. Deploy e rollback

```bash
php artisan config:cache
php artisan schedule:clear-cache
php artisan queue:restart          # os 3 workers terminam o job corrente e o cron/Supervisor sobe os novos
```

Depois: 3 processos `queue:work` vivos (dois em `redis`, fast-critical e fast-tiss, e um em `redis-bulk`), os três logs andando, `queue:monitor` sem alerta, `queue:failed` sem job novo.

```bash
php artisan queue:monitor redis:critical,redis:default,redis:tiss-xml,redis:tiss-send,redis:tiss-returns,redis-bulk:bulk --max=1000
```

**Rollback** (não há migration nem dado): reverter config/Supervisor/código, `config:cache` e `queue:restart`. Antes de voltar o worker antigo, **drene** `critical` e `bulk` com um worker temporário (`queue:work redis --queue=critical,bulk --stop-when-empty`): o worker antigo não lê essas filas. `queue:prune-failed` não tem rollback (já apagou). A mudança do `.env` (`BILLING_WEBHOOK_QUEUE`, `WHATSAPP_*_QUEUE`) só precisa ser revertida se o worker antigo voltar.

## 6. Falhas comuns

| Sintoma | Causa provável | O que fazer |
|---|---|---|
| Importação "Aguardando" para sempre | Worker bulk parado, ou consumidor em outro Redis DB/prefixo, ou ambiente caiu em `QUEUE_CONNECTION=database` | `ps` pelo `queue:work redis-bulk`; `queue-worker-bulk.log`; comparar `REDIS_QUEUE_CONNECTION` |
| OTP/webhook de pagamento demora | Worker fast parado ou preso num job longo; `.env` ainda com `*_QUEUE=default` | `queue-worker-fast.log`; `queue:monitor`; conferir as chaves da §2 |
| Job executado duas vezes | `retry_after` abaixo do `$timeout` do job (alguém mexeu no `.env`) | `config:show queue.connections.redis` / `redis-bulk`; restaurar 240/960 |
| Tarefa frequente "parou" por horas | Processo morto antes do `schedule:finish`: mutex órfão | `schedule:clear-cache` |
| Worker não sobe e o log diz `The "--connection" option does not exist` | `queue:work --connection=...` (a opção não existe no Laravel 12) | usar a conexão como argumento posicional |

## 7. Observabilidade (F0)

- `db.slow_query` (warning no log): uma vez por conexão/processo, quando o tempo **acumulado** das queries passa de 500 ms. Traz `connection`, `duration_ms` (a query que estourou), `total_duration_ms` (o acumulado), `route` (nome da rota), `command` e `job`. **Nunca** `sql`, `bindings` ou URL.
- `eloquent.lazy_loading_violation`: lazy loading proibido fora de produção; em `testing` só loga `model` e `relation`, em local/staging faz `report()`. Não lança. Dívida levantada: `docs/internal/planos/performance-lazy-loading-divida.md`.
- `SENTRY_TRACES_SAMPLE_RATE=0.1` (10%) no `.env`; sem DSN nada é enviado. `LOG_STACK=daily` e `LOG_LEVEL=info`: depois de mudar, `config:cache` e `queue:restart` (o worker guarda a config).
- `pg_stat_statements` e baseline `EXPLAIN`: `docs/internal/runbooks/postgres-pg-stat-statements.md`.
