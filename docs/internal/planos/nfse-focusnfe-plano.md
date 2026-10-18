# Plano — NFS-e do SaaS via Focus NFe

Data: 2026-10-18 · Autor: Bússola (planejador) · Briefing: [nfse-focusnfe-briefing.md](nfse-focusnfe-briefing.md)

Âncoras conferidas no código em 18/10/2026: `Invoice`/`InvoiceStatus`, evento `InvoicePaid` (disparo em `SubscriptionCycleService` ×3 e `AiCreditPackCheckoutService`), `RefundService::markFullyRefunded`, `ProcessWebhookEventService` (ramo REFUNDED/CHARGEBACK/PARTIAL e duplicidade com `touchInvoice:false`), `AiCreditPackCheckoutService` (ramo refunded/chargeback + `purchase.invoice_id`), `GatewayCredential` (cifra + `reason` ≥ 20 + audit), `SubscriptionSetting` (system_settings, cache 10 min, `setValue` invalida), `routes/manager.php` (grupos `saas.role:*`, `throttle:manager-read|manager-destructive`), `routes/api.php` (webhook de billing + limiter no `AppServiceProvider`), `routes/console.php`, `PanelNavigation::managerNav()`, `Panel/Manager/{Gateways,Subscriptions,AiCreditPurchases}`, `CheckoutService::summary/invoiceRow`, `SubscriptionsController::invoices()`, grupo `my-subscription` (`billing.contact` + `EnsureBillingContact`), `AsaasSandboxSmokeTest`, `Entity`+`EntityUser::billingContacts`, `PayerDocument`/`CpfCnpj`, `HasAuditColumns`/`Auditable`, `BillingLogService`, `CorrelationIdService`, `Storage::disk()` (disco padrão; download como `BillingController`).

Hipótese de invalidação: a Focus muda a API sem versão nova (reconferir `updatedAt` das páginas antes de implementar) e qualquer refactor do webhook de billing (os 4 pontos de disparo do evento novo moram nele).

Escopo (decidido pelo dono): **só a dona do SaaS emite**; tomador = clínica pagadora; fato gerador = fatura paga (`paid`) de assinatura/ciclo/troca e pacote de IA; cortesia/trial não geram nota; sem gate de plano. Fora de escopo: clínica emitindo para pacientes/convênios, cadastro via `/v2/empresas`, NFS-e recebidas, substituição/carta de correção.

Resumo do desenho: **3 migrations** (`nfse_credentials`, `nfse_documents`, `entities.ibge_code`), **3 enums**, **2 models**, **1 config** (`config/fiscal.php`), **8 classes de serviço/suporte**, **2 jobs**, **1 evento + 2 listeners**, **2 commands** (agendados), **4 controllers**, **~20 rotas**, **1 item de menu**, **~10 arquivos Vue**, **lang pt_BR/en** e a bateria de testes (§11). Regra de ouro: nenhuma mudança de comportamento nos fluxos de billing existentes — só chamadas aditivas do evento `InvoiceFullyReversed` e o bloco `nfse` nos payloads.

---

## 1. Modelo de dados (item 2)

### 1.1 `nfse_credentials` — segredos por ambiente (item 1)

Migration `database/migrations/2026_10_18_120000_create_nfse_credentials_table.php`:

| coluna | tipo | nota |
|---|---|---|
| `id` | uuid PK | `HasUuids` |
| `environment` | varchar(20) UNIQUE | `homologacao`\|`producao` (`NfseEnvironment`) |
| `token` | text | **cifrados** no model (`encrypted`) — token da empresa |
| `master_token` | text NULL | cifrado — token master (só `GET /v2/municipios*`; o briefing exige token master p/ APIs acessórias) |
| `webhook_token` | text | cifrado — vai no path da URL de callback (roteia, não autentica) |
| `webhook_secret` | text | cifrado — valor esperado no cabeçalho `X-FocusNfe-Secret` |
| `hook_id` | varchar(64) NULL | id do hook na Focus (registro/remoção por ambiente) |
| `hook_event` | varchar(20) NULL | `nfse`\|`nfsen` usado no registro (persistido: settings pode mudar depois) |
| `last_tested_at` / `last_test_ok` / `last_test_message` | timestamp / bool / varchar(255) | "testar credencial" |
| `created_by` / `updated_by` | uuid NULL | `HasAuditColumns` |
| `created_at`/`updated_at` | timestamps | |

Model `app/Models/Fiscal/NfseCredential.php` (casts `encrypted`, `last_test_ok` bool, `Auditable` + `HasAuditColumns`, `$auditExclude` com os 4 campos de segredo). **Nenhum endpoint devolve o valor**: só `has_token`/`has_master_token` (booleano), como `GatewaysController::credentials()` faz com `has_secret`.

### 1.2 `nfse_documents` — notas 1:N com faturas (item 2)

Migration `database/migrations/2026_10_18_120100_create_nfse_documents_table.php`:

| coluna | tipo | nota |
|---|---|---|
| `id` | uuid PK | |
| `invoice_id` | uuid FK `invoices` | index; 1:N (reemissão = nova linha) |
| `entity_id` | uuid FK `entities` | index; isolamento do download da clínica |
| `environment` | varchar(20) | ambiente de emissão |
| `standard` | varchar(20) | `municipal`\|`nacional` (`NfseStandard`) |
| `ref` | varchar(40) UNIQUE | **determinística** = UUID do documento sem hífens, maiúsculo (32 alfanuméricos, nasce uma vez por linha) |
| `status` | varchar(30) index | `pending_send`\|`send_failed`\|`processando_autorizacao`\|`autorizado`\|`erro_autorizacao`\|`cancelado` |
| `numero` | varchar(30) NULL | NFS-e autorizada |
| `codigo_verificacao` | varchar(60) NULL | |
| `amount` / `currency` | decimal(12,2) / char(3) | valor **congelado** = `invoice.amount` pago |
| `issued_at` / `cancelled_at` | timestamp NULL | |
| `cancelled_externally` | bool default false | "marquei como cancelada na prefeitura" |
| `cancel_required` | bool default false | estorno total sem cancelamento pelo webservice |
| `cancel_required_reason` | varchar(40) NULL | `refund`\|`chargeback` |
| `url` | text NULL | espelho HTML |
| `pdf_url` | text NULL | `url_danfse` (S3 público) |
| `xml_path` / `cancel_xml_path` | varchar(255) NULL | caminho no disco padrão (§8) |
| `payer_snapshot` | jsonb NULL | tomador congelado (LGPD/auditoria) |
| `request_payload` / `response_payload` | jsonb NULL | **saneados** (nunca têm token) |
| `errors` | jsonb NULL | `[{codigo,mensagem,correcao}]` da Focus |
| `justification` | varchar(255) NULL | justificativa do cancelamento (15–255) |
| `correlation_id` | varchar(64) NULL | |
| `source` | varchar(20) default 'auto' | `auto`\|`manual`\|`batch` |
| `created_by` / `updated_by` | uuid NULL | `HasAuditColumns` |
| `created_at`/`updated_at` | timestamps | |

**Unique parcial "uma nota autorizada por fatura"** (Postgres, via `DB::statement` porque o schema builder não cobre `WHERE`):

```sql
CREATE UNIQUE INDEX nfse_documents_invoice_occupying_unique
    ON nfse_documents (invoice_id)
    WHERE status IN ('pending_send','processando_autorizacao','autorizado');
```

