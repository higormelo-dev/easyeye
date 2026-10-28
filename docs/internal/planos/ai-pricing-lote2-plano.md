# Plano — Lote 2 da precificação de IA: câmbio automático (Frankfurter) e markup por ação

**Data:** 2026-10-23 · **Autor:** Bússola (planejador) · **Status:** proposta; nenhum código alterado
**Base:** lote 1 de precificação em R$ já está no working tree, **sem commit**. Este plano é aditivo e **não refaz** o lote 1.
**Referências:** `AGENTS.md`, `docs/internal/engineering/domain-invariants.md` (Financeiro/Billing), `docs/internal/planos/ai-credit-packs-brl-plano.md`.

## 0. Escopo

1. Buscar a cotação USD→BRL uma vez por dia útil na **Frankfurter** (`https://api.frankfurter.dev/v1`), gravar em `ai_exchange_rates` com `source=frankfurter` e `effective_at` da taxa retornada; as demais leituras do dia usam o banco.
2. Falhar de forma segura: manter a última cotação guardada, registrar aviso e não derrubar a precificação.
3. Agendar a busca diária em `routes/console.php` (job na fila `default`, `runInBackground`) e permitir busca sob demanda na primeira precificação do dia.
4. Adicionar **markup por ação** (`exam_assistant`, `report_drafting`, `consensus_review`, `eye_image_analysis`, `assistant_chat`, `record_assist`, `medicine_posology` e demais workflows canônicos), com global como padrão, faixa `1–20`, motivo e auditoria atômica.
5. Congelar na run o **markup efetivo da ação** e mostrar no manager a conta por ação, a fonte e a idade da cotação, além do botão **“buscar agora”** auditado.

**Fora de escopo:** PTAX/BCB, saldo negativo, mudança da reserva/cobrança (mantém reservar antes e cobrar até a reserva), refazer o lote 1, alterar gateways/webhooks de billing, nova dependência PHP/JS, deploy/commit.

## 1. Decisões do dono (fechar antes da implementação)

| # | Decisão | Recomendação | Impacto se mudar |
|---|---|---|---|
| D1 | Markups iniciais por ação | **Todos herdam o global `5x`**; mapa de overrides nasce vazio | Se quiser valores explícitos por ação, preencher o mapa inicial; a UI e os testes mudam |
| D2 | Horário do job diário | **06:05 BRT** (antes da clínica; usa a última taxa publicada, normalmente D-1) | Após a publicação do BCE (~11h/12h BRT), usar 12:05 para pegar a taxa do mesmo dia |
| D3 | Manual de dias anteriores | **Perde para a automática do dia** (proposta): manual de hoje > automática de hoje > manual antiga > automática antiga > `legacy_topup` > fallback | Se “manual sempre vence”, a ordem 3 sobe para 1 e o teste de precedência muda |
| D4 | “Buscar agora” no mesmo dia | **Idempotente**: se já há automática de hoje, devolve a existente e orienta correção por cotação manual | Se “forçar nova busca”, cria nova linha no mesmo dia e o histórico passa a ter mais de uma automática/dia |
| D5 | Ações internas (`medicine_posology`, `platform_finance_*`) | **Configuráveis, mas não cobradas nem com snapshot** nesta entrega (comportamento atual) | Se o dono quiser cobrar/snapshot interno, é um lote separado |
| D6 | Drawer de uso da IA | **Só ajustar se o lote 1 já estiver em R$**; caso contrário, fica como pendência do lote 1 | Evita misturar a UI de créditos com a de reais |
| D7 | Faixas de câmbio | **Manual `1–20`; automática `0,5–100`** (faixas separadas) | Unificar reduz a proteção contra erro de digitação manual |

**Referência orcafascio:** `10x` em PDF/composições e `200x` na busca inline **não são copiados automaticamente**. O EasyEye parte do global `5x`; os overrides por ação serão decididos pelo dono (D1). O saldo negativo do orcafascio também **não** é copiado: mantém-se reserva antes e cobrança até a reserva.

## 2. Contratos de câmbio

### 2.1 Precedência de `AiExchangeRateService::current()`

`current()` é **somente leitura** (nunca faz HTTP). A ordem proposta:

| Ordem | Fonte | Condição | Observação |
|---|---|---|---|
| 1 | `manual` | `effective_at` entre o início do dia local e `now()` | Manual do dia vence a automática do mesmo dia |
| 2 | `frankfurter` | `created_at` dentro do dia local (linha buscada hoje) | A taxa pode ter `effective_at` de D-1 (fim de semana/feriado) |
| 3 | `manual` | `effective_at < início do dia local` e `<= now()` | Manual antiga perde para a automática de hoje (D3) |
| 4 | `frankfurter` | `created_at < início do dia local` | Última automática guardada |
| 5 | `legacy_topup` | última recarga com câmbio | Compatibilidade com o lote 1 |
| 6 | `fallback` | nenhuma linha/recarga | `5,50`, sinalizado como contingência |

