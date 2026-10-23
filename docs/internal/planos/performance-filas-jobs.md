# Desempenho sob carga: filas, jobs, requests e consultas

- Data: 22/10/2026. Origem: auditoria somente leitura (5 auditores paralelos + amostragem), nenhum EXPLAIN real, nenhum `.env` lido, nada alterado.
- Status: plano aprovado pelo dono em 22/10/2026; execução começa por F0 + F1 (fluxo plano → implementação → revisão → "commit autorizado").
- Caminhos e linhas são relativos à raiz do repo e refletem o working tree de 22/10 (SchedulesController/ScheduleService tinham alterações não commitadas da série de sessões; linhas podem deslocar).

**O que o repo faz:** SaaS multi-clínica de oftalmologia (Laravel 12 + Inertia/Vue, PostgreSQL, Redis, Reverb): agenda, prontuário, imagens/laudos, cobrança, WhatsApp, TISS, IA. Carga assumida: dezenas de clínicas, recepções e médicos com Agenda/Dashboard abertos o dia todo (polling de 30 s), clínicas grandes com 100 mil agendamentos e dezenas de milhares de pacientes; produção e teste com **1 worker de fila** (cron+flock) e PHP-FPM com pool pequeno.

## Achados (ordem de impacto)

### Must fix (quebra na carga esperada)

1. **Uma fila FIFO pra tudo; prioridade morta e invertida** (`.docker/supervisor/easyeye-worker.conf:9`, `docs/internal/infra/reverb-ambiente-teste.md:152`, `config/queue.php:49,84`, `config/whatsapp.php:91`)
   - O que é: OTP do cadastro, webhook de pagamento, WhatsApp, IA, derivados de imagem e imports de 10-15 min caem todos em `default`. Docker: 2 workers com `--queue=default,high,…` (default ANTES de high; ninguém despacha pra `high`). Teste/produção: 1 worker sem `--queue=` (filas `tiss-*` nunca consumidas lá). 10 jobs sem fila nomeada. `--sleep=3`.
   - Problema: 2 imports simultâneos = fila parada 10 min; OTP (TTL 10 min) vence atrás deles; 300 confirmações WhatsApp às 8:00 = 3-5 min de fila na frente do OTP. `retry_after` 960 s é por conexão: worker morto com webhook de pagamento reservado → reprocessa só em 16 min.
   - Correção: filas `critical` (OTP, inbound WhatsApp, webhook billing), `default`, `bulk` (6 imports, posologia, catálogos) + 2ª conexão `redis-bulk` (retry_after 960) e `redis` em 240 (> 180 do RunAiWorkflowJob). Supervisor: `fast` numprocs 2 `--queue=critical,default,tiss-xml,tiss-send,tiss-returns --sleep=1 --timeout=190 --memory=256`; `bulk` numprocs 1 `--queue=bulk --timeout=900 --tries=1 --memory=512`. Cron do teste com `--queue=` explícito (2 linhas flock). Chaves já existem (AI_QUEUE, WHATSAPP_QUEUE, BILLING_WEBHOOK_QUEUE, FISCAL_QUEUE); faltam `WHATSAPP_VERIFICATION_QUEUE` e `BULK_QUEUE`.
   - Se pular: usuário não conclui cadastro enquanto alguém importa; pagamento aprovado "some" por minutos.

2. **Agendador roda ~45 tarefas em série no mesmo processo** (`routes/console.php`, `app/Services/Billing/DunningService.php:87-101`, `app/Services/Billing/GatewayPaymentReconciler.php:222`)
   - O que é: nenhuma tarefa com `runInBackground()`. Régua e conciliação chamam o gateway por assinatura/fatura (`usleep(300ms)` por fatura). `integrator-outbox:publish` (a cada minuto) está definido por último. `withoutOverlapping()` padrão de 24 h nas tarefas de minuto/hora; sem `onOneServer()`.
   - Problema: às 09:00 a régua leva minutos → recall e publicação do integrador atrasam atrás dela; gateway lento trava o minuto inteiro. Um kill no meio trava a tarefa frequente por 1 dia (sem `schedule:clear-cache` no deploy).
   - Correção: `->runInBackground()` nos pesados (dunning, reconcile, confirmations/surveys, send-invites, evaluate-dirty, outbox:publish); `withoutOverlapping(10)` nas frequentes; `onOneServer()` se houver 2 nós.
   - Se pular: derivados e retornos atrasam minutos todo dia útil.