Índices extras: `(entity_id, status)`, `(status, updated_at)` (varredura do sync) e `invoice_id`.

Model `app/Models/Fiscal/NfseDocument.php`: casts json array (`payer_snapshot`, `request_payload`, `response_payload`, `errors`), enum `status`, datas, `amount` decimal, relations `invoice()`/`entity()`/`creator()`, `Auditable` + `HasAuditColumns` com `$auditExclude = ['request_payload','response_payload','payer_snapshot','errors']` (auditoria guarda a mudança de status, não o payload inteiro).

### 1.3 `entities.ibge_code` (lacuna do briefing)

Migration `database/migrations/2026_10_18_120200_add_ibge_code_to_entities_table.php`: `entities.ibge_code` varchar(7) NULL (IBGE do município do tomador; usado em `tomador.endereco.codigo_municipio` e `servico.codigo_municipio`/`codigo_municipio_prestacao`). Preenchimento na tela (§2.4). Sem backfill automático.

### 1.4 Enums (`app/Enums/Fiscal/`)

- `NfseStatus.php` (string): `PendingSend='pending_send'`, `SendFailed='send_failed'`, `Processing='processando_autorizacao'`, `Authorized='autorizado'`, `AuthorizationError='erro_autorizacao'`, `Cancelled='cancelado'`; helpers `label()` (`__("fiscal.status.*")`), `badge()` (padrão `badge-soft-*` usado no painel) e `isOccupying(): bool` (pending_send|processing|authorized — a mesma lista do índice).
- `NfseStandard.php`: `Municipal='municipal'`, `National='nacional'`; `label()`, `endpoint()` (`nfse`|`nfsen`), `hookEvent()` (mesmos valores).
- `NfseEnvironment.php`: `Homologation='homologacao'`, `Production='producao'`; `label()`, `baseUrl()` (de `config('fiscal.base_urls')`).

### 1.5 Snapshot do tomador

`app/Support/Fiscal/NfseTomadorSnapshot.php::from(Entity $entity): array` — congela: `name` (mb_substr 115), `document` (canônico com letras, via `PayerDocument`/`BrazilianFormat`), `document_type` (`cnpj` se `CpfCnpj` valida como CNPJ, senão `cpf`), `email` (≤ 80: `entity.email` se preenchido, senão 1º destinatário ativo de `EntityUser::billingContacts` — mesma regra do `DunningService`), `phone` (10–11 dígitos de `telephone`/`cellphone`, omitido se ausente), `address { logradouro, numero, complemento, bairro, uf, cep, codigo_municipio }` com os limites da Focus (125/10/60/60/UF/8) e `codigo_municipio` = `entities.ibge_code`. Origem da emissão (webhook/job) **não** depende de sessão: o snapshot é montado e gravado na criação da linha — o payload enviado depois usa o snapshot, não a entidade viva (o `request_payload` fica igual ao enviado).

**Invariantes:** nota nunca sem snapshot; `amount`/`currency` congelados; `ref` única; nenhuma segunda linha ocupando a fatura (§1.2).

---

## 2. Configuração global (item 1) — Manager → Nota fiscal

### 2.1 Config (chave/valor) + seeder

`app/Support/Fiscal/NfseSettings.php` (constantes de chave + getters tipados; grava/lê via `SubscriptionSetting`, cache 10 min já existente; `save(array)` grava cada chave e invalida o cache pelo `setValue`). Chaves (grupo `nfse`, `database/seeders/SubscriptionSettingSeeder.php` ganha os defaults):

- `nfse.prestador.cnpj`, `nfse.prestador.inscricao_municipal`, `nfse.prestador.codigo_municipio` (IBGE 7), `nfse.prestador.cnae`, `nfse.prestador.item_lista_servico` (ex. `01.07`), `nfse.prestador.codigo_tributario_municipio`, `nfse.prestador.codigo_tributacao_nacional_iss` (ex. `010701`), `nfse.prestador.tributacao_iss`, `nfse.prestador.aliquota_iss` (fração: `0.05`; formulário mostra %), `nfse.prestador.iss_retido` (bool), `nfse.prestador.natureza_operacao` (`1`..`6`), `nfse.prestador.optante_simples_nacional` (bool), `nfse.prestador.regime_especial_tributacao` (`1`..`6`, opcional);
- `nfse.standard` (`municipal`|`nacional`), `nfse.environment` (`homologacao`|`producao`), `nfse.auto_issue` (bool), `nfse.copy_email` (opcional);
- `nfse.discriminacao.plano` (default `Assinatura do plano {plano} ({ciclo}) — período {periodo} — fatura {fatura}`) e `nfse.discriminacao.ia` (default `Pacote de créditos de IA ({creditos} créditos) — fatura {fatura}`).

`NfseSettings::isConfigured()` = cnpj + codigo_municipio + item/códigos conforme o padrão preenchidos. Cast de `1/0` para bool na leitura.

### 2.2 Credenciais e tokens

- Salvar token: `PUT`/`POST manager/nfse/credentials/{environment}` com `{token, master_token?, reason}` — `reason` obrigatório `min:20` (copiar mensagens de `manager_hardening` como o `GatewaysController`); grava com casts cifrados; auditoria `recordAdminAction` (evento `manager.nfse.credentials`, `new_values` só com flags `has_token`).
- "Testar credencial" (barata, não fiscal): `GET /v2/hooks` com o token da empresa (valida token + base URL) e, se houver `master_token`, `GET /v2/municipios/{ibge}` — grava `last_tested_at/ok/message` e devolve o resultado. Falha nunca derruba a tela (422 com mensagem traduzida).
- Token **nunca** aparece em: resposta JSON (só `has_token`), log (§9), auditoria (`$auditExclude`), payload da nota (vai só no header Basic).

### 2.3 Webhook (registro/remoção pela tela)

- URL pública do app: `route('fiscal.focusnfe.webhook', ['token' => $cred->webhook_token])` (exibida para copiar; o token é gerado na primeira configuração do ambiente — `Str::random(48)` no save da credencial, nunca trocado sem ação explícita).
- `POST manager/nfse/hooks` `{environment, reason}` → registra na Focus `POST /v2/hooks` `{cnpj, event: hook_event, url, authorization: webhook_secret, authorization_header: 'X-FocusNfe-Secret'}` (segredo = `webhook_secret` gerado no save) e guarda `hook_id`/`hook_event`; `DELETE manager/nfse/hooks/{environment}` remove na Focus e limpa o `hook_id`. Idempotente: registrar de novo primeiro remove o hook antigo.
- A tela mostra por ambiente: configurado? has_token? hook registrado (`hook_id`, url, event) e último teste.

### 2.4 Validar município + IBGE das clínicas