Dentro de cada grupo: `ORDER BY effective_at DESC, created_at DESC`. Usar o fuso da aplicação (`America/Sao_Paulo`) de ponta a ponta; **não usar `whereDate` cru** (risco de virada UTC). Preferir `whereBetween`/comparações com `CarbonImmutable` no fuso local.

`current()` passa a devolver também:

```php
array{
  rate: float, source: string, effective_at: ?CarbonInterface,
  recorded_by: ?string, recorded_by_name: ?string, note: ?string,
  exchange_rate_id: ?string, is_fallback: bool,
  fetched_at: ?CarbonInterface,      // created_at da linha (ou topped_up_at no topup)
  fetched_today: bool,               // existe automática criada hoje
  is_automatic: bool,                // source === frankfurter
  is_manual_override: bool,          // manual de hoje + automática de hoje
  age_days: ?int                     // dias entre a data efetiva e hoje
}
```

`fetched_today` e `is_manual_override` saem da mesma consulta da automática de hoje; não duplicar query.

### 2.2 Busca automática

**Endpoint:** `GET {base_url}/latest?base=USD&symbols=BRL` com `Accept: application/json`, `connectTimeout=3`, `timeout=5`, sem retry automático e sem chave de API.

**Resposta esperada:** `{ "base": "USD", "date": "YYYY-MM-DD", "rates": { "BRL": 5.4321 } }`.

Validações antes de gravar:
- HTTP 2xx; `base === 'USD'`; `date` válido em `Y-m-d`; `date` não futura.
- `rates.BRL` numérico, finito, `0.5 <= taxa <= 100.0`.
- Em qualquer falha: `AiExchangeRateFetchException` com código seguro (`http_<status>`, `invalid_payload`, `out_of_range`, `timeout`); **nunca** logar corpo da resposta.

**Linha automática:** `source=frankfurter`, `rate_brl_per_usd` com 6 casas, `effective_at = date 00:00 no fuso da aplicação`, `recorded_by=null`, `note='Frankfurter/BCE'`. `created_at` identifica a busca do dia.

**Métodos públicos previstos:**

```php
public function ensureToday(): array;   // usa failure cache; chamado pela precificação
public function fetchNow(?callable $audit = null): array; // job e botão; ignora failure cache
```

`ensureToday()`:
1. se já existe automática criada hoje, retorna `already_fetched`;
2. se `Cache::has("ai:exchange-rate:failed:{Y-m-d}")`, retorna `failed_cached` (TTL 15 min);
3. senão chama `fetchNow()`; qualquer `Throwable` inesperado é logado e vira `failed` (a precificação não quebra).

`fetchNow()`:
1. checa a automática de hoje; se existir, retorna `already_fetched` (idempotente por dia);
2. adquire `Cache::lock("ai:exchange-rate:fetch:{Y-m-d}", 10)`; se não adquirir, retorna `busy` (sem HTTP);
3. recheca dentro do lock;
4. faz o HTTP + validação;
5. em `DB::transaction`: recheca, cria a linha e chama o callback de auditoria (se houver);
6. retorna `created` com a linha; em `AiExchangeRateFetchException`: `Log::warning`, grava failure cache e retorna `failed`;
7. libera o lock em `finally`.

O callback de auditoria roda **dentro** da transação: se a auditoria falhar, a linha automática é desfeita. O HTTP já ocorreu (não é reversível), mas o preço não entra em vigor sem trilha.

**Config (`config/ai.php`):**

```php
'exchange_rate' => [
    'base_url' => env('AI_EXCHANGE_RATE_BASE_URL', 'https://api.frankfurter.dev/v1'),
    'connect_timeout_seconds' => (int) env('AI_EXCHANGE_RATE_CONNECT_TIMEOUT', 3),
    'timeout_seconds' => (int) env('AI_EXCHANGE_RATE_TIMEOUT', 5),
    'failure_cache_minutes' => 15,
    'schedule_time' => env('AI_EXCHANGE_RATE_SCHEDULE_TIME', '06:05'),
],
```

Sem segredo. `php artisan config:clear` no deploy.

### 2.3 Onde a busca sob demanda entra