3. **Import faz `COUNT(*)` e `fresh()` por linha** (`app/Services/PatientImportService.php:429,448`, `app/Services/DoctorImportService.php:375`, `app/Services/ScheduleImportService.php:499-513`)
   - O que é: por linha do CSV: `$import->fresh()`, `Patient::where(entity)->count()` (limite do plano), resolução de médico/paciente com 2-3 SELECTs, transação própria. Médico novo recebe `Password::sendResetLink` síncrono (SMTP) dentro do job.
   - Problema: 20 mil linhas em clínica de 50 mil pacientes ≈ 20 mil COUNT + 20 mil fresh + ~100 mil queries; worker preso até os 600 s. 150 médicos = 2-7 min de SMTP no worker.
   - Correção: contar uma vez antes do laço e somar `$imported`; checar cancelamento só no tick de 25 (já existe, l.511); mapas import_code/CRM→id carregados uma vez (padrão que convênios/visitas já usam, l.786-811); reset de senha como notificação `ShouldQueue` (padrão `PatientPasswordReset`).
   - Se pular: imports grandes estouram timeout e ocupam a fila `bulk` o dia todo.

4. **`whereDate` em `schedules` anula o índice** (`app/Http/Controllers/PanelDashboardController.php:666`, `app/Services/ScheduleService.php:532`, `app/Http/Controllers/Api/SchedulesController.php:57`, `app/Http/Controllers/Api/OfflineSnapshotController.php:33`)
   - O que é: no PostgreSQL vira `date_time::date = ?`. Cast na coluna → `schedules_entity_date_time_idx (entity_id, date_time)` só serve pelo `entity_id`. `doctor_id` não tem índice nenhum.
   - Problema: Dashboard polla `scheduleToday` a cada 30 s em toda tela aberta: clínica com 100 mil agendamentos varre 100 mil linhas por poll por recepção; grade de horários repete a cada troca de data; worklist do integrador idem.
   - Correção: `whereBetween('date_time', [$d->startOfDay(), $d->endOfDay()])` nos 8 pontos + `CREATE INDEX CONCURRENTLY schedules_entity_doctor_date_time_idx ON schedules (entity_id, doctor_id, date_time) WHERE deleted_at IS NULL`.
   - Se pular: é o custo recorrente mais alto do sistema e cresce com o histórico.

5. **FKs sem índice (PostgreSQL não indexa `constrained()`)** (`database/migrations/2026_03_22_143412_create_schedule_situation_logs_table.php`, `database/migrations/2026_04_02_000007_create_medical_record_files_table.php`, `database/migrations/2025_12_14_100733_create_patients_table.php:16`, `app/Http/Controllers/ReportsController.php:388-405`)
   - O que é: `foreignUuid()->constrained()` cria só a constraint. Sem índice: `schedule_situation_logs.schedule_id`, `medical_record_files.medical_record_id/patient_id`, `patients.person_id`, `schedule_resources.resource_id`, `medical_records.doctor_id` e `(entity_id, created_at)`, `people.national_registry`. `schedules.patient_id` só tem a parcial que EXCLUI situation 7 (Atendido).
   - Problema: cada mudança/exclusão de agendamento varre `schedule_situation_logs`; login do Portal do Paciente varre `patients`; relatório "pacientes novos" é NOT EXISTS correlacionado O(n×m); dedupe por CPF no import varre `people` por linha.
   - Correção: uma migration com `DB::statement('CREATE INDEX CONCURRENTLY …')` fora de transação (padrão de `database/migrations/2026_10_22_000300_add_recall_support_indexes.php`) para: `schedule_situation_logs (schedule_id, created_at)`, `medical_record_files (medical_record_id)` e `(patient_id)`, `patients (person_id)`, `schedule_resources (resource_id, schedule_id)`, `people (national_registry) WHERE national_registry IS NOT NULL`, `schedules (entity_id, patient_id, date_time) WHERE deleted_at IS NULL`, `medical_records (entity_id, created_at DESC) WHERE deleted_at IS NULL` e `(entity_id, doctor_id, created_at DESC) WHERE signed_at IS NULL AND deleted_at IS NULL`.
   - Se pular: lentidão aparece "do nada" nas clínicas maiores.