- `GET manager/nfse/municipality?ibge=&refresh=1` → `NfseMunicipalityService::obligations(ibge)` (cache 24 h por ambiente com token master; erros de rede caem no valor cacheado e viram aviso). Devolve as flags do briefing: `nfse_habilitada`, `requer_certificado_nfse`, `possui_ambiente_homologacao_nfse`, `possui_cancelamento_nfse`, `endereco_obrigatorio_nfse`, `cpf_cnpj_obrigatorio_nfse`, `codigo_cnae_obrigatorio_nfse`, `item_lista_servico_obrigatorio_nfse`, `codigo_tributario_municipio_obrigatorio_nfse`, `provedor_nfse`, `status_nfse`. É a fonte das guardas de emissão (§3) e do aviso de cancelamento (§5).
- Card "Clínicas sem IBGE": lista `entities` clientes ativas com `ibge_code` nulo; ação `PUT manager/nfse/entities/{entity}/ibge-code {ibge_code, reason?}` valida 7 dígitos com `GET /v2/municipios/{ibge}` antes de gravar (digitação validada; sem fonte externa nova — decisão §15.3) e audita.

### 2.5 Cliente HTTP

`app/Services/Fiscal/FocusNfeClient.php` + `app/Services/Fiscal/NfseCredentialResolver.php` (`for(NfseEnvironment): NfseCredential`, erro traduzido quando não configurado) + `config/fiscal.php`:

```php
'base_urls' => ['producao' => 'https://api.focusnfe.com.br', 'homologacao' => 'https://homologacao.focusnfe.com.br'],
'timeout' => 30, 'connect_timeout' => 10,
'queue' => env('FISCAL_QUEUE', 'default'),           // único env novo, opcional
'storage_path' => 'nfse',                            // disco padrão (§8)
'sync' => ['after_minutes' => 10, 'stale_pending_minutes' => 15, 'limit' => 100],
'reissue' => ['window_days' => 90, 'limit' => 50],
'webhook' => ['header' => 'X-FocusNfe-Secret'],
```

Métodos (todos recebem `NfseCredential` + `NfseStandard` onde aplicável): `issue(ref, payload)`, `show(ref): ?array` (404 → null), `cancel(ref, justification)`, `email(ref, emails)`, `registerHook(url, authorization, header): array`, `listHooks(): array`, `deleteHook(hookId)`, `municipality(ibge): array`, `municipalityItems(ibge)`/`municipalityTaxCodes(ibge)` (opcionais, para o card), `xml(relativePath): string`. Padrão do `AbstractHttpGateway`: `Http::baseUrl()->withBasicAuth($token,'')->timeout()->connectTimeout()`, sem seguir redirect, log allowlist (`ref`, `status`, `http`, `duracao_ms`, `correlation_id` — nunca header/corpo/token). Erros viram `app/Exceptions/Fiscal/FocusNfeException` (`code`: `auth`|`requisicao_invalida`|`erro_validacao`|`nao_encontrado`|`rate_limited`|`http`, `errors[]`, `retryable()`), tolerando propriedades desconhecidas (a Focus adiciona campos sem aviso).

### 2.6 Controller e rotas (Manager)

`app/Http/Controllers/Manager/NfseSettingsController.php` (`index`, `update`, `storeCredential`, `testCredential`, `storeHook`, `destroyHook`, `municipality`, `updateEntityIbgeCode`) — `authorizeSaasEntity(EntityGate::SaasAdminPanel)` nas ações de config (padrão dos controllers do Manager). Rotas em `routes/manager.php`:

- grupo `saas.role:admin,financial`: `GET nfse` → `manager.nfse.index` (`throttle:manager-read`; página visível para quem emite/reenvia; seções de config escondidas por `permissions.can_settings`);
- grupo `saas.role:admin`: `PUT nfse/settings` (`manager.nfse.settings.update`), `POST nfse/credentials/{environment}` (`manager.nfse.credentials.store`), `POST nfse/credentials/{environment}/test` (`manager.nfse.credentials.test`), `POST nfse/hooks` (`manager.nfse.hooks.store`), `DELETE nfse/hooks/{environment}` (`manager.nfse.hooks.destroy`), `GET nfse/municipality` (`manager.nfse.municipality`), `PUT nfse/entities/{entity}/ibge-code` (`manager.nfse.entities.ibge-code`) — todas `throttle:manager-destructive` exceto `municipality` (`manager-read`).

Item de menu: `app/Support/PanelNavigation.php::managerNav()` ganha, logo depois de `gateways`, `['key' => 'nfse', 'route' => 'manager.nfse.index', 'icon' => 'ti ti-receipt', 'label' => __('actions.sidemenu.nfse'), 'match' => ['manager.nfse.*']]`.

---

## 3. Emissão (item 3)

### 3.1 Serviço único

`app/Services/Fiscal/NfseIssueService.php`:

```php
public function issue(Invoice $invoice, string $source /* auto|manual|batch */, ?User $by = null): NfseDocument
```

1. `DB::transaction`: `Invoice::query()->whereKey($id)->lockForUpdate()->firstOrFail()` (**trava FOR UPDATE na fatura**, sempre dentro de transação curta — nunca com HTTP dentro).
2. Guardas (lançam `app/Exceptions/Fiscal/NfseIssueRejectedException` com chave traduzida → 422 na ação manual / log no automático):
   - status `paid` (fatura `refunded`, `cancelled`, `failed`, `draft`, `pending`/`overdue` → rejeita) e `refunded_amount < amount`;
   - `amount > 0`;
   - tomador com CPF/CNPJ **usável** (`PayerDocument`) e endereço completo **quando** o município exige (`endereco_obrigatorio_nfse`, do cache do §2.4; sem cache, tenta e deixa a validação síncrona da Focus responder);
   - prestador configurado (`NfseSettings::isConfigured()`), credencial do ambiente ativo presente, cnpj/IBGE do tomador presentes quando exigidos;
   - fatura sem nota **ocupando** (`status isOccupying()`): devolve a existente (sem erro) quando a origem é automática, 422 `fiscal.errors.document_exists` no manual.
3. Cria a linha (`pending_send`, `ref` determinística, snapshot, `amount`/`currency` congelados, `source`, `correlation_id` do `CorrelationIdService`); corrida perdida entre duas ações simultâneas estoura o índice único parcial → captura `QueryException` e devolve a linha vencedora.
4. `SendNfseJob::dispatch($document->id)->afterCommit()`.

Chamadores: (a) listener do §3.2, (b) `NfseDocumentsController::issue` (§3.3), (c) comando `fiscal:nfse-reissue` (§3.4).

### 3.2 Listener do `InvoicePaid`

`app/Listeners/Fiscal/IssueNfseOnInvoicePaid.php` (`ShouldQueue`; auto-descoberto em `app/Listeners` como os de `Surgery`): se `NfseSettings::autoIssue()` e o dono da fatura é cliente → `issue($invoice, 'auto')`; `NfseIssueRejectedException`/Focus fora do ar → `BillingLogService` + log (a pendência aparece na tela e no lote do §3.4 — nada de barulho por fatura). Vale para fatura de assinatura e de pacote de IA (`InvoicePaid` cobre os dois).

### 3.3 Ação manual por fatura

`app/Http/Controllers/Manager/NfseDocumentsController.php::issue(Invoice $invoice)` — `POST nfse/invoices/{invoice}/issue` (`saas.role:admin,financial`, Gate `SaasFinancial`, `throttle:manager-destructive`), `source='manual'`, 201 com a linha apresentada ou 422 `{message, errors}`. Pontos de UI: bloco NFS-e do drawer da assinatura (faturas), drawer do pacote de IA e a lista "Faturas pagas sem nota" da página NFS-e (§3.4).

### 3.4 Reemissão em lote