`AiPricingPolicyResolver::current(bool $ensureFresh = false)`:
- `ensureFresh=true` → chama `rates->ensureToday()` antes de montar a política;
- `false` → só lê o banco (manager e testes não fazem HTTP).

Chamar com `ensureFresh: true` em:
- `AiRunEstimateService` (primeira estimativa da clínica);
- `AiRunExecutionService` **somente no fallback** `$run->pricingPolicy() ?? current(ensureFresh: true)`;
- `AiOrchestrator` **somente no fallback** de `normalizeChargeForResponse`, para o diagnóstico usar a mesma cotação do dia;
- `MedicinePosologyBatchService` (estimativa interna).

O manager (`AiPricingController::index`) usa `fromRate($current)` e **nunca** dispara HTTP no carregamento.

## 3. Contratos de markup por ação

### 3.1 Workflows canônicos

`AiPricingSettings::WORKFLOWS`, nesta ordem (a mesma de `manager_ai_pricing.workflows`):

1. `exam_assistant`
2. `report_drafting`
3. `consensus_review`
4. `eye_image_analysis`
5. `assistant_chat`
6. `record_assist`
7. `medicine_posology`
8. `platform_finance_digest`
9. `platform_finance_chat`

`INTERNAL_WORKFLOWS = ['medicine_posology', 'platform_finance_digest', 'platform_finance_chat']`. As 6 primeiras são cobradas da clínica; as 3 internas não são cobradas hoje.

### 3.2 `AiPricingSettings`

Adicionar:
- `KEY_MARKUP_BY_WORKFLOW = 'ai.pricing.markup_by_workflow'`.
- `markupByWorkflow(): array<string,float>` — merge `config('ai.pricing.markup_by_workflow', [])` + JSON armazenado; ignora valores inválidos; retorna só overrides.
- `markupMultiplierFor(string $workflow): float` — override válido ou global.
- `forgetCache()` inclui a nova chave (comentário “6 chaves”).
- `update()` grava o mapa com `json_encode(array_map('floatval', ...))`.
- `assertValid()` valida: chave string em `WORKFLOWS`; valor numérico finito entre `1.0` e `20.0`; erro `markup_by_workflow`.
- Docblock das chaves.

**Default:** `config('ai.pricing.markup_by_workflow') = []`; todas as ações herdam o global `5x`. Não gravar `5x` em cada ação — assim uma mudança futura do global alcança quem não tem override.

### 3.3 DTO e fórmula

`AiPricingPolicyData`:
- novo parâmetro **no fim** do construtor: `public array $markupByWorkflow = []` (não quebra chamadas posicionais do lote 1);
- `markupFor(string $workflow): float`: override numérico e `1–20`, senão `markupMultiplier`;
- atualizar docblock de `source` para `manual|frankfurter|legacy_topup|fallback`.

`AiPricingPolicyResolver::fromRate(array $rate)` monta o DTO com `markupByWorkflow: $settings->markupByWorkflow()`.

`AiPricingService`:
- `grossCents(float $rawCostUsd, AiPricingPolicyData $policy, string $workflow, RoundingMode $rounding = HALF_UP)` usa `$policy->markupFor($workflow)`.
- `chargeCentsFromUsd()` e `buildEstimate()` passam o workflow.
- `normalizeChargeForResponse(AiProviderResponseData $response, AiPricingPolicyData $policy, string $workflow)` — parâmetro **obrigatório** para não cair no global por engano; `AiOrchestrator` passa `$run->workflow`.
- `AiCreditEstimateData::runPricingAttributes()` e `toArray()` usam `$policy->markupFor($this->workflow)`.
- `AiRun::pricingPolicy()` reconstrói com o valor congelado como `markupMultiplier` (mapa vazio): qualquer recomputação usa o snapshot, não o global atual.

### 3.4 Snapshot e ações internas

- Runs cobradas (clínica) continuam gravando `exchange_rate_brl_usd`, `markup_multiplier` (agora efetivo da ação), `minimum_charge_cents`, `pricing_source` e `priced_at` via `runPricingAttributes()`.
- Runs internas de P&L e `medicine_posology` continuam sem snapshot e com `reserved_charge_cents=0`; o markup configurado aparece no manager como conta teórica, mas não é cobrado. Registrar isso na UI e no aceite.
- Nenhuma mudança na reserva/cobrança: `reserve()` antes, `consumeReservation(min(actual, reserved))`, devolução do resto. Sem saldo negativo.

## 4. Manager (backend)

### 4.1 `AiPricingController::index`