6. **Upload pro S3 dentro da transação com lock de paciente/dia** (`app/Services/ExternalExamImportService.php:60-184`)
   - O que é: até 10 arquivos (10-20 MB) sobem pro S3 dentro de `DB::transaction`, depois de `Schedule::lockPatientDay`.
   - Problema: locks de linha presos durante I/O de rede; `changeSituation`/`lockForTransition` do mesmo paciente/dia esperam. A própria base documenta "HTTP fora de transação" (`app/Services/Billing/BillingCancellationService.php:303`).
   - Correção: subir arquivos antes da transação (mantendo o delete de rollback, l.169-171); só INSERTs dentro.
   - Se pular: recepção trava ao mudar situação enquanto a câmera envia exame.

7. **Até 50 PDFs lidos e processados em série na request** (`app/Http/Controllers/EyeImageReportController.php:141-150`, `app/Services/EyeImages/PdfTextExtractionService.php:38-87`)
   - O que é: `exam_ids max:50`; por exame lê PDF do S3 (≤10 MiB) e sobe processo `pdftotext` (teto 10 s), sem throttle nem semáforo.
   - Problema: pior caso 500 s > `max_execution_time` 300; típico 10-40 s segurando 1 dos ~5 slots do FPM.
   - Correção: `max:5` + `throttle:eye-images-vision`; mínimo `Cache::lock` de 2 slots como o `BoundedVisionProcess`.
   - Se pular: 3 médicos extraindo texto = painel lento pra clínica toda.

### Should fix

8. **Ziggy enviado 2× e sem filtro** (`app/Http/Middleware/HandleInertiaRequests.php:69-73`, `resources/views/panel-app.blade.php:64`, `resources/js/panel.js:25`, `resources/js/ziggy.js`)
   - O que é: 948 rotas / 143 KB (13 KB gzip), gerados por reflection e enviados como prop em TODA resposta Inertia, além do `@routes` no Blade (mais 143 KB no full load). O cliente só usa o global do `@routes`; a prop serve só ao SSR (desligado em produção) e ao `.location` do PortalLayout. Sem `config/ziggy.php`. `resources/js/ziggy.js` (103 KB, `localhost:8085` fixo) não é importado por ninguém. Mais ~22 KB de traduções (`t_hardening`, `t_ui`, `t_clinical`, `tour.t`) por resposta.
   - Problema: ~165 KB e ms de CPU por clique no menu antes de qualquer dado da tela.
   - Correção: `shareOnce()` (Inertia 3.5.1 + @inertiajs/vue3 3.1.1 já suportam) para `ziggy`, `t_*`, `locales`, `support`, `tour.t`; remover `@routes` e usar `.use(ZiggyVue, page.props.ziggy)` como o ssr.js já faz; `config/ziggy.php` com `except => ['api.*','integrators.*','patient-portal.*','clinical-review.*']`; apagar ziggy.js.
   - Se pular: cada navegação paga 165 KB à toa e expõe rotas internas ao portal do paciente.

9. **`entities` lida 5× por request; 13 a 26 queries fixas antes do controller** (`app/Http/Middleware/SetLocale.php:80`, `app/Http/Middleware/EnsureTwoFactor.php:80`, `app/Http/Middleware/CheckSubscription.php:68`, `app/Http/Middleware/EnsureEntityRole.php:80`, `app/Http/Middleware/HandleInertiaRequests.php:179-197`, `app/Domains/AI/Services/AiAssistantWidgetPropsBuilder.php:49-71`, `app/Support/CurrentDoctor.php:33-85`)
   - O que é: admin paga ~13 queries fixas por página (5 `SELECT * FROM entities WHERE id=?` idênticas, cadeia de assinatura, vínculos, preferências). Médico paga ~26: o widget de IA lê a carteira 4× e refaz a cadeia de assinatura 2-3× fora do memo. `CurrentDoctor::doctor()/issuer()` sem memo: 4-6× por tela. Manager: `isOwnerOfEntity` 2× + INSERT síncrono em `audit_logs` em todo GET (`app/Http/Middleware/LogAdminAccess.php:86-106`).
   - Problema: metade das queries de uma página são repetidas.
   - Correção: resolvedor único por request (`CurrentEntity` singleton, padrão de EnsureTwoFactor.php:77-82) → 5→1; widget de IA recebe `currentAccessFor($request)` e uma `AiCreditWallet`; `once()` em CurrentDoctor; LogAdminAccess no `terminate()`. Depois: Redis `entity:{id}` / `user:{id}:entities` com `Cache::forget` em Entity/EntityUser saved.
   - Se pular: +40-80 ms de latência base por página e carga que mascara os ganhos de índice.

