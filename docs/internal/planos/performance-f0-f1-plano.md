# Plano — Performance F0 (Enxergar) + F1 (Filas com prioridade)

Data: 22/10/2026 · Autor: Bússola (planejador) · Origem: Claude Code, a partir de `docs/internal/planos/performance-filas-jobs.md` (achados #1, #2, #16, #17, #18)

Âncoras conferidas no working tree em 22/10/2026: `app/Providers/AppServiceProvider.php` (`boot()`), `config/queue.php`, `config/billing.php`, `config/whatsapp.php`, `config/broadcasting.php`, `.docker/supervisor/easyeye-worker.conf`, `routes/console.php`, `docs/internal/infra/reverb-ambiente-teste.md`, `tests/Integration/QueueWorkerSmokeTest.php`, `tests/Integration/QueueRedisReservationTest.php`, `tests/Integration/Support/Ee09SlowMarkerJob.php`, `tests/Feature/Auth/PhoneVerificationTest.php`, `.env.example`.

**Este documento é só plano.** Não altera código, não lê `.env`, não executa `EXPLAIN`/`pg_stat_statements`, não conecta Sentry e não faz commit/push. A implementação é da Forja; revisão de Lupa/Sentinela; commit só com “commit autorizado”.

**Hipótese de invalidação:** refazer a leitura se (a) o commit da série de sessões mudar linhas de `SchedulesController`/`ScheduleService` (não devem ser tocados), (b) algum `$timeout`/`retry_after` mudar antes da implementação, (c) `ScheduleNotification` ganhar novo chamador, (d) o comando do Laravel 12 mudar a assinatura de `queue:work`, ou (e) o dono mudar as decisões da seção 2.2.

---

## 0. Regras que valem para esta entrega

- **Isolamento por tenant:** fila/prioridade não pode alterar resolução de `entity_id`, sessão `selected_entity_id`, gates ou policies. Jobs/CLI continuam resolvendo a entidade explicitamente quando necessário (`docs/internal/engineering/domain-invariants.md`, Convenções 3 e Pontos de atenção 3).
- **Billing:** `ProcessBillingWebhookJob` continua idempotente (`WebhookEvent` + `ProcessWebhookEventService`); a mudança é só de fila. Não alterar valores, status, retries ou ordem financeira.
- **TISS:** `GenerateTissBatchXmlJob`, `SendTissBatchJob` e `ProcessTissReturnJob` continuam `ShouldBeUnique` e nas filas `tiss-xml`/`tiss-send`/`tiss-returns`; o worker fast passa a consumir as três. Não trocar as filas nem a ordem do fluxo.
- **Agenda/locks:** `NotifyScheduleChangeJob` continua sendo despachado depois do commit (`afterCommit()`); `CreatePostOpReturns` continua fora do lock do caso cirúrgico. Nenhuma mudança de lock, transação ou ordem de observers.
- **Recall/WhatsApp:** `SendWhatsAppMessageJob` revalida `RecallInviteGuard` no envio; atraso de fila não pode ser usado para pular essa revalidação. `recall:requeue-pending` continua sendo a rede de segurança para mensagens presas.
- **LGPD/prontuário:** logs de query lenta só podem ter conexão, duração e rota/job. **Nunca** `sql`, `bindings`, nome de paciente, CPF, e-mail, conteúdo clínico ou payload. `ScheduleNotification` deixa de ser serializada na fila (o job carrega apenas `scheduleId`/evento/observação). `GenerateExamDerivatives` só muda fila/tentativas; não altera conteúdo, assinatura, versionamento ou auditoria do prontuário. `ImageReportDraftNotification`/`RequestingDoctorReportReady` não são tocados.
- **Testes/banco:** somente PostgreSQL `easyeye_test`, sem `--parallel`; `RefreshDatabase`/`DatabaseMigrations` recriam schema e exigem autorização; nenhum envio externo real; Sentry não conectado; não ler/imprimir `.env`.
- **Comandos:** `queue:work` no Laravel 12 recebe a **conexão como argumento posicional**; `--connection=` não existe. O plano corrige a menção da auditoria: `queue:work redis-bulk --queue=bulk`, não `queue:work --connection=redis-bulk`.
- **Escopo de arquivos:** não tocar nos arquivos da entrega “remarcar série” ainda não commitada, nem em `.claude-flow/*`/`.claude/settings.json`. Lista explícita na seção 9.
- **Sem commit/push** e sem editar `composer.json`/frontend nesta entrega.

---

## 1. Resultado esperado

### F0 — Enxergar

1. Lazy loading proibido **fora de produção** com callback que **reporta/loga e não lança**; violações viram dívida mensurável.
2. Query acima de 500 ms loga apenas metadados seguros (conexão, duração, rota/job).
3. `.env.example` com logs diários em `info` e Sentry com amostragem de 10%, sem criar `config/sentry.php` nem conectar Sentry.
4. Runbook operacional de `pg_stat_statements` + procedimento de baseline `EXPLAIN` dos 5 hot paths, **sem executar** contra banco real.

### F1 — Filas com prioridade

1. Conexão `redis` com `retry_after=240` para jobs fast; nova conexão `redis-bulk` com `retry_after=960` e `block_for=null`.
2. Filas `critical`, `bulk`, `default` e as três TISS; nenhum job `ShouldQueue` sem fila explícita.
3. Worker fast consome `critical,default,tiss-*`; worker bulk consome `bulk` na conexão `redis-bulk`, com 1 processo. **Ajustado em 22/10 (Sentinela + orquestrador, reversível pelo dono):** 3 processos nos dois ambientes — Docker: programas `fast-critical`, `fast-tiss` e `bulk`; SaveInCloud: 3 linhas de cron (`fast-critical`, `fast-tiss`, `bulk`).
4. `ScheduleNotification` deixa de enfileirar um segundo job; `GenerateExamDerivatives` tem `$tries=2` + `$backoff=60` (ajuste de 22/10 após a Lupa; era `$tries=1`) e fila `default` explícita.
5. Tarefas pesadas do agendador vão para background; tarefas frequentes têm lock curto; todas as tarefas podem usar `onOneServer()` quando o cache for Redis.
6. `queue:prune-failed --hours=720` diário; timeouts de broadcast do Reverb; testes e docs alinhados.

**Fora de escopo:** F2/F3 (whereDate, índices, buscas), F4 (imports/posologia/PDF), correções de N+1, execução de EXPLAIN/pg_stat_statements, DSN do Sentry, frontend, migrations e autoscaling.

---

## 2. Decisões

### 2.1 Decisões técnicas (adotadas)

| # | Decisão | Justificativa |
|---|---|---|
| D1 | **Lazy loading: opção (b)** — `preventLazyLoading(! app()->isProduction())` + callback que **não lança**. Em `testing` só `Log::warning`; em local/staging `report()`; produção desligado. | A opção (a) (lançar) quebraria testes e requests existentes com N+1. (b) expõe a dívida sem mudar comportamento funcional; a correção fica para F3/F4. |
| D2 | Query lenta: `DB::whenQueryingForLongerThan(500, ...)` loga `connection`, `duration_ms`, `total_duration_ms`, `route` e `job`; nunca SQL/bindings. | O gatilho é a duração **acumulada** da conexão; o log precisa deixar claro o total e a query corrente, sem vazar dado clínico. |
| D3 | Não criar `config/sentry.php`; o pacote Sentry faz merge do config de `vendor/` e lê `SENTRY_TRACES_SAMPLE_RATE`. | Evita duplicar/ divergir config; só `.env.example` é necessário. Não conectar Sentry nesta entrega. |
| D4 | `ScheduleNotification` **remove `ShouldQueue`** (e `Queueable`); o retry fica no `NotifyScheduleChangeJob` (`tries=3`, `backoff=60`). | O job já é a unidade durável; a notificação enfileirada criava um segundo job e serializava `Schedule`/paciente no Redis. Alternativa `notifyNow` manteria o risco se outro caller usar `notify`. |
| D5 | Jobs bulk **não** usam `onConnection('redis-bulk')`; continuam na conexão default (`redis`). O worker bulk é só um consumidor da mesma fila Redis. | Se o job fixar `redis-bulk`, testes com `QUEUE_CONNECTION=sync` e dispatches existentes mudam de conexão. Com o mesmo DB/prefix, `queues:bulk` é visível para o worker `redis-bulk`. |
| D6 | `redis` = 240 s; `redis-bulk` = 960 s; `database` continua 960 s. | Fast: maior timeout 180 s (AI), WhatsApp 150 s, TISS 120 s; worker `--timeout=190`. Bulk: imports 900 s; worker `--timeout=900`. |
| D7 | Separar `billing.webhooks.queue` (`critical`) de `billing.jobs.queue` (`default`). Só `ProcessBillingWebhookJob` é crítico. | Os 7 jobs não-webhook (cancelamentos, renovação, retry, refund) não podem herdar `critical` só porque compartilham a mesma config. |
| D8 | `WHATSAPP_QUEUE=default` para envio/status; `WHATSAPP_VERIFICATION_QUEUE=critical` para OTP; `WHATSAPP_INBOUND_QUEUE=critical` para inbound. | OTP e inbound são sensíveis a atraso; envio/status em massa não deve competir com eles. |
| D9 | `GenerateExamDerivatives`: `$tries=2` + `$backoff=60` (ajustado de `$tries=1` em 22/10, Lupa P3-1: uma só tentativa tornava definitiva a falha transitória de S3, pois o outbox apaga o intent no dispatch), fila `default` literal. | A geração é determinística e o outbox/intent é durável; retries repetiam 3 leituras de S3 e não corrigiam falha de memória/formato. |
| D10 | `runInBackground()` nos 7 comandos pesados da auditoria + 6 comandos externos/pesados; `withoutOverlapping(10)` nas frequentes; `onOneServer()` em todas as entradas. | O scheduler não pode ficar preso em gateway/WhatsApp/outbox. O `schedule:finish` libera o mutex ao terminar; `withoutOverlapping(10)` limita a sobreposição e o TTL de 10 min é a folga se o processo morrer antes do finish. Exceção: `recall:send-invites` mantém 30 (ver D11). |
| D11 | `recall:send-invites` mantém `withoutOverlapping(30)`; as demais frequentes passam a 10. | O comando pode passar de 10 min; reduzir para 10 permitiria duas rodadas simultâneas. O valor atual é intencional e fica documentado como exceção. |
| D12 | `redis-bulk` usa o **mesmo** `REDIS_QUEUE_CONNECTION`/DB/prefix de `redis`. | O job é despachado na conexão default; se o consumidor apontar para outro DB, a fila `bulk` fica invisível. Teste e runbook travam isso. |
| D13 | Sem `--connection=` no supervisor/cron; conexão posicional. Docker e SaveInCloud: 3 processos (`fast-critical`, `fast-tiss`, `bulk`; ver O1). | Correção técnica do Laravel 12; com 1 fast só `default`/TISS podem ficar sem consumo sob rajada de `critical`, então o fast foi dividido em dois programas de ordens diferentes (decidido em 22/10). |
| D14 | `queue:prune-failed --hours=720` diário às 04:20. | 30 dias de retenção para diagnóstico; `failed_jobs` não cresce sem teto. |
| D15 | `onOneServer()` em todas as entradas do scheduler. | Com `CACHE_STORE=redis` é coordenação real; em um nó é no-op. Se produção não usar Redis como cache, o efeito é nulo (decisão do dono). |
| D16 | Timeouts do cliente Reverb: `timeout=2`, `connect_timeout=1`. | Broadcast não pode pendurar request/worker; os eventos usam `ShouldBroadcastNow`, então o timeout do cliente é o limite. |
| D17 | Posologia: **F4**, não F1. | Trocar `Sleep::for()` por `dispatch()->delay()` exige dividir grupos em jobs e preservar lock/retomada; o worker bulk já isola o efeito do crítico. |

### 2.2 Decisões do dono (defaults propostos; não bloqueiam)

| # | Decisão | Default proposto | Impacto / alternativa |
|---|---|---|---|
| O1 | Quantos workers no SaveInCloud? | **3 processos no SaveInCloud (`fast-critical`, `fast-tiss`, `bulk`) — decidido em 22/10 pelo orquestrador, reversível pelo dono.** Docker: os mesmos 3 programas. | O desenho original (1 cron fast + 1 bulk) foi **descartado**: com 1 fast, `default` e TISS podem ficar sem consumo sob rajada de `critical` (a prioridade só vale entre jobs). Custo: ~256 MB a mais. Risco residual que sobra (`critical` e `tiss-xml` saturadas ao mesmo tempo) e correção definitiva (Horizon com `balance=auto` ou um worker por fila): `docs/internal/runbooks/filas-workers.md`, "Risco residual". |
| O2 | Amostragem de traces do Sentry | `SENTRY_TRACES_SAMPLE_RATE=0.1` | 10% das transações; mais custo/menos volume se aumentar. DSN continua vazio no example; nada é enviado sem DSN. |
| O3 | Retenção de logs | `LOG_STACK=daily`, `LOG_LEVEL=info`, 14 dias (default do canal) | Menos ruído e rotação; `debug` continua disponível localmente. |
| O4 | `GenerateExamDerivatives` retry mínimo | `$tries=2` + `$backoff=60` (era `$tries=1`; ajustado em 22/10) | Falha transitória de S3 não repete; o intent/outbox permite novo comando. Alternativa: 2 tentativas (custo de S3 e tempo de worker). |
| O5 | Escopo do `critical` em billing | Só webhook; jobs não-webhook em `default` | Fila crítica enxuta. Se a Lupa apontar risco de cobrança dupla por atraso em `CancelGateway*`/`SendBillingRefundJob`, promover só esses à `critical`. |
| O6 | `WHATSAPP_QUEUE` | `default` | Se o dono quiser todo WhatsApp em `critical`, muda a env e o worker; o default evita competir com OTP/inbound. |
| O7 | Retenção de `failed_jobs` | 720 h (30 dias) | 90 dias ajuda diagnóstico, mas ocupa mais; a tarefa é diária. |
| O8 | `CACHE_STORE=redis` em produção | Confirmar | `onOneServer()` só coordena com cache compartilhado; se produção usar `file`/`array`, o default é inócuo. |
| O9 | `pg_stat_statements`/EXPLAIN | DBA executa em janela/read replica, com dados representativos e sem exportar texto bruto | O agente não executa; a extensão pode exigir `shared_preload_libraries` e restart. |
| O10 | `BULK_QUEUE` e filas custom | `bulk`/`critical` | Trocar a env exige atualizar `--queue` do worker e `QueueWorkerSmokeTest`; o teste falha se esquecer. |

**O11 — não é decisão; comportamento verificado:** `runInBackground()` + `withoutOverlapping(10)` **não** reduz a cadência das tarefas de minuto. O `CommandBuilder::buildBackgroundCommand` encadeia `php artisan schedule:finish "<mutexName>" "$?"` ao comando em background; `ScheduleFinishCommand::handle` encontra o event pelo `mutexName()` e chama `Event::finish()`, que executa os after callbacks e, no `finally`, `removeMutex()`. O mutex é liberado quando o processo termina; os 10 minutos são apenas o teto de expiração se o processo morrer (kill/OOM) antes do `schedule:finish`. Assim, `recall:evaluate-dirty` e `integrator-outbox:publish` continuam rodando a cada minuto (desde que a execução anterior tenha terminado) e `fiscal:nfse-sync` continua a cada 10 min. Se um processo em background morrer antes do `finish`, o mutex fica até expirar; `php artisan schedule:clear-cache` libera na hora. As decisões de D10/D11 (locks 10/30) permanecem; a justificativa de D10 foi alinhada ao `schedule:finish`.

---

## 3. Contratos

### 3.1 F0 — observabilidade (AppServiceProvider)

Inserir no início de `boot()` de `app/Providers/AppServiceProvider.php` (imports no topo; `DB` já está importado no grupo `Auth, Blade, DB, Gate...`, então **não duplicar**):

```php
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\LazyLoadingViolationException;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\{Context, DB, Log, Queue};

// 1) Lazy loading: reporta, não lança. Em testing só loga (sem Sentry/report).
Model::preventLazyLoading(! app()->isProduction());

Model::handleLazyLoadingViolationUsing(function (Model $model, string $relation): void {
    $context = [
        'model'    => $model::class,
        'relation' => $relation,
    ];

    if (app()->environment('testing')) {
        Log::warning('eloquent.lazy_loading_violation', $context);
        return;
    }

    // local/staging: report() captura stack/Sentry quando configurado; não interrompe.
    report(new LazyLoadingViolationException($model, $relation));
});

// 2) Nome do job no contexto do log (sem payload).
Queue::before(function (JobProcessing $event): void {
    Context::add('job', $event->job->resolveName());
});
Queue::after(fn () => Context::forget('job'));
Queue::failing(fn () => Context::forget('job'));

// 3) Query lenta: só metadados; nunca sql/bindings.
DB::whenQueryingForLongerThan(500, function (Connection $connection, QueryExecuted $query): void {
    Log::warning('db.slow_query', [
        'connection'        => $connection->getName(),
        'duration_ms'       => round((float) $query->time, 1),
        'total_duration_ms' => round($connection->totalQueryDuration(), 1),
        'route'             => app()->runningInConsole() ? null : request()->route()?->getName(),
        'command'           => app()->runningInConsole() ? ($_SERVER['argv'][1] ?? null) : null,
        'job'               => Context::get('job'),
    ]);
});
```

Regras do contrato:

- `preventLazyLoading` **não** é chamado em produção; em testing/local/staging é `true`.
- O callback nunca lança; em testing não chama `report()` nem Sentry.
- `route` é o **nome** da rota, não a URL; `command` é só o nome do comando CLI (`argv[1]`); `job` é a classe do job via `Context`, não o payload.
- `DB::whenQueryingForLongerThan` dispara **uma vez por conexão/processo** (Laravel marca `has_run`); em worker longo, o primeiro evento é o alerta. O log traz `total_duration_ms` para explicar o gatilho; se F2 precisar de toda query, trocar por `DB::listen` com threshold por query (fora de F0).
- Proibido logar `sql`, `bindings`, `request()->fullUrl()`, `request()->all()` ou qualquer atributo de paciente.
- O `Context::add('job')` é limpo em `after`/`failing`; não vaza para o próximo job.

**Limitação assumida:** a dívida de N+1 não é corrigida; só listada. A coleta deve ser feita após a suíte e registrada em `docs/internal/planos/performance-lazy-loading-divida.md` (novo), com `model`, `relation`, origem/teste e destino (F3/F4).

### 3.2 F0 — env e logs

`.env.example` (somente exemplo; não ler `.env` real):

```dotenv
# linhas 60-62
LOG_CHANNEL=stack
LOG_STACK=daily
LOG_DEPRECATIONS_CHANNEL=null
LOG_LEVEL=info

# linha 205 (Sentry)
SENTRY_LARAVEL_DSN=
SENTRY_TRACES_SAMPLE_RATE=0.1
```

- Não alterar `config/logging.php`: o canal `daily` já existe e é selecionado por `LOG_STACK=daily`; rotação/validade continuam pelo config atual.
- Não criar `config/sentry.php`: o provider do pacote faz `mergeConfigFrom(vendor/sentry/sentry-laravel/config/sentry.php)`; `traces_sample_rate` lê a env. Sem DSN, nada é enviado.
- Nota de deploy: produção pode estar com `LOG_STACK=single`/`LOG_LEVEL=debug`; ao aplicar, rodar `config:cache` e reiniciar workers para o novo nível valer.

### 3.3 F0 — runbook de `pg_stat_statements` e baseline

Criar `docs/internal/runbooks/postgres-pg-stat-statements.md` (novo), curto e operacional, com:

1. **Segurança**: somente leitura; não colar/exportar `query` bruta (pode conter literal/PHI); usar `queryid` e trechos normalizados; janela/read replica quando possível.
2. **Pré-requisitos**: extensão instalada, `shared_preload_libraries='pg_stat_statements'`, restart do PostgreSQL (DBA), `CREATE EXTENSION IF NOT EXISTS pg_stat_statements;`, `pg_stat_statements.max`/`track`.
3. **Coleta**: top por `total_exec_time`, `mean_exec_time`, `calls`, `rows`, `shared_blks_*`, `temp_blks_*`; filtro por `dbid`/`userid`; reset consciente (`pg_stat_statements_reset()`).
4. **Baseline dos 5 hot paths** (procedimento, sem executar aqui): Dashboard (`PanelDashboardController::scheduleToday`), Agenda mês (`AgendaQueryService::schedules`), busca de pacientes (`PatientsController`/`AccentInsensitiveSearch`), validação/prévia de série (`ScheduleService::validateFor`), worklist/offline snapshot (`Api\SchedulesController`/`OfflineSnapshotController`).
   - Para cada um: capturar o SQL do query builder, substituir literais por placeholders, rodar `EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON)` em transação `READ ONLY`/réplica, com `entity_id` e intervalo representativos (sem paciente real), registrar commit/data/versão do Postgres e o JSON sem dados pessoais.
   - Comparar `Seq Scan` vs `Index Scan`, `Nested Loop` vs `Hash Join`, `temp read/written`, `rows` estimado vs real; anexar conclusão curta.
5. **Limitações**: estatísticas acumulam desde o restart; query text pode ter literais; extensão tem overhead; não é profiling de aplicação. A execução e a decisão de índices são F3/DBA.

### 3.4 F1 — conexões e filas

`config/queue.php`:

| Conexão | Driver | `retry_after` | `block_for` | Consumidor |
|---|---|---:|---|---|
| `sync` | sync | — | — | testes (`phpunit.xml`) |
| `database` | database | 960 | — | dev/testes/integração; default do config se env faltar |
| `redis` | redis | **240** | `null` | worker fast (crítico/default/TISS) |
| `redis-bulk` | redis | **960** | `null` | worker bulk (`bulk`) |

- `redis-bulk.connection` = `env('REDIS_QUEUE_CONNECTION', 'default')` (mesmo DB do `redis`); `queue` = `env('REDIS_BULK_QUEUE', 'bulk')`; `retry_after` = `env('REDIS_BULK_QUEUE_RETRY_AFTER', 960)`.
- `queue.bulk_queue` = `env('BULK_QUEUE', 'bulk') ?: 'bulk'` (topo do config).
- `database.retry_after` permanece 960 (comportamento atual).
- `redis.retry_after` cai de 960 para 240: é a maior folga para fast; o bulk não usa mais `redis`.

**Mapa de filas:**

| Fila | Jobs | Motivo |
|---|---|---|
| `critical` | `SendPhoneVerificationCodeJob`, `ProcessWhatsAppInboundJob`, `ProcessBillingWebhookJob` | OTP, inbound e webhook de pagamento; atraso tem impacto direto. |
| `bulk` | 6 imports + `ProcessMedicinePosologyBatchJob` + `SyncAiModelCatalogJob` | Trabalho longo (600–900 s) que não pode bloquear crítico/default. |
| `default` | IA, billing não-webhook, fiscal, WhatsApp outbound/status, surgery notice, `GenerateExamDerivatives`, `NotifyScheduleChangeJob`, notificações/listeners | Prioridade normal. |
| `tiss-xml` | `GenerateTissBatchXmlJob` | Mantida; o worker fast lê. |
| `tiss-send` | `SendTissBatchJob` | Mantida; o worker fast lê. |
| `tiss-returns` | `ProcessTissReturnJob` | Mantida; o worker fast lê. |

**Chaves e defaults:**

| Chave | Default | Onde |
|---|---|---|
| `BULK_QUEUE` | `bulk` | `config/queue.php` (`bulk_queue`) |
| `BILLING_WEBHOOK_QUEUE` | `critical` | `config/billing.php` (`webhooks.queue`) |
| `BILLING_JOBS_QUEUE` | `default` | `config/billing.php` (`jobs.queue`, novo) |
| `WHATSAPP_QUEUE` | `default` | `config/whatsapp.php` (`queue`) |
| `WHATSAPP_VERIFICATION_QUEUE` | `critical` | `config/whatsapp.php` (`verification_queue`, novo) |
| `WHATSAPP_INBOUND_QUEUE` | `critical` | `config/whatsapp.php` (`inbound_queue`, novo) |
| `AI_QUEUE` | `default` | `config/ai.php` (sem mudança) |
| `FISCAL_QUEUE` | `default` | `config/fiscal.php` (sem mudança) |
| `TISS_QUEUE_XML/SEND/RETURNS` | `tiss-xml`/`tiss-send`/`tiss-returns` | `config/tiss.php` (sem mudança) |

> Trocar qualquer fila acima exige atualizar o `--queue` do worker e o `QueueWorkerSmokeTest`; o teste falha de propósito se a env e o worker divergirem.

### 3.5 F1 — mapa completo de jobs `ShouldQueue`

Todos os caminhos abaixo são relativos a `app/`; “sem fila” = não tinha `onQueue`/`viaQueue` antes.

| Arquivo | Classe | Fila | Timeout / tries | Motivo |
|---|---|---|---:|---|
| `Domains/Tiss/Jobs/GenerateTissBatchXmlJob.php` | `GenerateTissBatchXmlJob` | `tiss-xml` | 120 / 5 | XML TISS; unique; fila definida no `TissWorkflowService` |
| `Domains/Tiss/Jobs/SendTissBatchJob.php` | `SendTissBatchJob` | `tiss-send` | 90 / 5 | Envio TISS; unique; fila no service |
| `Domains/Tiss/Jobs/ProcessTissReturnJob.php` | `ProcessTissReturnJob` | `tiss-returns` | 120 / 7 | Retorno TISS; unique; fila no `ReceiveTissResponseService` |
| `Jobs/WhatsApp/SendPhoneVerificationCodeJob.php` | `SendPhoneVerificationCodeJob` | `critical` | 150 / 3 | OTP; `ShouldBeEncrypted`; antes `whatsapp.queue` |
| `Jobs/WhatsApp/ProcessWhatsAppInboundJob.php` | `ProcessWhatsAppInboundJob` | `critical` | 150 / 3 | Inbound; unique; antes `whatsapp.queue` |
| `Jobs/Billing/ProcessBillingWebhookJob.php` | `ProcessBillingWebhookJob` | `critical` | default / 5 | Webhook idempotente; antes `billing.webhooks.queue` (default) |
| `Jobs/ProcessCid10ImportJob.php` | `ProcessCid10ImportJob` | `bulk` | 600 / 1 | Import CID-10; **sem fila** |
| `Jobs/ProcessCovenantImportJob.php` | `ProcessCovenantImportJob` | `bulk` | 900 / 1 | ANS/convênios; **sem fila** |
| `Jobs/ProcessDoctorImportJob.php` | `ProcessDoctorImportJob` | `bulk` | 600 / 1 | Médicos; **sem fila** |
| `Jobs/ProcessMedicineImportJob.php` | `ProcessMedicineImportJob` | `bulk` | 900 / 1 | CMED/medicamentos; **sem fila** |
| `Jobs/ProcessPatientImportJob.php` | `ProcessPatientImportJob` | `bulk` | 600 / 1 | Pacientes; **sem fila** |
| `Jobs/ProcessScheduleImportJob.php` | `ProcessScheduleImportJob` | `bulk` | 600 / 1 | Agenda; **sem fila** |
| `Jobs/ProcessMedicinePosologyBatchJob.php` | `ProcessMedicinePosologyBatchJob` | `bulk` | 300 / 3 | Posologia; **sem fila**; self-dispatch continua |
| `Jobs/SyncAiModelCatalogJob.php` | `SyncAiModelCatalogJob` | `bulk` | 300 / 1 | Catálogo IA; **sem fila** |
| `Jobs/AI/RunAiWorkflowJob.php` | `RunAiWorkflowJob` | `default` (`AI_QUEUE`) | 180 / 3 (config) | IA; `ShouldBeUnique` |
| `Jobs/Billing/CancelGatewayChargeJob.php` | `CancelGatewayChargeJob` | `default` | default / 6 | Billing não-webhook; troca para `billing.jobs.queue` |
| `Jobs/Billing/CancelGatewaySubscriptionJob.php` | `CancelGatewaySubscriptionJob` | `default` | default / 6 | Billing não-webhook; troca |
| `Jobs/Billing/ExpireOverdueSubscriptionsJob.php` | `ExpireOverdueSubscriptionsJob` | `default` | default / 3 | Agendado; troca |
| `Jobs/Billing/RecreateGatewayRecurrenceJob.php` | `RecreateGatewayRecurrenceJob` | `default` | default / 5 | Billing não-webhook; troca |
| `Jobs/Billing/RenewSubscriptionJob.php` | `RenewSubscriptionJob` | `default` | default / 1 | Billing não-webhook; troca |
| `Jobs/Billing/RetryFailedPaymentJob.php` | `RetryFailedPaymentJob` | `default` | default / 1 | Billing não-webhook; troca |
| `Jobs/Billing/SendBillingRefundJob.php` | `SendBillingRefundJob` | `default` | default / 8 | Billing não-webhook; troca |
| `Jobs/Fiscal/ProcessNfseWebhookJob.php` | `ProcessNfseWebhookJob` | `default` (`FISCAL_QUEUE`) | default / 5 | Webhook fiscal; não é billing |
| `Jobs/Fiscal/SendNfseJob.php` | `SendNfseJob` | `default` (`FISCAL_QUEUE`) | default / 5 | Emissão fiscal |
| `Jobs/GenerateExamDerivatives.php` | `GenerateExamDerivatives` | `default` | default / **2** (backoff 60 s) | Derivados de exame; **sem fila**; `$tries=2`/`$backoff=60` novos |
| `Jobs/NotifyScheduleChangeJob.php` | `NotifyScheduleChangeJob` | `default` | default / 3 | E-mail de agenda; **sem fila** |
| `Jobs/Surgery/SendSurgeryAuthorizationNoticeJob.php` | `SendSurgeryAuthorizationNoticeJob` | `default` | 150 / 1 | Aviso cirúrgico; `whatsapp.queue` |
| `Jobs/WhatsApp/ProcessWhatsAppStatusJob.php` | `ProcessWhatsAppStatusJob` | `default` | default / 4 | Status de entrega |
| `Jobs/WhatsApp/SendSaasWhatsAppNoticeJob.php` | `SendSaasWhatsAppNoticeJob` | `default` | 150 / 3 | Aviso SaaS |
| `Jobs/WhatsApp/SendWhatsAppMessageJob.php` | `SendWhatsAppMessageJob` | `default` | 150 / 3 | Envio WhatsApp; unique; revalida recall |

Observações:

- `GenerateTissBatchXmlJob`/`SendTissBatchJob`/`ProcessTissReturnJob` **não** ganham `onQueue` na classe; a fila continua sendo definida pelos services (`config('tiss.queues.*')`). O worker fast lê as três.
- `RunAiWorkflowJob` tem `$tries/$backoff/$timeout` atribuídos no construtor a partir de `config('ai.jobs.*')`; o teste de timeout precisa considerar `config('ai.jobs.timeout_seconds')`, não o default da propriedade.
- `ProcessMedicinePosologyBatchJob` tem `$tries=3`, mas o comando bulk usa `--tries=1`; o `$tries` do job tem precedência. O self-dispatch `self::dispatch($batch)` herda a fila `bulk` do construtor.

### 3.6 F1 — notificações e listeners `ShouldQueue`

| Arquivo | Classe | Fila | Motivo |
|---|---|---|---|
| `Listeners/Fiscal/IssueNfseOnInvoicePaid.php` | `IssueNfseOnInvoicePaid` | `default` (`viaQueue` = `FISCAL_QUEUE`) | Emissão fiscal pós-pagamento |
| `Listeners/Fiscal/CancelNfseOnInvoiceFullyReversed.php` | `CancelNfseOnInvoiceFullyReversed` | `default` (`viaQueue` = `FISCAL_QUEUE`) | Cancelamento fiscal |
| `Listeners/Surgery/CreatePostOpReturns.php` | `CreatePostOpReturns` | `default` | Retornos pós-op; fora do lock do caso |
| `Notifications/AiRunWaitingApprovalNotification.php` | `AiRunWaitingApprovalNotification` | `default` | Aviso de aprovação de IA |
| `Notifications/DoctorClinicInvitation.php` | `DoctorClinicInvitation` | `default` | Convite de médico |
| `Notifications/GatewayOperationalAlertNotification.php` | `GatewayOperationalAlertNotification` | `default` | Alerta de gateway |
| `Notifications/GatewayRecurrenceLostNotification.php` | `GatewayRecurrenceLostNotification` | `default` | Recorrência perdida |
| `Notifications/ImageReportDraftNotification.php` | `ImageReportDraftNotification` | `default` | Laudo/imagem; `ShouldQueueAfterCommit` |
| `Notifications/InvoiceChargeNotification.php` | `InvoiceChargeNotification` | `default` | Cobrança |
| `Notifications/PatientPasswordReset.php` | `PatientPasswordReset` | `default` | Reset de senha do Portal do Paciente (token `SensitiveParameter`; não alterar) |
| `Notifications/PatientPortalInvitation.php` | `PatientPortalInvitation` | `default` | Convite do portal |
| `Notifications/SubscriptionDunningNotification.php` | `SubscriptionDunningNotification` | `default` | Régua de cobrança |
| `Notifications/TrialEndingNotification.php` | `TrialEndingNotification` | `default` | Fim de trial |
| `Notifications/UserClinicInvitation.php` | `UserClinicInvitation` | `default` | Convite de usuário |
| `Notifications/WhatsAppOperationalAlertNotification.php` | `WhatsAppOperationalAlertNotification` | `default` | Alerta WhatsApp |

- `ClinicalReviewInvitationNotification` e `RequestingDoctorReportReady` **não** são `ShouldQueue` (o primeiro só cita `ShouldQueue` em comentário); não entram.
- `ScheduleNotification` **sai** desta tabela: deixa de implementar `ShouldQueue` e de usar `Queueable`.
- Nenhuma notificação tem `onQueue`/`viaQueue`; todas ficam em `default`. Não é necessário alterar as 12 restantes.

### 3.7 F1 — Supervisor (Docker)

Substituir o programa único `.docker/supervisor/easyeye-worker.conf` por **três programas de 1 processo** (a fonte de verdade é o arquivo no repositório; **ajustado em 22/10** — o desenho original de `numprocs=2` com a mesma ordem nos dois foi trocado depois das revisões):

| Programa | `--queue` (nessa ordem) | Log | Observação |
|---|---|---|---|
| `easyeye-worker-fast-critical` | `critical,default,tiss-xml,tiss-send,tiss-returns` | `easyeye-fast-critical.log` | `--timeout=190 --tries=3 --memory=256 --max-jobs=1000`, `stopwaitsecs=240` |
| `easyeye-worker-fast-tiss` | `tiss-xml,tiss-send,tiss-returns,default,critical` | `easyeye-fast-tiss.log` | mesmos flags; TISS (lote esparso) na frente, `default` logo depois |
| `easyeye-worker-bulk` | `bulk` | `easyeye-bulk.log` | `--timeout=900 --tries=1 --memory=512`, `stopwaitsecs=960`; conexão = `%(ENV_SUPERVISOR_BULK_CONNECTION)s` |

- Conexão é **posicional**: `queue:work <conexão> --queue=...`; não existe `--connection=` no Laravel 12. Os fast usam `%(ENV_SUPERVISOR_QUEUE_CONNECTION)s`; o bulk usa `%(ENV_SUPERVISOR_BULK_CONNECTION)s`, que o `.docker/php/entrypoint.sh` deriva da conexão efetiva (`redis` → `redis-bulk`, `database` → `database`, outro valor → aviso).
- Fast: `--timeout=190` > maior fast (180); `stopwaitsecs=240` cobre o maior job com folga (240 = `retry_after` do `redis`).
- Bulk: `--timeout=900` = maior bulk; `stopwaitsecs=960` cobre os 900 s (`retry_after` 960); `--tries=1` (jobs de import já têm `$tries=1`; posologia tem 3 e prevalece).
- Comentários do arquivo explicam fast/bulk e o desenho dos dois fast (remove a menção ao timeout global de 900).
- `docker-compose.yml` mantém `QUEUE_CONNECTION_DOCKER=database` por padrão (Redis é externo): o bulk segue a conexão efetiva pelo entrypoint, então a fila `bulk` tem consumidor nos dois modos.

### 3.8 F1 — SaveInCloud (cron + flock)

Em `docs/internal/infra/reverb-ambiente-teste.md`, substituir a linha única do worker por **três** (padrão decidido em 22/10, reversível pelo dono; **não reinstalar 1 fast só**: sob rajada de `critical`, `default`/TISS podem ficar sem consumo):

```cron
# Fast-critical: critical primeiro, depois default e TISS.
* * * * * cd /var/www/webroot/ROOT && /usr/bin/flock -n /tmp/easyeye-queue-fast-critical.lock /usr/bin/php artisan queue:work redis --queue=critical,default,tiss-xml,tiss-send,tiss-returns --sleep=1 --timeout=190 --tries=3 --max-time=3600 --memory=256 -q >> storage/logs/queue-worker-fast-critical.log 2>&1
# Fast-tiss: as 3 filas TISS primeiro, depois default, critical por último.
* * * * * cd /var/www/webroot/ROOT && /usr/bin/flock -n /tmp/easyeye-queue-fast-tiss.lock /usr/bin/php artisan queue:work redis --queue=tiss-xml,tiss-send,tiss-returns,default,critical --sleep=1 --timeout=190 --tries=3 --max-time=3600 --memory=256 -q >> storage/logs/queue-worker-fast-tiss.log 2>&1
# Bulk: imports/catálogos/posologia; conexão própria, um processo.
* * * * * cd /var/www/webroot/ROOT && /usr/bin/flock -n /tmp/easyeye-queue-bulk.lock /usr/bin/php artisan queue:work redis-bulk --queue=bulk --sleep=3 --timeout=900 --tries=1 --max-time=3600 --memory=512 -q >> storage/logs/queue-worker-bulk.log 2>&1
```

- Três `flock` e três logs distintos; `--queue=` explícito; sem `--connection=`.
- Atualizar a seção “Um worker só, de propósito”: agora são três processos (fast-critical, fast-tiss, bulk); `retry_after` fast = 240, bulk = 960; remover a instrução de `REDIS_QUEUE_RETRY_AFTER=960` (se existir no ambiente, remover ou deixar ≥240; nunca <190). A config continua exigindo `config:cache` e `queue:restart`.
- **Deploy:** editar o crontab (trocar a linha antiga do fast pelas três) → `php artisan config:cache` → `php artisan schedule:clear-cache` → `php artisan queue:restart`; conferir os três `flock`/processos e os logs; `queue:restart` faz os workers atuais saírem após o job corrente e o cron subir os novos. `schedule:clear-cache` deve rodar fora de uma tarefa longa (ele limpa locks).
- **Nota operacional:** o `runInBackground()` encadeia `schedule:finish`, que libera o mutex ao terminar; se o processo em background morrer antes do `finish`, o mutex fica até o TTL (10/30 min) e `php artisan schedule:clear-cache` libera na hora. As tarefas de minuto não são limitadas pelo lock de 10 min em operação normal.
- Atualizar a tabela de sintomas: worker parado agora tem três logs; importação “Aguardando” pode ser bulk, não fast.

### 3.9 F1 — `routes/console.php` (antes → depois)

Regras: `runInBackground()` só em `Schedule::command`; `Schedule::call` não suporta. Preservar `between()`/`when()` existentes. `onOneServer()` em todas as entradas; `withoutOverlapping(10)` nas frequentes (exceção `recall:send-invites` = 30). Nomes já existentes são mantidos; adicionar nome em `integrator-outbox:publish`.

| Tarefa | Hoje | Depois |
|---|---|---|
| `billing:reconcile-overdue` (08:30) | `withoutOverlapping()` | `->runInBackground()->withoutOverlapping()->onOneServer()` |
| `billing:check-refunds` (hourly) | `withoutOverlapping()` | `->runInBackground()->withoutOverlapping(10)->onOneServer()` |
| `billing:gateway-health` (06:10) | `withoutOverlapping()` | `->runInBackground()->withoutOverlapping()->onOneServer()` |
| `billing:dunning` (09:00) | `withoutOverlapping()` | `->runInBackground()->withoutOverlapping()->onOneServer()` |
| `whatsapp:send-confirmations` (hourly, 8–20) | `between()+withoutOverlapping()` | `->between(...)->runInBackground()->withoutOverlapping(10)->onOneServer()` |
| `whatsapp:send-surveys` (hourly, 9–20) | `between()+withoutOverlapping()` | `->between(...)->runInBackground()->withoutOverlapping(10)->onOneServer()` |
| `whatsapp:sweep-stuck` (10 min) | `withoutOverlapping()` | `->withoutOverlapping(10)->onOneServer()` |
| `recall:send-invites` (hourlyAt 5) | `withoutOverlapping(30)` | `->runInBackground()->withoutOverlapping(30)->onOneServer()` (exceção) |
| `recall:evaluate-dirty` (every minute) | `withoutOverlapping(10)` | `->runInBackground()->withoutOverlapping(10)->onOneServer()` |
| `recall:requeue-pending` (10 min) | `withoutOverlapping(10)` | `->withoutOverlapping(10)->onOneServer()` |
| `recall:reconcile` (02:30) | `withoutOverlapping()` | `->runInBackground()->withoutOverlapping()->onOneServer()` |
| `recall:verify-integrity` (05:15) | `withoutOverlapping()` | `->runInBackground()->withoutOverlapping()->onOneServer()` |
| `integrator-outbox:publish` (every minute) | `everyMinute()->withoutOverlapping()` | `->name('integrator-outbox:publish')->everyMinute()->runInBackground()->withoutOverlapping(10)->onOneServer()` |
| `fiscal:nfse-sync` (10 min) | `withoutOverlapping()` | `->runInBackground()->withoutOverlapping(10)->onOneServer()` |
| `fiscal:nfse-reissue` (05:10) | `withoutOverlapping()` | `->runInBackground()->withoutOverlapping()->onOneServer()` |
| `ai:expire-stale-runs` (hourly) | `withoutOverlapping()` | `->withoutOverlapping(10)->onOneServer()` |
| **novo** `queue:prune-failed --hours=720` (04:20) | — | `->dailyAt('04:20')->name('queue:prune-failed')->withoutOverlapping(10)->onOneServer()` |
| Demais tarefas existentes | `withoutOverlapping()` | acrescentar `->onOneServer()`; não mudar horário/ação |

- **RunInBackground obrigatório (7):** dunning, reconcile-overdue, confirmations, surveys, send-invites, evaluate-dirty, integrator-outbox.
- **RunInBackground recomendado (6):** check-refunds, gateway-health, recall:reconcile, recall:verify-integrity, fiscal:nfse-sync, fiscal:nfse-reissue (chamam gateway/Focus/DB pesado). Se o dono quiser o mínimo, os 7 acima são o piso.
- `Schedule::job(new ExpireOverdueSubscriptionsJob())` recebe `->onOneServer()`; não usar `runInBackground` (já é dispatch).
- `Schedule::call` (trials, plan-changes, renew, etc.) recebe só `->onOneServer()`; `runInBackground` lança em `CallbackEvent`.
- Com `runInBackground`, o mutex **é liberado no fim**: o `CommandBuilder` encadeia `schedule:finish "<mutexName>" "$?"`; o `ScheduleFinishCommand` chama `Event::finish()`, que executa `removeMutex()` no `finally`. As tarefas de minuto (`recall:evaluate-dirty`, `integrator-outbox:publish`) continuam de minuto, e `fiscal:nfse-sync` continua de 10 em 10, desde que a execução anterior tenha terminado. Os 10/30 min são só o TTL de fallback se o processo morrer antes do `schedule:finish`; se uma tarefa ultrapassar o TTL, a próxima pode sobrepor (monitorar `billing:dunning` e `send-invites`). **Nota operacional:** processo em background morto antes do `schedule:finish` deixa o mutex até expirar (10 min); `php artisan schedule:clear-cache` libera na hora.

### 3.10 F1 — notificação, derivados, broadcast

- `app/Notifications/ScheduleNotification.php`: remover `use Illuminate\Bus\Queueable`, `use Illuminate\Contracts\Queue\ShouldQueue`, `implements ShouldQueue` e `use Queueable`. Manter `via()`/`toMail()`/`toArray()`. Único caller é `NotifyScheduleChangeJob`; não há outro `notify()`.
- `app/Jobs/NotifyScheduleChangeJob.php`: adicionar no construtor `$this->onQueue('default');` (uma das 10 sem fila). `tries=3`/`backoff=60` continuam.
- `app/Jobs/GenerateExamDerivatives.php`: adicionar `public int $tries = 2;`, `public int $backoff = 60;` e, no construtor, `$this->onQueue('default');`.
- `config/broadcasting.php` (reverb): `client_options` com `timeout => 2`, `connect_timeout => 1`; não mexer em pusher/ably.
- `queue:prune-failed --hours=720` no scheduler (seção 3.9); sem comando novo.

---

## 4. F0 — passos de implementação

1. **F0-1** `app/Providers/AppServiceProvider.php`: imports e bloco da seção 3.1 no início de `boot()`.
   - Conferir que `Paginator`, `URL`, observers e bindings continuam iguais.
   - Não adicionar `DB::listen` nem logar SQL.
2. **F0-2** `.env.example`: `LOG_STACK=daily`, `LOG_LEVEL=info`, `SENTRY_TRACES_SAMPLE_RATE=0.1`; comentário de deploy.
   - Não criar `config/sentry.php`; não alterar `config/logging.php` (canal `daily` já existe).
3. **F0-3** Criar `docs/internal/runbooks/postgres-pg-stat-statements.md` conforme 3.3; nenhuma execução contra banco real.
4. **F0-4** Testes:
   - `tests/Feature/Observability/LazyLoadingViolationTest.php`: em testing, `Entity::factory()->create()` + acesso a `subscription` **não lança**; `Log::spy()` recebe `eloquent.lazy_loading_violation` com `model`/`relation`; `Exceptions::assertNothingReported()` (testing não reporta).
   - `tests/Feature/Observability/SlowQueryLogTest.php`: `Log::spy()`; `DB::connection()->allowQueryDurationHandlersToRunAgain(); DB::select('select pg_sleep(0.6)');`; recebe `db.slow_query` com `connection`, `duration_ms`, `total_duration_ms`, `route`, `command`, `job`; **não** tem chaves `sql`/`bindings`.
   - Rodar a suíte acordada e coletar `eloquent.lazy_loading_violation` do log; criar `docs/internal/planos/performance-lazy-loading-divida.md` com `model`, `relation`, origem/teste, destino. A coleta é dívida, não correção.
5. **F0-5** Pint somente nos arquivos PHP tocados; nenhum commit.

**Aceite F0:** teste de lazy loading verde sem exceção; log de query lenta com metadados e sem SQL; `.env.example` correto; runbook sem execução; dívida registrada.

---

## 5. F1 — passos de implementação

1. **F1-1 — config/queue e env**
   - `config/queue.php`: `bulk_queue`; `redis.retry_after` 240; bloco `redis-bulk` (960, `block_for=null`, mesma conexão Redis); atualizar comentários.
   - `.env.example`: `BULK_QUEUE=bulk`; comentário 240/960; `REDIS_BULK_QUEUE_RETRY_AFTER` opcional; `BILLING_WEBHOOK_QUEUE=critical`; `BILLING_JOBS_QUEUE=default`; `WHATSAPP_VERIFICATION_QUEUE=critical`; `WHATSAPP_INBOUND_QUEUE=critical`; manter `WHATSAPP_QUEUE=default`.
2. **F1-2 — configs que despacham**
   - `config/billing.php`: `webhooks.queue` default `critical`; novo `jobs.queue` default `default`.
   - `config/whatsapp.php`: `verification_queue`/`inbound_queue` default `critical`; `queue` continua `default`.
   - `config/ai.php`/`config/fiscal.php`/`config/tiss.php`: sem mudança de valor; só conferir que o worker fast lê as filas.
3. **F1-3 — atribuição explícita de filas**
   - 8 bulk: `$this->onQueue((string) config('queue.bulk_queue', 'bulk'));` nos construtores (CID10, Covenant, Doctor, Medicine, Patient, Schedule, Posology, SyncAiModelCatalog).
   - 3 críticos: `SendPhoneVerificationCodeJob` → `whatsapp.verification_queue`; `ProcessWhatsAppInboundJob` → `whatsapp.inbound_queue`; `ProcessBillingWebhookJob` → `billing.webhooks.queue` (fallback `critical`).
   - 7 billing não-webhook: trocar `billing.webhooks.queue` por `billing.jobs.queue` (CancelCharge, CancelSubscription, ExpireOverdue, RecreateRecurrence, Renew, RetryFailed, SendRefund).
   - `NotifyScheduleChangeJob` e `GenerateExamDerivatives`: fila `default` explícita; `GenerateExamDerivatives` `$tries=2`/`$backoff=60`.
   - Não adicionar `onConnection` nos jobs; TISS não muda.
4. **F1-4 — workers**
   - `.docker/supervisor/easyeye-worker.conf`: fast/bulk conforme 3.7; atualizar `DOCKER.md` (2→3 processos, filas, logs).
   - `docs/internal/infra/reverb-ambiente-teste.md`: três linhas cron/flock (fast-critical, fast-tiss, bulk) e deploy conforme 3.8.
5. **F1-5 — scheduler**
   - `routes/console.php` conforme 3.9; preservar `between`/`when`; adicionar `queue:prune-failed`.
   - Não usar `runInBackground` em `Schedule::call`; não mexer em horários.
6. **F1-6 — notificação/derivados/broadcast** conforme 3.10.
7. **F1-7 — testes** conforme seção 6; rodar na ordem e registrar.
8. **F1-8 — docs** conforme seção 7.

**Ordem de dependência:** F1-1/2 antes de F1-3; F1-4 depois dos nomes de fila; F1-5 depois de F1-3/6; testes depois de tudo; Pint no fim.

---

## 6. Testes necessários e comandos

### 6.1 Novos/ajustados

| Teste | O que prova |
|---|---|
| `tests/Feature/Observability/LazyLoadingViolationTest.php` | Não lança em testing; loga model/relation; não reporta. |
| `tests/Feature/Observability/SlowQueryLogTest.php` | `db.slow_query` com connection/duração/rota/job; sem SQL/bindings. |
| `tests/Integration/QueueWorkerSmokeTest.php` (ajustar) | Fast lê critical/default/tiss; bulk lê bulk; `retry_after` por conexão; `--timeout`/`stopwaitsecs` por programa; `numprocs`; env de fila presente no `--queue`; `RunAiWorkflowJob` timeout 180 < 240. |
| `tests/Integration/QueueRedisReservationTest.php` (ajustar) | Reserva real `redis` (fast, 180/240) e `redis-bulk` (900/960); worker real; mesma fila Redis. |
| `tests/Integration/Support/Ee09SlowMarkerJob.php` (ajustar) | Construtor aceita `int $timeout = 900` (promovido); default preservado. |
| `tests/Feature/Queue/QueuePriorityTest.php` (novo) | `Queue::fake + assertPushedOn`: OTP/inbound/webhook em `critical`; 8 bulk em `bulk`; 7 billing em `default`; `NotifyScheduleChangeJob`/`GenerateExamDerivatives` em `default`. |
| `tests/Feature/Queue/ScheduleNotificationQueueTest.php` (novo) | `ScheduleNotification` não é `ShouldQueue`; `NotifyScheduleChangeJob::handle` envia sem enfileirar segundo job (`Queue::fake`, `Mail::fake`). |
| `tests/Feature/Queue/SchedulePriorityTest.php` (novo) | `runInBackground` nos 13; `withoutOverlapping(10)` nas frequentes e 30 em send-invites; `onOneServer`; `queue:prune-failed --hours=720`; `between`/`when` preservados. |
| `tests/Unit/Queue/GenerateExamDerivativesQueueTest.php` (novo) | `$tries===1`, `$queue==='default'`. |
| `tests/Unit/Broadcasting/ReverbClientOptionsTest.php` (novo) | `client_options.timeout=2`, `connect_timeout=1`. |
| `tests/Feature/Auth/PhoneVerificationTest.php` (ajustar) | OTP usa `whatsapp.verification_queue`; payload continua cifrado (`ShouldBeEncrypted`). |

> `QueueWorkerSmokeTest` deve parsear o `.conf` por **regex de bloco** (`[program:...]`), não `parse_ini_file`: o arquivo tem `%(program_name)s` e `parse_ini_file` falha com “unexpected '('”. O teste atual já usa regex; manter esse caminho e extrair `command`, `numprocs`, `stopwaitsecs` de cada bloco.

### 6.2 Comandos (a partir da raiz)

```bash
# F0
rtk proxy php vendor/bin/pest tests/Feature/Observability

# F1 — feature/unit (sem banco pesado; QueuePriorityTest usa fake)
rtk proxy php vendor/bin/pest tests/Feature/Queue tests/Unit/Queue tests/Unit/Broadcasting \
  tests/Feature/Auth/PhoneVerificationTest.php \
  tests/Feature/Broadcasting/ImportProgressBroadcastTest.php \
  tests/Feature/Recall/IntravitrealCourseHttpTest.php

# F1 — integração real (somente easyeye_test; sem --parallel; DatabaseMigrations autorizado)
rtk proxy php vendor/bin/pest tests/Integration/QueueWorkerSmokeTest.php
rtk proxy php vendor/bin/pest tests/Integration/QueueRedisReservationTest.php

# Formatação somente nos arquivos tocados
rtk proxy php vendor/bin/pint <lista-de-arquivos-php-tocados>
rtk proxy php vendor/bin/pint --test <lista-de-arquivos-php-tocados>
```

- Antes dos testes de integração: confirmar `APP_ENV=testing`, conexão efetiva local, banco `easyeye_test`, ausência de outro escritor e isolamento de envios externos. `DatabaseMigrations` recria schema; isso é efeito autorizado do teste, não setup.
- `QueueRedisReservationTest` pula sozinho se Redis não estiver acessível; se pular, registrar como limitação, não como verde.
- Não rodar suíte inteira em paralelo; não criar banco extra; não usar `--parallel`.
- Se um teste da série de sessões (arquivos restritos) falhar, **não editar**: reportar ao orquestrador; a série está em working tree sem commit.

### 6.3 Evidências a registrar no handoff

- Saída dos testes (verde/vermelho, pulos e motivo).
- Lista de violações de lazy loading (model/relation/origem) no doc de dívida.
- `queue:monitor` pós-deploy sugerido: `php artisan queue:monitor redis:critical,redis:default,redis:tiss-xml,redis:tiss-send,redis:tiss-returns --max=1000` e `redis-bulk:bulk` (quando houver Redis no ambiente).
- Logs dos três workers (fast-critical, fast-tiss e bulk) e `supervisorctl status`/`ps`; não colar payload.

---

## 7. Documentação a atualizar

| Documento | Mudança |
|---|---|
| `docs/onboarding.md` | Seção de filas: `critical/default/tiss` no fast, `bulk` no `redis-bulk`; `composer dev` (queue:listen) só consome `default`; para OTP/inbound local, rodar `queue:listen --queue=critical,default`; para bulk, segundo worker; link do runbook. |
| `docs/internal/runbooks/filas-workers.md` (novo) | Mapa de filas, conexões/retry, supervisor/cron, comandos posicionais, deploy/rollback, health checks, falhas comuns, aviso “mesmo Redis DB/prefix”. Incluir a nota operacional: processo em background morto antes do `schedule:finish` deixa o mutex até expirar (10 min); `php artisan schedule:clear-cache` libera na hora. |
| `docs/internal/infra/reverb-ambiente-teste.md` | Três linhas de cron/flock + texto “fast-critical, fast-tiss e bulk” + retry 240/960 + deploy `config:cache`/`schedule:clear-cache`/`queue:restart` + logs. |
| `docs/internal/integracoes/whatsapp-gupshup.md` | Atualizar a linha de `WHATSAPP_QUEUE=default`/worker: OTP e inbound em `critical`; envio/status em `default`; worker fast precisa ler `critical`. |
| `DOCKER.md` | Linhas ~42, ~297, ~335, ~404: 3 programas de 1 processo (`fast-critical`, `fast-tiss`, `bulk`), filas, logs `easyeye-fast-critical.log`/`easyeye-fast-tiss.log`/`easyeye-bulk.log`. |
| `docs/internal/runbooks/postgres-pg-stat-statements.md` (novo) | F0-3. |
| `docs/internal/planos/performance-lazy-loading-divida.md` (novo) | Dívida coletada no F0-4; não bloqueia F1. |
| `docs/setup.md` | Opcional: link para o runbook de workers; não duplicar comandos. |

---

## 8. Riscos, mitigação e rollback

| Risco | Prob. | Impacto | Mitigação |
|---|---|---|---|
| Job crítico preso atrás de TISS/default no mesmo fast | Média | OTP/webhook atrasa | `critical` primeiro no `fast-critical`; 2º fast (`fast-tiss`) nos dois ambientes (O1, decidido em 22/10); teste de filas. |
| `redis-bulk` aponta para outro Redis DB/prefix | Média | Bulk invisível/parado | `connection` igual ao `redis`; teste de config; runbook e comentário no config. |
| `retry_after=240` menor que um job fast | Baixa | Reentrega duplicada | Teste `QueueWorkerSmokeTest`; `RunAi` 180, WhatsApp/TISS ≤150; worker 190; novo job >240 quebra o teste. |
| Bulk 900 > retry 960? | Baixa | Reentrega | 960 > 900; `stopwaitsecs=960`; teste de reserva real. |
| `queue:work` com `--connection=` | Baixa | Worker não sobe | Comando posicional; teste/regex do supervisor; docs. |
| Starvation por prioridade fixa | Baixa | `default`/TISS parados | 2 fast em ordens diferentes (3 processos nos dois ambientes); risco residual com `critical` e `tiss-xml` saturadas juntas (aceite do dono pendente); monitorar `redis-cli llen` e `queue:monitor`. |
| Processo background morto antes do `schedule:finish` | Baixa | Mutex preso até 10/30 min; tarefa frequente não roda | `schedule:finish` libera no fim normal; TTL 10/30 min é fallback; `php artisan schedule:clear-cache` libera na hora; runbook. |
| Tarefa em background ultrapassa o TTL do lock | Baixa/Média | Próxima execução pode sobrepor | `withoutOverlapping(10)`/30; monitorar `billing:dunning` e `send-invites`; aumentar TTL ou reduzir o escopo se necessário. |
| `onOneServer` sem cache Redis | Baixa | Sem coordenação multi-nó | Confirmar `CACHE_STORE=redis` (O8); em 1 nó é no-op. |
| `ScheduleNotification` sem `ShouldQueue` | Baixa | E-mail síncrono no job; SMTP lento | `NotifyScheduleChangeJob` tem 3 tentativas/backoff 60; único caller; teste garante zero segundo job. |
| `GenerateExamDerivatives` com 2 tentativas | Baixa | Falha transitória de S3 só após 60 s; falha determinística roda 2× | Decisão O4 (ajustada); o intent do outbox é apagado no dispatch, então a recuperação manual é o `BackfillExamDerivatives`; log de `derivative_status=failed`. |
| Billing não-webhook em `default` atrasa | Baixa/Média | Cobrança/cancelamento | Fila separada de webhook; se Lupa apontar, promover `CancelGateway*`/`SendBillingRefund` a `critical` (O5). |
| `queue:prune-failed` apaga falhas >30 dias | Baixa | Perda de diagnóstico | Default 720 h (O7); comando diário; sem rollback. |
| `preventLazyLoading` só reporta | Baixa | N+1 continua | Dívida documentada; F3/F4 corrigem; sem quebra de teste. |
| Query lenta dispara 1×/processo | Baixa | Pouca visibilidade em worker longo | Log de total; F2 pode trocar por `DB::listen`; documentado. |
| `pg_stat_statements` exige restart | Baixa | Janela do DBA | O9; não executar pelo agente; read replica. |
| Sentry 0.1 custo/volume | Baixa | Custo | O2; DSN vazio; sem conexão nesta entrega. |

**Rollback:** não há migration/dado. Reverter config/supervisor/código, rodar `config:cache` + `queue:restart`; antes de voltar o worker antigo, drenar `critical`/`bulk` com um worker temporário que leia as filas novas (o worker antigo não as consome). `ScheduleNotification`/`GenerateExamDerivatives` voltam com o código. `queue:prune-failed` não tem rollback (já apagou). `preventLazyLoading` não altera dados.

---

## 9. Arquivos afetados e arquivos proibidos

### 9.1 Afetados (previstos)

**F0**
- `app/Providers/AppServiceProvider.php`
- `.env.example`
- `docs/internal/runbooks/postgres-pg-stat-statements.md` (novo)
- `tests/Feature/Observability/LazyLoadingViolationTest.php` (novo)
- `tests/Feature/Observability/SlowQueryLogTest.php` (novo)
- `docs/internal/planos/performance-lazy-loading-divida.md` (novo, após coleta)

**F1**
- `config/queue.php`, `config/billing.php`, `config/whatsapp.php`, `config/broadcasting.php`, `.env.example`
- `app/Jobs/ProcessCid10ImportJob.php`, `ProcessCovenantImportJob.php`, `ProcessDoctorImportJob.php`, `ProcessMedicineImportJob.php`, `ProcessPatientImportJob.php`, `ProcessScheduleImportJob.php`, `ProcessMedicinePosologyBatchJob.php`, `SyncAiModelCatalogJob.php`
- `app/Jobs/WhatsApp/SendPhoneVerificationCodeJob.php`, `app/Jobs/WhatsApp/ProcessWhatsAppInboundJob.php`
- `app/Jobs/Billing/ProcessBillingWebhookJob.php` + 7 jobs billing não-webhook
- `app/Jobs/NotifyScheduleChangeJob.php`, `app/Jobs/GenerateExamDerivatives.php`
- `app/Notifications/ScheduleNotification.php`
- `.docker/supervisor/easyeye-worker.conf`, `routes/console.php`
- `tests/Integration/QueueWorkerSmokeTest.php`, `tests/Integration/QueueRedisReservationTest.php`, `tests/Integration/Support/Ee09SlowMarkerJob.php`
- `tests/Feature/Queue/QueuePriorityTest.php`, `ScheduleNotificationQueueTest.php`, `SchedulePriorityTest.php`; `tests/Unit/Queue/GenerateExamDerivativesQueueTest.php`; `tests/Unit/Broadcasting/ReverbClientOptionsTest.php`
- `tests/Feature/Auth/PhoneVerificationTest.php`
- `docs/onboarding.md`, `docs/internal/runbooks/filas-workers.md` (novo), `docs/internal/infra/reverb-ambiente-teste.md`, `docs/internal/integracoes/whatsapp-gupshup.md`, `DOCKER.md`

### 9.2 Proibidos (série não commitada + configuração de sessão)

Não editar: `app/Http/Controllers/SchedulesController.php`, `app/Services/ScheduleService.php`, `app/Services/ScheduleSeriesService.php`, `app/Services/Schedules/*`, `app/Exceptions/ScheduleSeriesChangedException.php`, `routes/web.php`, `lang/*/schedules.php`, `resources/js/Pages/Panel/Schedules/*`, `tests/Feature/Schedules/*`, `tests/Integration/ScheduleSeriesRescheduleRaceTest.php`, `tests/Integration/Support/series_reschedule_child.php`, `tests/Unit/Lang/SchedulesTranslationParityTest.php`, `tests/Feature/Surgery/PostOpReturnsTest.php`, `docs/internal/manuais/clinic/secretaria/README.md`, `docs/internal/engineering/domain-invariants.md`, `.claude-flow/*`, `.claude/settings.json`.

Se algum ajuste de F1 precisar de linha em arquivo restrito, **não editar agora**: anotar “após o commit da série” no handoff e pedir autorização ao orquestrador. Nenhum ajuste previsto exige isso.

---

## 10. Critérios de aceite / checklist

### F0

- [ ] `preventLazyLoading` ativo em testing/local/staging e desligado em produção.
- [ ] Acesso lazy em testing **não lança**; log `eloquent.lazy_loading_violation` com model/relation; sem report em testing.
- [ ] Query >500 ms loga `db.slow_query` com `connection`, `duration_ms`, `total_duration_ms`, `route`, `job`; sem `sql`/`bindings`.
- [ ] `.env.example`: `LOG_STACK=daily`, `LOG_LEVEL=info`, `SENTRY_TRACES_SAMPLE_RATE=0.1`; sem `config/sentry.php`.
- [ ] Runbook `pg_stat_statements` + baseline dos 5 hot paths (procedimento; sem execução real).
- [ ] Dívida de lazy loading registrada ou tarefa explicitamente pendente para o handoff.
- [ ] Pint --test verde nos arquivos F0.

### F1

- [ ] `redis.retry_after=240`; `redis-bulk.retry_after=960`; `block_for=null`; mesma conexão Redis.
- [ ] `BULK_QUEUE`, `BILLING_WEBHOOK_QUEUE=critical`, `BILLING_JOBS_QUEUE=default`, `WHATSAPP_VERIFICATION_QUEUE=critical`, `WHATSAPP_INBOUND_QUEUE=critical`, `WHATSAPP_QUEUE=default`.
- [ ] Todos os `ShouldQueue` mapeados; 10 sem fila agora explícitos; 8 bulk; 3 críticos.
- [ ] Supervisor fast/bulk com `numprocs`, `--timeout`, `--tries`, `--memory`, `stopwaitsecs`; sem `--connection=`.
- [ ] SaveInCloud com 2 linhas de cron/flock e `--queue=` explícito.
- [ ] `QueueWorkerSmokeTest` prova fast/bulk, retry por conexão, stopwaitsecs, env nos `--queue`, AI 180<240.
- [ ] `QueueRedisReservationTest` prova reserva real `redis`/`redis-bulk`.
- [ ] `QueuePriorityTest` prova `critical`/`bulk`/`default`.
- [ ] `ScheduleNotification` não é `ShouldQueue`; job não enfileira segundo job.
- [ ] `GenerateExamDerivatives` `$tries=2`/`$backoff=60`/`default`.
- [ ] Reverb `timeout=2`/`connect_timeout=1`.
- [ ] Scheduler com `runInBackground` (7+6), `withoutOverlapping(10)` nas frequentes (30 em send-invites), `onOneServer` e `queue:prune-failed`.
- [ ] Docs e runbook de workers atualizados; `DOCKER.md` sem “2 processos/default,high”.
- [ ] Nenhum arquivo proibido alterado; nenhum commit/push.
- [ ] Pint --test verde nos arquivos F1.

---

## 11. Pendências do dono e handoff

**Pendências (defaults já definidos; nenhuma bloqueia o código):**

1. O1: **decidido em 22/10 pelo orquestrador (reversível pelo dono): 3 processos no SaveInCloud** (`fast-critical`, `fast-tiss`, `bulk`), como no Docker. Pendente do dono: aceitar o risco residual de prioridade fixa ou adotar Horizon (fase seguinte).
2. O2/O8/O9: Sentry 0.1, `CACHE_STORE=redis`, janela/read replica para `pg_stat_statements`/EXPLAIN.
3. O4: `GenerateExamDerivatives` com 2 tentativas (`$backoff=60`); O5: billing não-webhook em `default`; O6: WhatsApp todo em `critical` ou split; O7: retenção de failed jobs.
4. **O11 — não é pendência:** comportamento verificado; o `schedule:finish` libera o mutex ao terminar e as tarefas de minuto continuam de minuto. Os 10 min são só fallback para processo morto; `schedule:clear-cache` libera na hora. Nenhuma ação do dono.
5. Confirmar no `.env` de produção (sem imprimir): `REDIS_QUEUE_RETRY_AFTER` removido/≥240, `REDIS_BULK_QUEUE_RETRY_AFTER=960`, `BILLING_JOBS_QUEUE`, `WHATSAPP_*`, `BULK_QUEUE`, `LOG_STACK/LEVEL`, `SENTRY_TRACES_SAMPLE_RATE`.
6. Deploy: `config:cache` → `schedule:clear-cache` → `queue:restart`; conferir os três workers; `queue:monitor`; logs.

**Handoff para o orquestrador:** ao terminar a implementação, a Forja deve reportar: arquivos alterados, testes/comandos e resultados, pulos/limitações, dívida de lazy loading, decisões pendentes e “sem commit”. O commit só ocorre após revisão e a frase **“commit autorizado”**.

**Não fazer:** commit, push, deploy, ler `.env`, conectar Sentry, executar EXPLAIN/pg_stat_statements contra banco real, tocar na série não commitada, rodar testes em paralelo ou criar banco extra.