- `$current = $rates->current(); $policy = $policies->fromRate($current);` (sem HTTP).
- `rate` acrescenta: `fetched_at`, `fetched_today`, `is_automatic`, `is_manual_override`, `age_days`.
- `examples` (ladder de `exam_assistant`) acrescenta `markup_multiplier` **efetivo da ação** e `exchange_rate_brl_usd`; a UI deixa de usar o global para a conta.
- Novo `workflow_examples`: uma linha por workflow com `workflow`, `workflow_label`, `is_internal`, `raw_cost_usd=0.01`, `exchange_rate_brl_usd`, `markup_multiplier` (efetivo), `minimum_charge_cents`, `charge_cents`, `charge_formatted`, `minimum_applied`.
- `settings` acrescenta `markup_by_workflow` (objeto; vazio vira `{}`) e `internal_workflows` (lista constante).
- `urls` acrescenta `exchange_rates_fetch`.
- `history` acrescenta `fetched_at` (created_at) e mantém `source`.
- `storeExchangeRate` e `fetchExchangeRate` devolvem `history_first` também com `fetched_at`, para a UI prepender a linha completa.

### 4.2 `updateSettings`

- Validar `markup_by_workflow => ['sometimes','array']`.
- `settingsPayload()` inclui o mapa (cast `(object)`) e `internal_workflows`.
- `projectSettings()`: se veio o mapa, `after['markup_by_workflow'] = (object) array_map('floatval', [...config, ...request])` (mesma semântica dos pisos: o request substitui os overrides, o config é o padrão).
- Manter a transação única: `settings->update()` + `audit->recordAdminAction` + `auditedOrFail`; cache só depois do commit.
- Auditoria `manager.ai_pricing.settings_updated` já cobre; o `newValues`/`oldValues` passam a incluir o mapa.

### 4.3 `POST /manager/ai-pricing/exchange-rates/fetch`

- Rota no grupo do manager, `authorizeOwner()`, throttle `manager-destructive`, nome `manager.ai-pricing.exchange-rates.fetch`.
- Request: `reason` obrigatório, `10–500`.
- Chama `rates->fetchNow($auditClosure)`. O closure grava `manager.ai_pricing.exchange_rate_fetched` com `source`, `rate`, `effective_at`, `fetched_at`, `auditableType='ai_exchange_rate'`, `auditableId=$row->id`, `reason`; usa `auditedOrFail` para exigir a linha.
- Respostas:
  - `201 created`: `message`, `rate` (novo `current()`), `history_first`.
  - `200 already_fetched`: `message`, `rate`, `already_fetched=true`; auditar a tentativa em best-effort (sem alteração de preço).
  - `409 busy`: “busca em andamento”; sem HTTP duplicado.
  - `502 failed`: `message` seguro, `rate` atual; log `warning`; auditoria de tentativa best-effort.
- `Log::info` no sucesso, sem dados clínicos.

## 5. Manager (frontend)

Arquivo: `resources/js/Pages/Panel/Manager/AiPricing/Index.vue`.

### 5.1 Cotação

- Card “Cotação atual”: mostrar `source` (incluindo **Frankfurter (BCE)**), `effective_at`, `fetched_at`, `age_days` (“hoje”/“há N dias”), badge “automática” e badge “manual sobrepõe a automática de hoje” quando `is_manual_override`.
- Botão **“Buscar agora”** (`type=button`, fora do form de cotação manual), habilitado quando `!fetched_today` ou `is_fallback`; se `fetched_today`, mostrar “A automática de hoje já foi buscada; para corrigir, registre uma cotação manual.”
- Modal de confirmação com motivo (reutilizar `CenteredModal`), `fetchForm`, POST no `urls.exchange_rates_fetch`; tratar 201/200/409/502/422; atualizar `currentRate`, prepender `history_first` e recarregar `examples`, `workflow_examples`, `rate` e `history`.
- Aviso de fallback atualizado: “Registre uma cotação manual ou busque a automática.”
- Hint de vigência manual: “Cotação manual com vigência anterior a hoje perde para a automática de hoje.”

### 5.2 Markup por ação

- Dentro do **mesmo** `<form data-test="settings-form">` (uma única transação/auditoria), adicionar fieldset “Markup por ação” após os pisos.
- Iterar `t.workflows` (ordem canônica). Cada linha: label, checkbox “usar padrão global”, input numérico `1–20` (`step=0.01`), valor efetivo exibido. Ao desmarcar, criar override com o global atual; ao marcar, remover a chave.
- `settingsForm.markup_by_workflow` guarda **somente overrides**; o payload de settings envia o mapa.
- Resumo da confirmação lista cada override e o global; o modal existente continua.
- Após salvar, atualizar `currentSettings` e clonar `markup_by_workflow` na resposta; recarregar `workflow_examples`.
- Erro 422 `markup_by_workflow` destaca o fieldset e associa a mensagem ao primeiro input/fieldset (ajustar `focusError` se necessário).