10. **Dashboard recalcula `stats` sem cache no polling de 30 s** (`app/Http/Controllers/PanelDashboardController.php:452-575`, `resources/js/Pages/Panel/Dashboard.vue:112`)
    - O que é: a cada 30 s por tela: `Patient::count`, médicos, fila PA, `exams_pending` (2 NOT EXISTS correlacionados sobre 30 dias) e `recallStats`.
    - Problema: 50 clínicas × 3 painéis = ~300 execuções/min de 6 agregações.
    - Correção: `Cache::remember("dashboard.{$entityId}.stats-slow", 120)` para total_patients/total_doctors/exams_pending/recall; só `dayCounters`/fila PA a 30 s.
    - Se pular: Postgres sofre mais com dashboards abertos do que com trabalho real.

11. **Agenda hidrata 6 relações pra mostrar só o nome do médico** (`app/Services/Schedules/AgendaQueryService.php:66-119`, `app/Http/Controllers/SchedulesController.php:1258-1273`)
    - O que é: `schedules()` sempre faz `with(doctor.entityUser.user, covenant, patient.person, patient.covenantPlan, visitType, resources)` + joins em patients/people (mesmo sem busca) + `->get()` sem limite; semana/mês usam `toCompactRow`. `counters()` conta via `->get()->count()` com eager loads e `events()` roda 2× por render. `Entity::find` 4× no `index`; props não-lazy reavaliadas em todo `router.reload({only})`.
    - Problema: mês de clínica grande (~8 mil agendamentos) = 10 queries `IN (8000 ids)` + ~24 mil models hidratados e descartados; cada clique de situação repete a dupla consulta.
    - Correção: `with(['doctor.entityUser.user'])` fixo e o resto `when(range === 'day')`; joins só `when(search)`; `->count()` nos builders; `$entity` uma vez; props em `fn () =>`.
    - Se pular: visão de mês em segundos nas clínicas grandes.

12. **Validação de série/lote: 12-16 queries por data** (`app/Services/ScheduleService.php:46-121,415-436,608-676`, `app/Services/ScheduleSeriesService.php:149,177`, `app/Http/Controllers/SchedulesController.php:743-844`)
    - O que é: `validateFor` refaz por data double-booking, escala, intervalo (+`Doctor::find` + lazy), bloqueio, janela cirúrgica (+`Doctor::find`), recursos (`ClinicResource::find` + 3 exists por sala). `getResourceAvailability` = 4 queries por sala a cada tecla. Lote de 500 ids: transação + `Auditable` + situation_log por id e 500 `NotifyScheduleChangeJob::dispatch`.
    - Problema: prévia de série de 60 sessões ≈ 900 queries interativas; lote de 500 ≈ 12 mil queries + 500 INSERTs em `jobs`.
    - Correção: carregar uma vez por médico (escala, bloqueios, janelas do período) e validar em memória, padrão já existente em `ScheduleAvailabilityService::compute` (l.96-140); memo de `Doctor::find`/`ClinicResource::find`; recursos em 3 `whereIn`; 1 job com a lista de ids (ou `max:100`).
    - Se pular: tela de série congela e o lote pode estourar a request.

13. **Busca de pacientes sem índice possível** (`app/Http/Controllers/PatientsController.php:54-91,331-349`, `app/Support/Database/AccentInsensitiveSearch.php:34`)
    - O que é: `unaccent(col) ILIKE unaccent('%x%')` em 4-5 colunas; `people` só tem a PK; `unaccent()` é STABLE, então nenhum índice de expressão existe. Lista = `paginate` (count + página) + `totalPatients` = 3 varreduras; `search()` por tecla com 8 ORs + ORDER BY.
    - Problema: 40 mil pacientes → cada tecla varre `people` inteira em 4 colunas.
    - Correção: `CREATE FUNCTION f_unaccent(text) RETURNS text LANGUAGE sql IMMUTABLE PARALLEL SAFE STRICT AS $$ SELECT public.unaccent('public.unaccent', $1) $$;` + `CREATE INDEX CONCURRENTLY people_full_name_trgm_idx ON people USING gin (f_unaccent(full_name) gin_trgm_ops)` (idem cellphone, `patients.code`, `schedules.full_name`) e trocar `unaccent(` por `f_unaccent(` na macro (senão o índice nunca é usado); `totalPatients` → `$patients->total()`; separar o OR de `patients.code` (impede BitmapOr entre tabelas). pg_trgm já está habilitado (medicines).
    - Se pular: busca por tecla vira o gargalo das clínicas grandes.