`app/Console/Commands/ReissueNfseInvoicesCommand.php` → `fiscal:nfse-reissue` `[--since=90]` (dias, default de `config('fiscal.reissue.window_days')`) `[--limit=50] [--dry-run] [--force]`: varre faturas `paid` da janela **sem nota ocupando** (mesma query do card da tela) e chama `issue($invoice, 'batch')`; exige `nfse.auto_issue` ligado ou `--force`; imprime o que faria. Agenda em `routes/console.php`: `Schedule::command('fiscal:nfse-reissue')->dailyAt('05:10')->withoutOverlapping()->name('fiscal:nfse-reissue')`.

### 3.5 Job de envio

`app/Jobs/Fiscal/SendNfseJob.php` (`ShouldQueue`, `$tries = 5`, `$backoff = [60, 300, 1800, 3600, 21600]`, `onQueue(config('fiscal.queue'))`):

1. Carrega a linha (só `pending_send`|`send_failed`; outra origem/mudança de estado → no-op).
2. Monta o payload **na hora do envio** com `NfsePayloadBuilder` do `standard` da linha (payload do §3.6), grava `request_payload` saneado e chama `issue()` do cliente.
3. `201/202` → `status = processando_autorizacao` + `response_payload` (`numero_rps`/`serie_rps`/`tipo_rps`/`protocolo` quando vierem).
4. `400/422` → `status = erro_autorizacao` + `errors` (motivo traduzível, sem retry).
5. `401` → `status = send_failed` + log de erro de config (sem retry até trocar token).
6. `429`/timeout/5xx → exceção (retry/backoff padrão); `failed()` marca `send_failed` + log.

### 3.6 Builders de payload (Unit-testáveis)

- `app/Contracts/Fiscal/NfsePayloadBuilder.php` + `app/Services/Fiscal/Payloads/MunicipalNfsePayloadBuilder.php` e `NationalNfsePayloadBuilder.php` + `NfsePayloadFactory::for(NfseStandard)`.
- Municipal (campos do briefing §Campos): `data_emissao` (`now()->toIso8601String()` no fuso do app), `natureza_operacao`, `optante_simples_nacional`, `prestador{...}`, `tomador{...}` (snapshot; o e-mail de cópia não vai no payload de emissão — entra na lista de destinatários do envio pós-autorização, §7.1), `servico{valor_servicos=amount, iss_retido, item_lista_servico, codigo_tributario_municipio, codigo_cnae, discriminacao, codigo_municipio (IBGE do tomador/prestação), aliquota, base_calculo}` — só os campos presentes; `regime_especial_tributacao` quando configurado; placeholders da discriminação renderizados (`app/Support/Fiscal/NfseDiscriminacao.php`: `{plano}`, `{ciclo}`, `{periodo}`, `{fatura}`, `{creditos}`, `{cliente}`).
- Nacional (DPS): `data_emissao`, `data_competencia` (= competência do período/fatura), `emitente_dps = 1`, `codigo_municipio_emissora`, `cnpj_prestador`, `codigo_opcao_simples_nacional`, `regime_especial_tributacao`, `cnpj_tomador`|`cpf_tomador`, `codigo_municipio_prestacao`, `codigo_tributacao_nacional_iss`, `descricao_servico`, `valor_servico`, `tributacao_iss`; `serie_dps`/`numero_dps` conforme §15.2; endereço/e-mail do tomador como no exemplo oficial (ler `campos.focusnfe.com.br` antes de codar).
- `app/Support/Fiscal/NfsePayloadSanitizer.php` — descarta recursivamente chaves sensíveis (`token`, `authorization`, `secret`, `password`) antes de gravar `request_payload`/`response_payload`.

**Invariantes:** a fatura é travada só na criação da linha; o envio nunca segura lock da fatura; `ref` nasce uma vez e é a mesma em envio/consulta/cancelamento/e-mail; reemissão após `erro_autorizacao`/`send_failed`/`cancelado` cria **nova linha/ref**; nada é commitado sem `ShouldDispatchAfterCommit` (listener e jobs).

---

## 4. Acompanhamento (item 4)

### 4.1 Webhook

- Rota em `routes/api.php` (depois do webhook de billing): `Route::post('fiscal/focusnfe/webhook/{token}', FocusNfeWebhookController::class)->name('fiscal.focusnfe.webhook')->middleware('throttle:fiscal-webhook');` + comentário do limiter.
- `app/Http/Controllers/Fiscal/FocusNfeWebhookController.php::__invoke(string $token, Request $request)`:
  1. resolve credencial comparando `hash_equals($cred->webhook_token, $token)` nas 2 linhas → nenhuma: **404 genérico** (não revela existência);
  2. `hash_equals($cred->webhook_secret, (string) $request->header(config('fiscal.webhook.header')))` → diferente: 401 sem detalhe (**webhook só com segredo**);
  3. `ref` do JSON ausente → 200 `{ok:true}` + log (nunca 4xx para a Focus, que retenta 1 min→24 h);
  4. `ProcessNfseWebhookJob::dispatch($ref, $cred->environment->value, $payload)` e **2xx imediato** (processamento em fila).
- Limiter `fiscal-webhook` no `AppServiceProvider` (padrão do `billing-webhook`/`whatsapp-webhook`): token válido → balde próprio da credencial, 120/min; desconhecido → balde global 60/min por IP.
- `app/Jobs/Fiscal/ProcessNfseWebhookJob.php` (`$tries=5`, `$backoff=[60,300,1800]`) → `NfseSyncService::apply(ref, environment, payload, 'webhook')`.

### 4.2 Aplicação idempotente

`app/Services/Fiscal/NfseSyncService.php`:

- `apply(...)`: acha a linha por (`ref`,`environment`) (desconhecida → log + return); `lockForUpdate` no documento; calcula o alvo (`autorizado` → `numero`, `codigo_verificacao`, `issued_at`, `url`, `pdf_url`; `cancelado` → `cancelled_at` + `justification` + `cancelled_externally=false`; `erro_autorizacao` → `errors`; `processando_autorizacao` → `response_payload`); **se todos os campos alvo já são iguais, retorna sem update/auditoria** — idempotência por (`ref`, `status`) mesmo com entrega duplicada. Sempre grava `response_payload` saneado quando muda.
- XML: busca/guarda **fora** da transação (falha = log; a rota de download busca sob demanda em §7).
- `syncDocument(NfseDocument): void` (GET agora) usado pelo comando, pela ação "consultar agora" e (com filtro) pela varredura.
- `app/Console/Commands/SyncNfseDocumentsCommand.php` → `fiscal:nfse-sync`: (a) `processando_autorizacao` com `updated_at < now()-N min` (`fiscal.sync.after_minutes`) → `syncDocument` (fallback das 48 h de webhook); (b) `pending_send` mais velho que `stale_pending_minutes` → re-dispatch do `SendNfseJob` (ref reutilizável enquanto não autorizou); (c) limite/try por documento. Agenda: `Schedule::command('fiscal:nfse-sync')->everyTenMinutes()->withoutOverlapping()->name('fiscal:nfse-sync')`.

**Invariantes:** webhook nunca grava direto na requisição; `ref` desconhecida nunca quebra a entrega; 2xx sempre (exceto segredo/token inválidos); job idempotente.

---

## 5. Cancelamento (item 5)