### 5.3 Conta por ação

- Novo card/tabela “A conta por ação” com as 9 linhas de `workflow_examples`: ação, custo US$ 0,01, câmbio, markup efetivo, preço, piso. Badge “Interno — não cobrado” para as 3 internas.
- O ladder existente de `exam_assistant` passa a usar `example.markup_multiplier` (efetivo), não o global.
- Manter `examplesStale` enquanto os exemplos não recarregarem.

### 5.4 Textos

Adicionar em `lang/pt_BR/manager_ai_pricing.php` e `lang/en/manager_ai_pricing.php`:
- `workflows`: 9 chaves na ordem canônica;
- `sources.frankfurter`, `sources.ptax_bcb` (rótulo “não usado”);
- `fetched_at`, `age`, `age_days`, `today`, `automatic`, `manual_override`, `fetch_now`, `fetch_confirm`, `fetch_hint`, `fetch_success`, `fetch_already`, `fetch_failed`, `fetch_busy`, `fetch_unavailable`;
- `markup_workflow_title`, `markup_workflow_hint`, `markup_use_default`, `markup_global_default`, `markup_effective`;
- `workflow_examples_title`, `workflow_examples_hint`, `internal_not_charged`;
- `errors.markup_by_workflow`, `errors.ai_exchange_rate_fetch_failed`;
- atualizar `subtitle`, `fallback_warning`, `rate_hint`, `effective_hint`, `examples_hint`.

## 6. Job e agendamento

### 6.1 Comando e job

- `app/Console/Commands/FetchAiExchangeRateCommand.php`: assinatura `ai:fetch-exchange-rate`; apenas `FetchAiExchangeRateJob::dispatch()`; `purpose` claro.
- `app/Jobs/AI/FetchAiExchangeRateJob.php` (ou pasta de jobs existente): `ShouldQueue`, fila `default`, `tries=1`, `timeout=10`; `handle(AiExchangeRateService $rates)` chama `fetchNow()`; loga `info` em sucesso/already/busy, `warning` em falha com cotação anterior e `error` em falha sem cotação; `failed()` loga.
- A busca do botão e da precificação é síncrona no serviço; o job é o caminho do agendador.

### 6.2 `routes/console.php`

```php
Schedule::command('ai:fetch-exchange-rate')
    ->dailyAt((string) config('ai.exchange_rate.schedule_time', '06:05'))
    ->name('ai:fetch-exchange-rate')
    ->runInBackground()
    ->withoutOverlapping()
    ->onOneServer();
```

Atualizar `tests/Feature/Queue/SchedulePriorityTest.php` com a entrada:
`'ai:fetch-exchange-rate' => ['5 6 * * *', true, 1440]` (ajustar se D2 mudar).

## 7. Tarefas ordenadas

### Backend (Forja ou orquestrador)

| # | Tarefa | Arquivos | Aceite |
|---|---|---|---|
| B1 | Enum/config/base | `app/Enums/AI/AiExchangeRateSource.php`, `config/ai.php`, `.env.example` (opcional), docblocks de `AiExchangeRate`/`AiUsdBrlRate` e comentário da migration (sem schema) | `Frankfurter` existe; `PtaxBcb` preservado sem uso; config sem segredo |
| B2 | Serviço de câmbio | `AiExchangeRateService.php`, `app/Domains/AI/Exceptions/AiExchangeRateFetchException.php` | `current()` com precedência e metadados; `ensureToday`/`fetchNow` com lock, timeout, faixa e failure cache |
| B3 | Job/comando/agenda | `FetchAiExchangeRateCommand.php`, `FetchAiExchangeRateJob.php`, `routes/console.php`, `SchedulePriorityTest.php` | Job na `default`, comando agendado, teste do scheduler atualizado |
| B4 | Settings | `AiPricingSettings.php` | `markupByWorkflow`, `markupMultiplierFor`, validação `1–20`, cache, update |
| B5 | DTO/resolver | `AiPricingPolicyData.php`, `AiPricingPolicyResolver.php` | `markupFor`, `fromRate`, `current(ensureFresh)`; chamadas de precificação com `true` |
| B6 | Fórmula/snapshot | `AiPricingService.php`, `AiCreditEstimateData.php`, `AiRun.php`, `AiOrchestrator.php`, `AiRunExecutionService.php`, `AiRunEstimateService.php`, `MedicinePosologyBatchService.php` | Markup efetivo na fórmula, `toArray`, `runPricingAttributes` e `pricingPolicy`; `normalizeChargeForResponse` com workflow e fallback `ensureFresh: true` |
| B7 | Manager backend | `AiPricingController.php`, `routes/manager.php` | Payloads, `workflow_examples`, `fetchExchangeRate`, auditoria atômica, sem HTTP no index |
| B8 | Testes backend | arquivos da seção 8 | Casos verdes e sem regressão do lote 1 |