14. **Laudo assinado re-renderizado a cada abertura; payload de MB no audit** (`app/Services/MedicalRecordPdfService.php:254-278`, `app/Services/EyeImages/ReportImageBlockBuilder.php:280-376`, `app/Http/Controllers/EyeImageReportController.php:303-351`, `app/Http/Controllers/ComplianceController.php:60-66`)
    - O que é: PDF gerado do zero a cada clique: imagens reinjetadas inline (até 20 MiB brutos cada → ~54 MiB de base64 no HTML); laudos antigos fazem até 50 `exists()+size()+get()` no S3 + `imagecreatefromstring` (40 MP). Na emissão: até 50 downloads + decode completo só pra validar truncamento; ~27-54 MiB gravados em JSON e DE NOVO em `audit_logs.new_values` (o código já documenta). Link público só com throttle 10/min/IP.
    - Problema: 3-30 s e centenas de MB por clique num documento imutável; `audit_logs` cresce MB por laudo.
    - Correção: renderizar uma vez (job após assinatura) → S3 chaveado por `documentation.id + signed_at`, servir arquivo; síncrono só pra prontuário não assinado; pular decode quando o candidato é `thumb/display_archive` (já validados em `GenerateExamDerivatives`); bytes em objeto S3, não em JSON; `Auditable` sem payload binário.
    - Se pular: cada reimpressão custa um slot FPM por até 30 s.

15. **wkhtmltopdf/Imagick/ditado sem limite de concorrência num pool FPM de ~5** (`app/Services/MedicalRecordPdfService.php:57`, `app/Http/Controllers/SchedulePrintController.php:86`, `app/Services/EyeImages/MontageService.php:54-88`, `app/Domains/AI/Services/SpeechTranscriptionService.php:147-158`, `Dockerfile`)
    - O que é: 11 pontos `SnappyPdf::loadView()->inline()` sem cache nem semáforo; montagem baixa até 50 `display_archive` (2560 px) pra gerar tiles de 300 px; ditado espera o provedor até 60 s. Imagem Docker sem `www.conf` → `pm.max_children=5`; nginx e PHP aceitam requests de 300 s.
    - Problema: 5 impressões (ou 5 ditados) simultâneas = 100% do pool → sistema parado pra todos.
    - Correção: wrapper `BoundedPdfRender` com `Cache::lock` de 2 slots (padrão `BoundedVisionProcess`) pra wkhtmltopdf/Imagick/pdftotext; montage com `thumb_archive` e cap 12; ditado 20 s; dimensionar `pm.max_children` (conferir no SaveInCloud).
    - Se pular: "super lentidão" generalizada sempre que alguém imprime em lote.

16. **E-mail da agenda gera 2 jobs e serializa dado de paciente** (`app/Jobs/NotifyScheduleChangeJob.php:33-50`, `app/Notifications/ScheduleNotification.php:11-29`)
    - O que é: job carrega Schedule com relações e chama `->notify()` de notification `ShouldQueue` sem `SerializesModels` → 2º job com Schedule + paciente inteiros no payload do Redis; `tries/backoff` do 1º não cobrem falha de SMTP.
    - Problema: remarcar série de 30 = 60 jobs e 30 payloads com nome/e-mail/telefone na fila.
    - Correção: tirar `ShouldQueue` da notification (já roda em job) ou `notifyNow`.
    - Se pular: fila 2× mais cheia e PHI em texto no Redis.