- `app/Services/Fiscal/NfseCancellationService.php`:
  - `cancel(NfseDocument, string $justification, ?User $by): array` — só `autorizado`; `justification` 15–255 (FormRequest/validação inline); síncrono via `DELETE /v2/{nfse|nfsen}/{ref}`; `{"status":"cancelado"}` → linha `cancelado` + `cancelled_at` + `justification` + `response_payload`, XML de cancelamento buscado/guardado, auditoria; `{"status":"erro_cancelamento"}` → devolve `{message, errors}` para a tela **sem** mudar o status; `400 nfe_nao_autorizada` → mensagem traduzida.
  - `markCancelledExternally(NfseDocument, string $reason, User $by)` — quando `possui_cancelamento_nfse = false` (ou a prefeitura exige cancelar lá): `status = cancelado`, `cancelled_externally = true`, `justification = $reason`, `cancelled_at`; Gate `SaasAdminPanel`, `reason` ≥ 20 caso o motivo não seja a justificativa da prefeitura (auditado).
  - `forReversal(NfseDocument, string $reason /* refund|chargeback */): void` — usado pelo §6: só `autorizado`; tenta cancelar com justificativa padrão (`fiscal.cancel.refund_justification`: "Estorno total da fatura {referencia}" — 15–255); sucesso → `cancelado`; `erro_cancelamento`/`nfe_nao_autorizada`/município sem cancelamento → `cancel_required = true` + `cancel_required_reason` + log/`BillingLogService` (pendência visível na tela).
- Rotas: `POST nfse/documents/{document}/cancel` (`{justification}`; Gate `SaasAdminPanel`; `manager-destructive`) e `POST nfse/documents/{document}/mark-cancelled-externally` (`{reason}`; Gate `SaasAdminPanel`).
- UI: modal de cancelamento mostra a justificativa, o `possui_cancelamento_nfse` (aviso + botão "marcar como cancelada externamente" com motivo quando falso) e os erros da Focus campo a campo.

**Invariantes:** cancelamento é definitivo e só de `autorizado`; nunca automático fora de estorno total/chargeback; motivo sempre auditado.

---

## 6. Estorno ↔ nota (item 6)

- Evento novo `app/Events/Billing/InvoiceFullyReversed.php` (`invoiceId`, `reason: refund|chargeback`, `correlationId?`) implementando `ShouldDispatchAfterCommit`.
- **4 pontos de disparo** (todos aditivos, mínimos):
  1. `ProcessWebhookEventService` ramo REFUNDED (junto do `markFullyRefunded`, ~linha 464);
  2. `ProcessWebhookEventService` ramo CHARGEBACK (após `applyChargeback`);
  3. `AiCreditPackCheckoutService` ramo `refunded` (após `markFullyRefunded`);
  4. `AiCreditPackCheckoutService` ramo `chargeback`.
  Nunca no caminho de pagamento em duplicidade (`touchInvoice:false` / retorno antecipado) — a nota é da cobrança que quitou.
- Listener `app/Listeners/Fiscal/CancelNfseOnInvoiceFullyReversed.php` (`ShouldQueue`): acha a nota ocupando da fatura; sem nota → no-op; `autorizado` → `forReversal` (§5). O evento é fire-and-forget: o webhook de billing não passa a depender da Focus.
- Estorno **parcial**: nenhuma ação automática (nota segue válida, regra fiscal). O drawer da fatura da assinatura mostra aviso `partial_refund_warning` (derivado: `refunded_amount > 0 && refunded_amount < amount` + nota autorizada) — decisão humana; a clínica não vê o aviso.
- Valor da nota nunca é reescrito por estorno.

**Invariantes:** `ProcessWebhookEventService`/`AiCreditPackCheckoutService`/`RefundService` só ganham as 4 chamadas de evento; suítes `tests/Feature/Billing` existentes continuam verdes (rodar antes/depois); nada de HTTP da Focus dentro do processamento do webhook (listener é fila).

---

## 7. Entrega ao cliente (item 7)

### 7.1 E-mail

- Na emissão, `tomador.email` = e-mail principal do snapshot (§1.5) e, se `nfse.copy_email` configurado, ele entra na lista de destinatários do envio pós-autorização (`POST /v2/{...}/{ref}/email`, até 10) — disparado pelo `NfseSyncService` quando a nota vira `autorizado` (uma vez; falha só log).
- Reenviar: `POST nfse/documents/{document}/resend-email` `{emails: [≤10]}` (Gate `SaasFinancial`, `manager-destructive`) — modal com pré-preenchimento (snapshot + contatos de cobrança). Só em `autorizado`; auditoria com motivo fixo.

### 7.2 Minha assinatura (clínica)

- `CheckoutService::summary()` envolve as linhas (assinatura, pacote de IA e histórico) com `'nfse' => NfseManagerPresenter::clinicRow($invoice)` — `null` sem nota visível; com nota: `{status, number, issued_at, pdf_url, xml_url, cancel_xml_url, mirror_url, cancelled}`. Só status `autorizado`/`cancelado` (pendente/erro não aparecem).
- Componente `resources/js/Components/Billing/NfseInvoiceLinks.vue`: "NFS-e nº X" + **PDF** (link direto `pdf_url`, `target=_blank`) + **XML** (rota autenticada) + XML do cancelamento quando cancelada; renderizado na lista de faturas de `Pages/Panel/MySubscription/Index.vue`.
- Download (nunca expõe token): `app/Http/Controllers/Billing/NfseDownloadController.php`, dentro do grupo `my-subscription` (`billing.contact`, `always allowed`), rotas `GET my-subscription/nfse/{document}/xml` → `panel.my-subscription.nfse.xml` e `GET my-subscription/nfse/{document}/cancel-xml` → `panel.my-subscription.nfse.cancel-xml`, `throttle:billing-checkout-read`. Isolamento: `abort_unless($document->entity_id === $this->entity($request)->id, 404)` (404, não 403). XML ausente no disco e nota autorizada → busca na Focus com o token do servidor, guarda e entrega (falha → 404). `Storage::disk()->download($path, "nfse-{numero|ref}.xml", ['Content-Type' => 'application/xml'])` (padrão `BillingController`). PDF é link direto (S3 público da Focus); o XML **nunca** sai por URL pública.

**Invariantes:** isolamento por `entity_id` conferido no controller (não só no escopo da query); token só no servidor; resposta com `Cache-Control: private`.

---

## 8. Arquivos/XML e PDF (item 8)

- `app/Services/Fiscal/NfseDocumentStorage.php` (disco padrão via `Storage::disk()`): `storeXml(NfseDocument, string $contents, string $kind /* authorized|cancel */)`, `contents(NfseDocument, string $kind): ?string`, caminho `nfse/{environment}/{Y}/{m}/{ref}.xml` e `.../{ref}-cancel.xml`. Guarda do XML de autorização e de cancelamento por 5 anos (runbook: backup do volume; sem rotina de expurgo).
- XML baixado da Focus com o token Basic pelo servidor (`caminho_xml_nota_fiscal`/`caminho_xml_cancelamento`), no apply do sync/cancelamento e lazy no download (§7.2).
- PDF: sem cache — `url_danfse` (S3 público) usado direto. Opcional futuro: baixar/cachear se o link expirar (não fazer agora).

---

## 9. Observabilidade e segurança (item 9)