### Frontend (Prisma)

| # | Tarefa | Arquivos | Aceite |
|---|---|---|---|
| F1 | Cotação/fonte/idade/botão | `resources/js/Pages/Panel/Manager/AiPricing/Index.vue` | Fonte/idade, “buscar agora” com motivo, estados 201/200/409/502/422 |
| F2 | Markup por ação | mesmo arquivo | Overrides, “usar padrão global”, confirmação, erro 422, payload correto |
| F3 | Conta por ação | mesmo arquivo | Tabela das 9 ações, internas marcadas, ladder com markup efetivo |
| F4 | Lang | `lang/pt_BR/manager_ai_pricing.php`, `lang/en/manager_ai_pricing.php` | Paridade e ordem dos 9 workflows |
| F5 | Vitest | `tests/JavaScript/Panel/Manager/AiPricingIndex.test.js` | Render, salvar mapa, fetch, erros, conta |

**Sequência:** B1 → B2 → B3 → B4 → B5 → B6 → B7 → F1/F2/F3/F4 → testes. B4/B5 podem rodar em paralelo com B2/B3 se os contratos desta seção estiverem congelados. F1 depende de B7; F2/F3 dependem de B4/B5/B7.

## 8. Testes

### 8.1 Pest — câmbio (`tests/Feature/AI/AiExchangeRateFrankfurterTest.php`)

Usar `Http::fake`, `Http::preventStrayRequests()`, `travelTo` e `RefreshDatabase`.

1. **Sucesso:** resposta `5.4321` grava uma linha `frankfurter`, `effective_at` da data, `recorded_by=null`, `note`; `current()` devolve `frankfurter`, `is_automatic=true`, `fetched_today=true`, `age_days` correto.
2. **Idempotência:** duas chamadas de `ensureToday()` no mesmo dia → 1 request e 1 linha; segunda retorna `already_fetched`.
3. **Dia seguinte:** 23:59 e 00:01 (fuso local) → duas linhas, uma por dia; `current()` usa a nova.
4. **Falha HTTP 500/timeout:** sem linha nova; `current()` mantém a última (manual/auto antiga/topup); `Log::warning`; failure cache evita segunda chamada por 15 min.
5. **JSON inválido/lixo:** `rates` ausente, `BRL` não numérico, `base` errado, `date` inválida/futura → `failed`, sem linha.
6. **Faixa:** `0.49` e `100.01` rejeitados; `0.5` e `100` aceitos.
7. **Precedência:** manual de hoje > automática de hoje; automática de hoje > manual de ontem; manual de ontem > automática de ontem; topup > fallback; fallback quando vazio.
8. **Manual do mesmo dia após a automática:** `current()` volta `manual` e `is_manual_override=true`.
9. **Lock:** lock ocupado → `busy`, sem HTTP.
10. **`current()` não faz HTTP:** `Http::preventStrayRequests()` e chamada direta.

### 8.2 Pest — markup (`tests/Feature/AI/AiPricingWorkflowMarkupTest.php` e ajustes)

1. `markupFor`: override `8x` em `report_drafting`, global `5x` nas demais; override inválido cai no global.
2. Fórmula: `0.0133 USD × 5.5 × 8 × 100 = 58.52` (HALF_UP → 59); comparar com o global.
3. Piso continua vencendo quando o bruto é menor; override não altera o piso.
4. `runPricingAttributes()` grava `markup_multiplier=8.000` para `report_drafting`.
5. `AiRun::pricingPolicy()->markupFor('report_drafting')` devolve o valor congelado mesmo com o global atual diferente.
6. `normalizeChargeForResponse` usa o markup da ação.
7. `AiCreditEstimateData::toArray()` devolve o markup efetivo.
8. `AiPricingSettings::update/assertValid`: mapa válido, chave desconhecida, valor `0`, `21`, `abc`; cache invalidado; `forgetCache` inclui a chave.
9. Ações internas: `markupFor` funciona, mas a run interna continua `reserved_charge_cents=0` e sem snapshot.

### 8.3 Pest — manager (`tests/Feature/Manager/AiPricingControllerTest.php`)