17. **Produção cega: sem tracing, sem alerta de N+1 nem de query lenta** (`app/Providers/AppServiceProvider.php`, sem `config/sentry.php`, `.env.example` `LOG_LEVEL=debug`)
    - O que é: nenhum `Model::preventLazyLoading`, `DB::whenQueryingForLongerThan`, `DB::listen`; Sentry só captura erro (traces/profiles null); log `debug` em arquivo único; `logger()->info` por request pra usuário sem telefone verificado (`app/Http/Middleware/EnsurePhoneVerified.php:53`).
    - Problema: nada desta lista aparece em produção até o usuário reclamar; impossível medir ganho.
    - Correção: no `boot()`: `Model::preventLazyLoading(! app()->isProduction())` + `handleLazyLoadingViolationUsing(fn ($m, $r) => report(...))`; `DB::whenQueryingForLongerThan(500, fn ($c, $e) => Log::warning(...))`; env `SENTRY_TRACES_SAMPLE_RATE=0.1`, `LOG_LEVEL=info`, `LOG_STACK=daily`; `pg_stat_statements` no Postgres.
    - Se pular: próxima regressão volta a ser "está lento" sem pista.

18. **Worker sem `--memory`, job sem `$tries`, broadcast com timeout de 30 s** (`.docker/supervisor/easyeye-worker.conf:9`, `app/Jobs/GenerateExamDerivatives.php:35-56`, `config/broadcasting.php:42-44`, `app/Services/Medicines/MedicinePosologyBatchService.php:325-327`)
    - O que é: worker com `--memory` padrão 128 MB enquanto sync ANS (66 mil planos) e CMED carregam mapas; `GenerateExamDerivatives` herda `--tries=3` e falha determinística roda 3× (3 leituras S3 de 10 MB); `ImportProgressUpdated` é `ShouldBroadcastNow` com `client_options` vazio (Guzzle 30 s); posologia dorme 500 ms entre grupos e 5-15 s nos retries dentro do worker.
    - Problema: worker reinicia após cada sync; 50 PNGs corrompidos = 150 execuções; Reverb pendurado faz cada update esperar 30 s e o import cair no timeout "sem motivo"; 200 grupos = 100 s de sono ocupando 1 dos 2 workers.
    - Correção: `--memory=256` (fast) / `512` (bulk); `public int $tries = 1` + fila; `'client_options' => ['timeout' => 2, 'connect_timeout' => 1]`; retries via `dispatch()->delay()`.
    - Se pular: workers "somem" e imports falham de forma intermitente.

19. **Tabelas sem teto e sweeps sem índice** (`database/migrations/2026_03_22_200001_create_audit_logs_table.php`, `database/migrations/2026_03_30_200003_create_data_access_logs_table.php`, `app/Console/Commands/SweepStuckWhatsAppMessagesCommand.php:35-38`)
    - O que é: `audit_logs` (107 models `Auditable` + 1 INSERT por request do manager + payload de laudo), `data_access_logs`, `schedule_situation_logs`, `whatsapp_messages` crescem sem retenção (CFM/LGPD exigem guardar); sweeps do WhatsApp a cada 10 min são cross-tenant e o único índice começa por `entity_id`; `failed_jobs` sem `queue:prune-failed`; `cache`/`queue` caem em `database` se o env faltar (default do config).
    - Problema: índices e autovacuum caros; sweeps viram seq scan a cada 10 min.
    - Correção: `CREATE INDEX CONCURRENTLY audit_logs_created_brin ON audit_logs USING brin (created_at)` (idem `data_access_logs (accessed_at)`); particionar por mês depois (`PARTITION BY RANGE`, PK vira `(id, created_at)`; nenhuma FK aponta pra elas); `whatsapp_messages (status, kind, updated_at) WHERE direction='out' AND status IN ('pending','sending')`; agendar `queue:prune-failed --hours=720`; fixar `QUEUE_CONNECTION`/`CACHE_STORE=redis` no deploy.
    - Se pular: em 12-18 meses a auditoria passa o banco clínico.

### Nice to have

20. **SSR ligado por padrão no painel autenticado** (`config/inertia.php:22`, `resources/views/app.blade.php:20` diz "desligado em produção")
    - O que é: com `INERTIA_SSR_ENABLED=true` cada full load do painel (noindex, zero SEO) POSTa ≥125 KB pro node e espera; sem `ssr.timeout` valem 10 s connect / 30 s total; falha silenciosa (`SsrRenderFailed` sem listener).
    - Problema: node travado → F5 leva 10-30 s sem log. Config e Blade se contradizem.
    - Correção: `protected $withoutSsr = ['panel', 'panel/*', 'portal/*', 'security/*', 'patient-portal/*', 'clinical-review/*']`, `'timeout' => 2`, listener → log/Sentry.
    - Se pular: regressão silenciosa quando alguém ligar o SSR "pra ver".