- **Token nunca aparece em:** log (allowlist `ref/status/http/duracao/correlation_id` — padrão `AbstractHttpGateway`), JSON de resposta (só `has_token`/`has_master_token`), auditoria (`$auditExclude` + `new_values` sem segredo), payload de nota (`NfsePayloadSanitizer` antes de gravar), mensagens de erro (nunca ecoa o header Basic).
- **`correlation_id`**: `CorrelationIdService` na criação da nota e no log do cliente; webhook/consumo repetido mantém o da linha.
- **Auditoria admin**: `admin.audit` já cobre o grupo do Manager; `AuditLogger::recordAdminAction` nas ações manuais — settings (`manager.nfse.settings`, motivo fixo), credenciais (`manager.nfse.credentials`, `reason` ≥ 20), hooks (`manager.nfse.hooks.store|destroy`), IBGE da clínica, emitir/cancelar/reenviar/consultar/marcar externa (reason quando aplicável).
- **Throttles**: `manager-read`/`manager-destructive` nas rotas novas; `billing-checkout-read` nos downloads; `fiscal-webhook` (§4.1). **CSP intocada** (nenhum script/asset novo externo). `ShouldDispatchAfterCommit` no evento e `->afterCommit()` nos jobs de emissão.
- **LGPD**: payloads guardam dados contratuais da clínica (não de paciente); nunca logamos corpos; os snapshots são o registro legal da emissão.
- **Fila**: `config('fiscal.queue')` (`FISCAL_QUEUE` opcional); nada obrigatório novo no `.env` (flags de teste à parte — `FOCUSNFE_HOMOLOG_SMOKE`).

---

## 10. Textos e locale (item 10)

- `lang/pt_BR/fiscal.php` + `lang/en/fiscal.php` (novos): status/standard/environment, config do prestador, credenciais/hooks/município, tabela de notas/pendências, ações, erros traduzíveis (`fiscal.errors.*`: fatura_nao_paga, fatura_estornada, valor_zero, documento_invalido, endereco_obrigatorio, ibge_ausente, credencial_ausente, document_exists, sem_cancelamento), placeholders da discriminação, justificativa padrão de estorno.
- `lang/pt_BR/actions.php` + `lang/en/actions.php`: `actions.sidemenu.nfse` ("Nota fiscal (NFS-e)" / "Invoices (NFS-e)").
- `lang/pt_BR/checkout.php` + en: bloco da clínica (`nfse.title`, `nfse.number`, `nfse.pdf`, `nfse.xml`, `nfse.cancelled`, `nfse.download_error`).
- `lang/pt_BR/manager_subscriptions.php` + en: bloco NFS-e do drawer (título, botões, aviso de estorno parcial, erros).
- Datas e moeda pelos helpers existentes (`date()`, `money()` no Vue; `trans` + `:amount` no backend); **nenhuma string fixa em Vue**.

---

## 11. Testes (item 11)

Pest Feature (`tests/Feature/Fiscal/`, `Http::fake()` com as respostas documentadas):

| arquivo | prova |
|---|---|
| `NfseSettingsTest.php` | admin salva config (financial 403); token cifrado no banco (valor bruto ≠ enviado) e **nunca** devolvido (só `has_token`); `reason` ≥ 20 obrigatório; testar credencial ok/falha; registrar/remover hook (hook_id persistido/limpo); validar município (flags); IBGE da clínica validado/auditado |
| `NfseIssueTest.php` | listener com `auto_issue` on/off (Queue::fake); emissão manual; todas as guardas do §3.1 (422 traduzido); segunda emissão devolve/recusa a existente; índice único parcial barra concorrência; `ref` determinística e única; snapshot/amount congelados; `source` correto |
| `SendNfseJobTest.php` | 201 → processando (+numero_rps no payload salvo); 422 → erro_autorizacao + errors; 401 → send_failed; timeout/5xx → retry; header Basic com o token e **token ausente** do `request_payload` e dos logs |
| `NfseWebhookTest.php` | token desconhecido 404; header errado 401; ok → 200 + job; duplicado (mesmo `ref`/`status`) = no-op (sem 2ª mudança/auditoria); processando→autorizado grava numero/xml; cancelado grava cancel_xml; erro grava errors; `ref` desconhecida 200 + log |
| `NfseSyncCommandTest.php` | consulta notas processando antigas (não as novas); marca erro; re-dispara pending_send velho; respeita limites |
| `NfseCancelTest.php` | cancelar ok (status/justificativa/auditoria); `erro_cancelamento` exibido sem mudar status; `nfe_nao_autorizada` traduzido; município sem cancelamento → orientação + marcar externamente (reason ≥ 20, auditado); justificativa < 15 rejeitada |
| `NfseRefundTest.php` | estorno total dispara `InvoiceFullyReversed` e cancela (Queue::fake + aplicação); chargeback idem; duplicidade **não** cancela; parcial **não** cancela e expõe `partial_refund_warning`; fatura sem nota → no-op |
| `NfseDownloadTest.php` | clínica A baixa o XML da própria nota (conteúdo do disco); clínica B → 404; sem `billing.contact` → 403; XML ausente → busca na Focus e guarda; cancel-xml |
| `NfseManagerOpsTest.php` | `invoices()` do drawer e `show` do pacote trazem o bloco `nfse` + ações por status; gates (financial emite/reenvia; admin-panel cancela/altera credencial); página `manager.nfse.index` com props do contrato; menu |

Unit (`tests/Unit/Fiscal/`): `MunicipalNfsePayloadBuilderTest.php` e `NationalNfsePayloadBuilderTest.php` (fixture JSON igual ao exemplo oficial da Focus; campos obrigatórios, truncamentos, alíquota como fração, datas com fuso, opcionais ausentes, propriedades desconhecidas toleradas), `NfseDiscriminacaoTest.php` (placeholders + default), `NfsePayloadSanitizerTest.php` (remove chaves sensíveis). Vitest (`tests/JavaScript/Fiscal/`): `NfseSettingsForm.test.js` (submit, razão ≥ 20, token nunca pré-preenchido — campo vazio com "substituir"), `NfseDocumentsTable.test.js` (badges e botões por status, confirmação com motivo), `NfseInvoiceLinks.test.js` (nº/PDF/XML só com nota autorizada; cancelamento; ausência enquanto pendente). Regra do repo: nada de `--parallel`; banco só `easyeye_test`; rodar a suíte `tests/Feature/Billing` existente para provar zero regressão.

Integração opt-in: `tests/Integration/FocusNfeHomologSmokeTest.php` — `FOCUSNFE_HOMOLOG_SMOKE=1` + credencial `homologacao` lida do banco (padrão `AsaasSandboxSmokeTest`; `DB_DATABASE=<banco do app>`), trava dupla recusando produção (base URL homolog + ambiente `homologacao`); fluxo: builder real com tomador sintético (documento válido gerado) → `POST` → consulta até `autorizado` (timeout com relatório) → baixa XML → cancela → confere `caminho_xml_cancelamento`; e-mail só se `FOCUSNFE_SMOKE_EMAIL` informado; tudo pulado sem as travas, nada gravado no banco.

---

## 12. Docs (item 12)