1. Index: `settings.markup_by_workflow` e `workflow_examples` presentes; `Http::preventStrayRequests()` prova que o carregamento não busca.
2. Salvar mapa: 200, valor efetivo, auditoria, `AiPricingSettings::markupMultiplierFor`.
3. 422 para mapa inválido/desconhecido; `field=markup_by_workflow`.
4. Auditoria falhando: 500, mapa antigo preservado, cache limpo.
5. `POST fetch` sucesso: 201, linha `frankfurter`, auditoria `manager.ai_pricing.exchange_rate_fetched`, `rate.source=frankfurter`, `history_first`.
6. `POST fetch` repetido: 200 `already_fetched`, 1 linha, 1 request.
7. `POST fetch` falha: 502, sem linha nova, `rate` atual, log.
8. `POST fetch` sem permissão: 403; sem motivo: 422; lock ocupado: 409.
9. Manual após fetch: `current.source=manual`, `is_manual_override=true`.
10. Gate: financeiro não-owner/suporte não acessa.

### 8.4 Pest — vazamento e lang

- `tests/Feature/AI/AiPricingBrlTest.php`: adicionar `markup_by_workflow`, `markup_effective`, `effective_markup`, `markup_for_workflow` a `AI_PRICING_FORBIDDEN_KEYS`; manter a varredura recursiva das rotas de clínica; adicionar asserção positiva no manager (`settings.markup_by_workflow` e `workflow_examples.*.markup_multiplier`) para o teste não passar por ausência nos dois lados.
- `tests/Unit/Lang/ActionsAndPortalTranslationParityTest.php`: `manager_ai_pricing` com as 9 chaves de workflow e paridade pt_BR/en; se tocar `manager_ai_usage`, incluir a checagem.
- `tests/Feature/Manager/AiUsageControllerTest.php` (se existir): `pricing.markup_multiplier` do run detail é o efetivo congelado.

### 8.5 Vitest (`tests/JavaScript/Panel/Manager/AiPricingIndex.test.js`)

1. Renderiza fonte/idade, badge automática/manual override e a tabela das 9 ações; internas com “não cobrado”.
2. “Buscar agora” desabilitado quando `fetched_today`; habilitado no fallback; modal exige motivo; 201 atualiza cotação e histórico; 200 `already_fetched` mostra aviso e não duplica; 502 mostra erro e mantém a cotação.
3. Salvar markup por ação: desmarcar “usar padrão” cria override; marcar remove; payload envia `markup_by_workflow`; confirmação lista os overrides; 422 destaca o fieldset.
4. Ladder usa o markup efetivo de `exam_assistant` (não o global) quando há override.
5. `router.reload` inclui `workflow_examples`.
6. Nenhum fetch de câmbio no carregamento da página.

### 8.6 Comandos de validação

```bash
rtk proxy php vendor/bin/pest tests/Feature/AI/AiExchangeRateFrankfurterTest.php \
  tests/Feature/AI/AiPricingWorkflowMarkupTest.php \
  tests/Feature/AI/AiPricingBrlTest.php \
  tests/Feature/Manager/AiPricingControllerTest.php \
  tests/Feature/Queue/SchedulePriorityTest.php \
  tests/Unit/AI/AiPricingServiceTest.php \
  tests/Unit/AI/AiRunExecutionServiceFlowTest.php \
  tests/Unit/Lang/ActionsAndPortalTranslationParityTest.php

rtk npm test -- tests/JavaScript/Panel/Manager/AiPricingIndex.test.js
rtk proxy php vendor/bin/pint --test <arquivos alterados>
rtk npx eslint <arquivos JS alterados>
rtk npx prettier --check <arquivos JS alterados>
```

Não rodar `npm run verify`/`npm run build` como check passivo: o build copia TinyMCE e substitui `public/vendor/`, `public/build/` e `bootstrap/ssr/`. Se o orquestrador autorizar, preservar os artefatos locais antes.

## 9. Riscos e invariantes