Ficaram de fora ~30 achados menores (exports financeiros/Partners em memória, preview do CSV lendo o arquivo inteiro, `connectTimeout` do Pagar.me, envio TISS síncrono de 40 s, `after_commit=true` como proteção sistêmica, cache de `isOwner`, `generatePreview`).

## Plano em fases

Cada fase fecha sozinha, com teste e medição. Esforço de 1 dev.

| Fase | Dias | Entrega | Fecha |
|---|---|---|---|
| F0 Enxergar | 1 | `preventLazyLoading` + `whenQueryingForLongerThan` + Sentry traces 0.1 + `LOG_LEVEL=info` + `pg_stat_statements`; EXPLAIN dos 5 hot paths como baseline | #17 |
| F1 Filas com prioridade | 2-3 | `critical/default/bulk` + conexão `redis-bulk`; 2 programas Supervisor; cron do teste com `--queue=`; `runInBackground`/`withoutOverlapping(10)`/`onOneServer`; `--memory`; `$tries=1` nos derivados; `ScheduleNotification` sem `ShouldQueue`; broadcast timeout 2 s; `queue:prune-failed` | #1 #2 #16 #18 |
| F2 Request fixo | 2 | `shareOnce` (ziggy, t_*, tour.t) + sem `@routes` + `config/ziggy.php` + apagar ziggy.js; `CurrentEntity` por request; widget IA reusa memo; `once()` no CurrentDoctor; LogAdminAccess em `terminate()`; `$withoutSsr` + timeout | #8 #9 #20 |
| F3 Consultas e índices | 3-4 | migration `CONCURRENTLY` (#5 + #4 + #13 + #19 BRIN/WhatsApp); `whereDate`→`whereBetween`; `f_unaccent` + macro; agenda eager por range + counters; dashboard stats 120 s; série/lote em memória; recursos em batch | #4 #5 #10 #11 #12 #13 |
| F4 Segundo plano | 3-5 | PDF do laudo assinado em job → S3 (reusar `BroadcastsImportProgress`/`useImportProgress` + Reverb pro "pronto"); `BoundedPdfRender` 2 slots; `extractPdfText` cap 5 + throttle; montage por thumb; imports (COUNT uma vez, mapas, reset enfileirado, S3 fora da transação); exports longos em job; lote de 500 → 1 job | #3 #6 #7 #14 #15 |
| F5 Crescimento | contínuo | partição mensal de audit/data_access; `Auditable` sem binário; retenção documentada | #19 |

Ordem recomendada: F0 → F1 → F3 (índices são baratos e medíveis) → F2 → F4 → F5. Horizon é opcional depois da F1 (dashboard e métricas; exige só o Redis que já existe); não é pré-requisito. Ao criar `redis-bulk`, ajustar `QueueWorkerSmokeTest` (trava `retry_after` > maior `$timeout` por conexão). Ao trocar a macro pra `f_unaccent`, criar índices e macro no mesmo deploy.

## Veredito e limites

**Veredito:** não aguenta a carga esperada hoje. Causa dominante: fila única sem prioridade com 1-2 workers + custo fixo alto por request + `whereDate`/FKs sem índice. Fazer F0 e F1 primeiro: 3-4 dias e resolve OTP/pagamento/imports; F3 em seguida dá o maior ganho de banco com risco mínimo.

**Lean:** -1 arquivo morto (resources/js/ziggy.js, 103 KB), -1 job duplicado por e-mail; 0 dependências novas necessárias.

**Não verificado:** EXPLAIN/estatísticas reais (não conectou ao banco; severidades inferidas do SQL gerado + migrations); `.env` de produção (drivers efetivos, SSR, Sentry, `MAIL_MAILER`); pool FPM e crontab reais do SaveInCloud; bundle do frontend (vendor.js 596 KB, fontes Tabler 1,8 MB, fora do escopo); internals de TISS e `AiRunExecutionService`; `MedicalRecordsController` l.420-744; testes não rodados. Os ganhos estimados assumem que não existem índices criados à mão fora das migrations em produção.