- `docs/internal/runbooks/nfse-focusnfe.md` (novo): cadastro da empresa no painel da Focus (certificado A1, `habilita_nfse` xor `habilita_nfsen_producao`, série/próximo número, `dry_run`), tokens (empresa + master) e onde colar, webhook (registrar/remover, segredo/cabeçalho, retentativas 1 min→24 h, 2xx), homologação→produção (checklist: ambiente, token, re-registrar hook, standard, primeira nota manual), operação (emitir/reenviar/consultar/reemissão em lote/sync), cancelamento na prefeitura (`possui_cancelamento_nfse=false` → marcar externamente), retenção de XML 5 anos (backup do disco, caminho `storage/app/private/nfse/...`), troubleshooting (`401` token, `erro_validacao` campo a campo, `endereco_obrigatorio_nfse`, CNAE/CTISS/IBGE, `cpf_cnpj_obrigatorio_nfse`), segurança/LGPD. Deploy dentro do runbook: migrations (2 tabelas + coluna), scheduler (`fiscal:nfse-sync` 10 min, `fiscal:nfse-reissue` 05:10), fila (`FISCAL_QUEUE` opcional), seeder das settings, nenhuma env nova obrigatória.
- `docs/internal/manuais/manager-nfse.md` (novo): manual do Manager (configurar prestador, tokens, hook, validar município, IBGE das clínicas, emitir/reenviar/cancelar, faturas pagas sem nota).
- `docs/internal/glossario.md`: verbetes NFS-e/DPS/tomador/prestador/ref (opcional, 5 linhas).

---

## 13. Frentes, ordem de execução e contrato (item 3 do pedido)

### 13.1 Frente A — backend (Forja)

Ordem: **A1** migrations + enums + models + `config/fiscal.php` + `NfseSettings` + seeder → **A2** `FocusNfeClient`/`NfseCredentialResolver`/`NfseMunicipalityService`/`NfseDocumentStorage`/builders + Unit → **A3** `NfseIssueService` + `SendNfseJob` + listener `IssueNfseOnInvoicePaid` + `fiscal:nfse-reissue` + agenda → **A4** webhook + limiter + `ProcessNfseWebhookJob` + `NfseSyncService` + `fiscal:nfse-sync` → **A5** `NfseCancellationService` + evento `InvoiceFullyReversed` + 4 disparos + listener → **A6** controllers/rotas Manager + presenter + `invoices()`/`show` do pacote + `PanelNavigation` → **A7** download da clínica + `summary`. Feature tests junto de cada passo.

### 13.2 Frente B — frontend (Prisma)

Pode começar **em paralelo** logo após este contrato (não espera o backend inteiro: usa os props/payloads abaixo, com fixtures estáticas nos testes). Ordem: **B1** `lang/*/fiscal.php` + `actions.sidemenu.nfse` + chaves de `checkout.php`/`manager_subscriptions.php` (destrava A6) → **B2** página `Pages/Panel/Manager/Nfse/Index.vue` + `NfseSettingsForm.vue` + `NfseCredentialsCard.vue` + `NfseHookCard.vue` + `NfseMunicipalityCard.vue` + `NfseDocumentsTable.vue` (inclui "Faturas pagas sem nota" e "Clínicas sem IBGE") → **B3** blocos do drawer (`NfseBlock.vue` em `Pages/Panel/Manager/Subscriptions/` usado também no detalhe do pacote em `AiCreditPurchases`) + `Components/Billing/NfseInvoiceLinks.vue` em `MySubscription` → **B4** Vitest.

### 13.3 Contrato (para trabalharem em paralelo)

**Página `manager.nfse.index` (props Inertia)** — `settings{}` (todas as chaves do §2.1 com tipos já convertidos), `credentials[{environment, configured, has_token, has_master_token, hook:{registered, hook_id, event, url}, last_test:{at, ok, message}}]`, `hook_urls{homologacao, producao}`, `municipality{}` (`null` se não validado), `documents[]` (linhas abaixo, 20 últimas), `pending_invoices[] {id, reference, entity:{id,name}, amount, currency, paid_at, billing_reason, issue_url}`, `entities_missing_ibge[] {id, name, city, state}`, `permissions{can_settings, can_issue, can_cancel}`, `t` (`trans('fiscal')`).

**Linha de documento (Manager)**:
```json
{"id":"...","ref":"...","invoice_id":"...","invoice_reference":"...","entity":{"id":"...","name":"..."},
 "environment":"producao","standard":"municipal","status":"autorizado","status_label":"Autorizada","status_badge":"badge-soft-success",
 "numero":"123","codigo_verificacao":"...","amount":199.9,"currency":"BRL","issued_at":"2026-10-18T13:00:00-03:00","cancelled_at":null,
 "cancelled_externally":false,"cancel_required":false,"cancel_required_reason":null,"source":"auto","errors":null,
 "urls":{"xml":"...","cancel_xml":null,"pdf":"https://...","mirror":"https://..."},
 "actions":{"issue":"...","cancel":"...","mark_cancelled_externally":"...","resend_email":"...","sync":"..."}}
```
(`actions` só com as chaves permitidas pelo status + Gate.)

**Bloco nas faturas do Manager** (`manager.subscriptions.invoices`, `show` do pacote): `nfse` = a linha acima (sem `actions` duplicadas: mesmas chaves) + `partial_refund_warning: bool`.

**Bloco na clínica** (`summary`): `nfse = {status, number, issued_at, pdf_url, xml_url, cancel_xml_url, mirror_url, cancelled}` ou `null`.

**POSTs** (todas JSON, respostas `{message}` / `{document}` / `{ok:true}`): `nfse/settings.update{...}`, `nfse/credentials.store{token, master_token?, reason}`, `nfse/credentials.test{}`, `nfse/hooks.store{environment, reason}`, `nfse/hooks.destroy`, `nfse/municipality?ibge&refresh`, `nfse/entities.ibge-code{ibge_code}`, `nfse.documents.issue{}`, `nfse.documents.cancel{justification}`, `nfse.documents.mark-cancelled-externally{reason}`, `nfse.documents.resend-email{emails[]}`, `nfse.documents.sync{}`. Erros: sempre `{message}` + `{errors?: {campo: [..]} | [{codigo,mensagem}]}`; 401/403/404/422/429 conforme §9.

### 13.4 Fatiamento sugerido (commits, para quando o orquestrador autorizar)

1. `feat(fiscal)` — migrations + enums + models + config + settings/seeder;
2. `feat(fiscal)` — client + builders + storage + município (Unit junto);
3. `feat(fiscal)` — emissão (service + job + listener + comando + agenda);
4. `feat(fiscal)` — webhook + sync + limiter;
5. `feat(fiscal)` — cancelamento + estorno/chargeback (evento + listener);
6. `feat(fiscal)` — Manager (controllers/rotas/presenter/nav) + lang + página/componentes + Vitest;
7. `feat(fiscal)` — clínica (summary + download + componente);
8. `docs(fiscal)` — runbook + manual + glossário.

---

## 14. Riscos e invariantes (consolidado)