1. **Lote 1 sem commit:** não usar `git checkout`/`reset`; não criar worktree a partir do HEAD sem transportar o diff do lote 1. Se a divisão backend/frontend exigir worktrees, o orquestrador deve consolidar o lote 1 (com “commit autorizado”) ou copiar o diff. Sem autorização, trabalhar no checkout atual.
2. **Fuso:** `current()` e a janela do dia usam `America/Sao_Paulo`; não misturar `whereDate` UTC. Testar 23:59/00:01. A `effective_at` manual deve ser normalizada para o fuso da aplicação antes de gravar.
3. **Latência:** a primeira estimativa do dia pode esperar até 5 s; a failure cache de 15 min evita repetição. Não reduzir o timeout a ponto de falhar em rede lenta.
4. **Auditoria:** o insert automático + auditoria do botão são atômicos; o HTTP não é reversível, mas o preço não entra em vigor sem trilha. O job não tem auditoria de admin (linha `created` do model cobre).
5. **Precedência:** D3 é proposta; se o dono escolher “manual sempre vence”, ajustar a ordem e os testes antes de implementar.
6. **Ações internas:** configurar markup não cria cobrança nem snapshot; a UI deve dizer “não cobrado” para não prometer efeito financeiro.
7. **Vazamento:** `markup_by_workflow`, `markup_multiplier`, `exchange_rate` e `workflow_examples` são manager-only. Nenhuma prop de clínica pode carregar o mapa; o teste recursivo é o gate.
8. **Billing:** não alterar `AiCreditWalletService`, reserva, liquidação, gateways, webhooks ou idempotência. O lote 2 só muda o **preço** (câmbio/markup); a cobrança continua “reserva antes, cobra até a reserva”, sem saldo negativo.
9. **Agenda:** a nova entrada precisa de `withoutOverlapping`, `onOneServer` e `runInBackground`, e a tabela de `SchedulePriorityTest` precisa ser atualizada; senão o teste de agenda quebra.
10. **Config cache:** após alterar `config/ai.php`/`.env`, `config:clear`; o manager lê `system_settings` com cache invalidado após commit.
11. **Sem PTAX/BCB:** Frankfurter é a única fonte automática; `PtaxBcb` permanece no enum apenas por compatibilidade e não é usado.
12. **Sem commit/push/deploy** sem a frase “commit autorizado” do orquestrador.

## 10. Critérios de aceite

- [ ] `AiExchangeRateSource::Frankfurter` existe; nenhuma chamada a PTAX/BCB; sem nova dependência.
- [ ] `current()` aplica a precedência D3 (ou a decidida), é somente leitura e devolve fonte, idade, `fetched_at`, `fetched_today` e `is_manual_override`.
- [ ] A primeira precificação do dia busca a Frankfurter e grava uma única linha; as seguintes leem o banco; falha mantém a última cotação e loga aviso; faixa `0,5–100`, timeout `5 s`.
- [ ] O job diário existe na fila `default`, o comando está agendado com `runInBackground`, `withoutOverlapping` e `onOneServer`, e o teste de agenda passa.
- [ ] O manager mostra fonte/idade, “buscar agora” com motivo e auditoria, e não faz HTTP no carregamento.
- [ ] `markupByWorkflow`/`markupFor` funcionam; global `5x` é o padrão; overrides `1–20`; a fórmula, o `toArray`, o snapshot e o run detail usam o markup efetivo da ação.
- [ ] O manager mostra a conta por ação (9 workflows; internas marcadas como não cobradas).
- [ ] `markup_by_workflow` não aparece em nenhuma rota de clínica; teste de vazamento e paridade de lang verdes.
- [ ] Reserva/cobrança, gateways e webhooks intactos; sem saldo negativo.
- [ ] Testes focados Pest/Vitest, Pint/ESLint/Prettier nos arquivos alterados; dívida legada não escondida.
- [ ] Nenhum commit/push/deploy sem “commit autorizado”.

## 11. Divisão e handoff

- **Backend:** Forja (se reativada) ou o próprio orquestrador — B1–B8, testes Pest, Pint.
- **Frontend:** Prisma — F1–F5, Vitest, ESLint/Prettier.
- **Revisão:** Lupa (corretude, locks, regressão, testes) e Sentinela (ACL/tenant/vazamento/LGPD) em paralelo, somente achados.
- **Correção:** Forja/Prisma após os achados; nova rodada de testes.
- **Commit:** somente com “commit autorizado”; mensagem no padrão do repositório, sem `Co-Authored-By` não autorizado.
- **Handoff:** ao terminar, avisar `Claude Code` via `maestri ask "Claude Code" "<resumo>"` e manter `maestri floor status` atualizado se estiver em floor.

## 12. Pendências que o plano não fecha sozinho

1. Confirmar D1–D4 (markups iniciais, horário, precedência da manual antiga, idempotência do “buscar agora”).
2. Confirmar se o lote 1 será commitado antes da divisão em worktrees (recomendado) ou se o diff será transportado.
3. Confirmar se o drawer de uso da IA será ajustado neste lote ou ficará na pendência do lote 1 (D6).
4. Confirmar se o dono quer uma linha automática por dia mesmo quando já existe manual do dia (recomendado: sim, para histórico e para o dia seguinte; o manual continua vencendo).