- **Billing intacto:** os fluxos de pagamento só ganham 4 chamadas de `event()` (ShouldDispatchAfterCommit) e o bloco `nfse` nos payloads; qualquer regressão aparece em `tests/Feature/Billing` (rodar antes/depois). O listener é de fila — Focus fora do ar não derruba webhook.
- **Locks/idempotência:** `FOR UPDATE` só na fatura, na criação da linha, sem HTTP dentro; envio/consulta não seguram a fatura; índice único parcial `nfse_documents (invoice_id) WHERE status IN (pending_send, processando_autorizacao, autorizado)` é o backstop; `ref` nasce uma vez e nunca é reutilizada depois de autorizada; reemissão = nova linha/ref; webhook idempotente por (`ref`,`status`); sync só pela linha.
- **Segredos:** token/master/segredo do hook/webhook_token cifrados, nunca em log/JSON/auditoria/payload; webhook exige segredo no cabeçalho (tempo constante) + 404 genérico; download da clínica é proxy no servidor.
- **Isolamento por entity:** downloads só da própria clínica (404), `billing.contact`; Manager atrás de `saas.admin`/Gates; nada de nota entre clínicas nos payloads.
- **ShouldDispatchAfterCommit / afterCommit** em evento e jobs; XML buscado fora da transação; `data_emissao` com fuso; propriedades desconhecidas da Focus toleradas.
- **Migrações aditivas** (2 tabelas + 1 coluna + 1 índice parcial); seeder roda uma vez; cache de settings (10 min) invalidado no save.
- **PII:** payloads guardam dados contratuais da clínica (não de paciente); corpos nunca vão para log.

---

## 15. Decisões em aberto

1. **Onde guardar o XML (item 8): disco padrão do projeto vs S3.** Recomendação: **disco padrão** (`Storage::disk()`, `storage/app/private/nfse/{env}/{Y}/{m}/...`), como os demais arquivos privados; o runbook exige backup do volume pela guarda de 5 anos. Trocar para S3 depois é trivial porque o caminho é por nota (muda só `NfseDocumentStorage`).
2. **`serie_dps`/`numero_dps` no padrão nacional:** a empresa na Focus tem "série/próximo número" no painel — presumo que a Focus preencha; se a validação exigir o envio, guardar `nfse.prestador.serie_dps` + contador em `system_settings` com `FOR UPDATE` na emissão. Confirmar (junto dos campos RT `codigo_nbs`/`codigo_indicador_operacao` e endereço/e-mail do tomador) em `campos.focusnfe.com.br` **antes de codar o builder nacional** — só isso fica bloqueado até a conferência; o resto do plano segue.
3. **IBGE do tomador:** digitação validada (7 dígitos conferidos em `GET /v2/municipios/{ibge}`) na tela "Clínicas sem IBGE" — recomendado, sem nova fonte externa. Alternativa se a Focus tiver busca por nome em `/v2/municipios` (conferir em `doc.focusnfe.com.br/llms.txt`): autocomplete na mesma tela.
4. **Tomador sem endereço quando o município NÃO exige** (`endereco_obrigatorio_nfse=false`): emitir sem os campos de endereço (recomendado — não travar faturamento; a nota é do plano) vs bloquear sempre até completar o cadastro.
5. **Pendência de cancelamento (estorno total sem cancelamento pelo município):** badge/lista na página NFS-e + `BillingLogService` (recomendado). Se o time preferir alerta na fila que já olha, avaliar `GatewayAlertService` na implementação (verificar se `kind`/`gateway` aceitam valor livre).
6. **Janela do lote de reemissão:** default 90 dias (`--since`); faturas mais antigas ficam fora (emissão automática só da entrada em produção pra frente). Ajustar o default antes de ligar o agendado se o dono quiser reprocessar histórico.
7. **E-mail de cópia (`nfse.copy_email`)**: destinatário fixo do dono do SaaS ou por ambiente? Recomendação: um campo único (global) como está no item 1; se quiser separar homolog/produção, vira uma chave por ambiente (mudança pequena).

---

## 16. Decisões do orquestrador (18/10/2026) — fecham a §15

1. XML no **disco padrão** (`Storage::disk()`), caminho por nota; runbook exige backup do volume.
2. Padrão nacional: `serie_dps`/`numero_dps` e campos do tomador conforme a lista completa de campos da Focus (`campos.focusnfe.com.br/nfse_nacional/EmissaoDPSXml.html`, cópia em texto no scratchpad da sessão do orquestrador: `/tmp/claude-1000/-home-higor-Projetos-MyStartup-easyeye/f9c06fd0-505f-4994-bbc7-0399b6f2d388/scratchpad/focusnfe-docs/campos_nfse_nacional_dps.txt`). Campos do tomador no nacional são PLANOS com sufixo `_tomador`: `cnpj_tomador|cpf_tomador`, `razao_social_tomador`, `codigo_municipio_tomador`, `cep_tomador`, `logradouro_tomador`, `numero_tomador`, `complemento_tomador`, `bairro_tomador`, `telefone_tomador`, `email_tomador`. CONFERIDO pelo orquestrador: `serie_dps` (inteiro 1..49999), `numero_dps` (inteiro até 15 dígitos), `data_competencia`, `emitente_dps`, `valor_servico` e `tributacao_iss` (1 tributável, 2 imunidade, 3 exportação, 4 não incidência) são **obrigatórios**. Logo: `nfse.prestador.serie_dps` (default 1) + contador `nfse.prestador.proximo_numero_dps` em `system_settings`, lido e incrementado sob `FOR UPDATE` na criação da linha (o número vai congelado na linha: coluna `dps_number` em `nfse_documents`); `data_emissao` em UTC ISO 8601; alíquota no nacional é `percentual_aliquota_relativa_municipio` em % (não fração) e retenção é `tipo_retencao_iss` (1 não retido, 2 retido pelo tomador).
3. IBGE do tomador: digitação validada em `GET /v2/municipios/{ibge}` **e** busca por nome/UF via `GET /v2/municipios?nome=&uf=` (endpoint `listar_municipios` existe) na mesma tela — `GET manager/nfse/municipalities?q=&uf=` (`manager-read`, token master; sem token master a busca fica desabilitada com aviso).
4. Município sem exigência de endereço: **emitir sem** os campos de endereço (não travar faturamento).
5. Pendência de cancelamento: badge + lista na página NFS-e + `BillingLogService`. Sem `GatewayAlertService`.
6. Lote de reemissão: default 90 dias.
7. E-mail de cópia: um campo global.

Branch: a implementação acontece no working tree atual (`desenv`); o orquestrador cria `feature/nfse-focusnfe` na hora do commit. Nenhum agente faz commit nem toca nos arquivos não versionados de outra sessão (`.agents/`, `.claude/skills/`, `docs/setup.md`, `docs/onboarding.md`, `docs/quality.md`, `eslint-rules/`, `AGENTS.md`, `CLAUDE.md`, `README.md`, `SPECS.md`, `.gitignore`, `package.json`, `eslint.config.js`).

## 17. Ajuste de contrato (orquestrador, 18/10/2026) — fatura paga SEM nota no Manager

`nfse` nas faturas do Manager (`SubscriptionsController::invoices()` e `show` do pacote de IA) nunca é `null` para fatura **paga**: sem nota ocupando, devolve `{"status": null, "status_label": <fiscal.status.none>, "actions": {"issue": "<url>"}}` quando o Gate `SaasFinancial` permite (senão `actions: {}`), para o drawer mostrar "Emitir NFS-e". Fatura não paga/estornada: `null` (sem bloco). O front monta `NfseBlock` sempre que `inv.nfse` não for `null` e trata `status === null` como "sem nota" (só o botão emitir, sem links). A clínica (`summary`) continua recebendo `null` sem nota autorizada/cancelada.
