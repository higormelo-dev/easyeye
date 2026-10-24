# Plano — Pacotes de IA em reais, markup 5x e pacote personalizado

Data: 2026-10-23 · Autor: Bússola (planejador) · Status: proposta, sem código alterado

Âncoras conferidas no checkout em 23/10/2026: `config/ai.php` (`usd_per_credit=0,01`, `margin_multiplier=2,0`, pacotes `starter/operational/scale`), `AiPricingService`, `AiRunEstimateService`, `AiCreditWalletService`, `AiCreditLedgerEntry`, `AiCreditPurchaseService`, `AiCreditPackCheckoutService`, `AiCreditPackPurchaseRequest`, `CheckoutController`, `NfseDiscriminacao`, `MySubscription`, `AiCreditPackCheckout.vue`, `Panel/AI`, `EyeImages`, `AiRunsController`, `AiUsageReportService`, `AiProviderCostService`, `AiUsdBrlRate`. O plano assume que a árvore pode ter alterações de outras frentes; nenhum arquivo de terceiro deve ser revertido.

## 0. Decisões do dono (bloqueiam ou mudam o desenho)

| Tema | Recomendação | Se não houver decisão |
|---|---|---|
| Câmbio | Manual no manager, com data/hora, autor e histórico. PTAX/BCB só com autorização explícita de novo serviço externo. | Usar a última recarga de provedor e, sem recarga, 5,50 sinalizado como estimativa. |
| Saldo comprado legado | Converter pelo **valor médio pago por crédito** da própria clínica; sem compra, usar R$ 2,00/crédito (decisão do dono). Preserva melhor o dinheiro pago. | Não migrar e manter créditos antigos em paralelo — não recomendado. |
| Cota mensal legada | Tratar 1 crédito antigo = R$ 1,00 e migrar 30/80 para R$ 30/R$ 80. | Congelar cota antiga e conceder a nova só no próximo ciclo. |
| Pacote personalizado | R$ 1.000,00 a R$ 10.000,00, em reais inteiros, acima do Enterprise. Limites configuráveis. | Sem teto é risco de fraude/limite de gateway; não recomendado. |
| Nomes | Básico (R$ 100), Premium (R$ 499, “MAIS ESCOLHIDO”), Enterprise (R$ 999), Personalizado. | Reutilizar `starter/operational/scale` confunde histórico. |
| Markup | 5,0, configurável no manager entre 1,0 e 20,0. Nunca visível à clínica. | Fixar em config e impedir edição. |
| Pisos | Piso global de R$ 0,10 por execução e pisos por workflow iguais a R$ 0,10; ajustáveis no manager. | Sem piso, chamadas curtas podem custar R$ 0,01 e corroer margem. |
| Pacote = saldo | R$ 100 pagos creditam R$ 100 de saldo, sem bônus. | Bônus exige regra comercial e contábil própria. |

**Fora de escopo:** PTAX automática, novo gateway, reemissão de NFS-e antiga, alteração de preços de planos além da cota de IA, limpeza destrutiva de colunas legadas e execução de migrations/seeds nesta etapa.

## 1. Modelo monetário e fórmula

- Unidade interna: **centavos de BRL** (`int`) em carteira, ledger, execuções e compras; a fatura continua com o `amount` decimal existente e é alimentada pelo `amount_cents` do pedido. Não usar float para saldo.
- Preço da execução:
  ```
  bruto_cents = round(raw_cost_usd × cambio_brl_usd × markup × 100, HALF_UP)
  preco_cents = max(1, piso_cents(workflow), bruto_cents)
  ```
- Estimativa normal usa o piso do workflow. Quando o modelo não tem preço cadastrado, o fallback atual continua: `piso_base × modo` (Economy 1, Validated 2, Consensus 3), sem custo USD.
- `normalized_charge_cents` por chamada de provedor é **diagnóstico** (arredonda para cima por chamada); a cobrança da execução usa a soma do custo real e a fórmula acima. Não somar os centavos por chamada para debitar a carteira.
- Cobrança real continua limitada à reserva: `consumido = min(real, reservado)`; o restante é liberado. Excedente acima da reserva não é cobrado nesta entrega (comportamento atual); registrar métrica/alerta.
- Markup e câmbio ficam **congelados na execução** (`ai_runs`) antes da reserva. Mudança no manager só afeta novas estimativas; execuções pendentes mantêm o snapshot.
- Arredondamento fiscal/visual é sempre `R$ 0,00`; a clínica vê o valor cobrado, nunca a conta interna.

### 1.1 Política de preço (novo DTO + resolver)

Criar `App\DTOs\AI\AiPricingPolicyData` (readonly) com:

```
exchangeRateBrlUsd: float
markupMultiplier: float
minimumChargeCentsDefault: int
minimumChargeCentsByWorkflow: array<string,int>
source: 'manual'|'legacy_topup'|'fallback'
effectiveAt: ?CarbonImmutable
exchangeRateId: ?string
```

Criar `AiPricingPolicyResolver`:

1. câmbio atual em `AiExchangeRateService::current()`;
2. markup e pisos em `AiPricingSettings` (DB `system_settings`, fallback config);
3. devolve o DTO. `AiPricingService` passa a receber o DTO em `estimateCredits`, `calculateActualCredits` e `normalizeChargeForResponse`; testes montam o DTO sem banco.

Config nova (`config/ai.php`), sem reutilizar a env antiga `AI_CREDIT_MARGIN_MULTIPLIER` (pode estar 2.0 em produção):

```
'markup_multiplier' => env('AI_MARKUP_MULTIPLIER', 5.0),
'minimum_charge_cents_default' => env('AI_MIN_CHARGE_CENTS_DEFAULT', 10),
'minimum_charge_cents_by_workflow' => [
  'exam_assistant' => env('AI_MIN_CHARGE_CENTS_EXAM_ASSISTANT', 10),
  'report_drafting' => env('AI_MIN_CHARGE_CENTS_REPORT_DRAFTING', 10),
  'consensus_review' => env('AI_MIN_CHARGE_CENTS_CONSENSUS_REVIEW', 10),
  'eye_image_analysis' => env('AI_MIN_CHARGE_CENTS_EYE_IMAGE', 10),
],
'custom_package' => ['min_cents' => 100000, 'max_cents' => 1000000, 'step_cents' => 100],
```

Precedência: `system_settings` > env/config. Chaves: `ai.pricing.markup_multiplier`, `ai.pricing.minimum_charge_cents_default`, `ai.pricing.minimum_charge_cents_by_workflow` (JSON), `ai.pricing.custom_min_cents`, `ai.pricing.custom_max_cents`. Validar markup `1.0..20.0`, pisos `1..10000`, limites do personalizado coerentes.

### 1.2 Câmbio manual e histórico

Nova tabela `ai_exchange_rates`:

```
id uuid pk
source varchar(20)           // manual|legacy_topup|ptax_bcb
rate_brl_per_usd decimal(12,6)
effective_at timestamp
recorded_by uuid null fk users
note varchar(255) null
timestamps
index(effective_at)
```

`AiExchangeRateService::current()`:

1. última linha com `effective_at <= now()`;
2. senão, última recarga (`ai_provider_topups`) como `legacy_topup`;
3. senão, 5,50 como `fallback`.

`AiUsdBrlRate` vira fachada para esse service, preservando `rate()`, `current()` e `FALLBACK` para P&L, catálogo e estimativas de medicamentos. Não alterar retroativamente execuções já precificadas.

Migração inicial: se existir recarga, criar uma linha `legacy_topup` com a taxa e a data; se não existir, **não** inventar linha manual. O manager mostra “defina a cotação” enquanto for fallback.

## 2. Dados: saldo em centavos e migração

### 2.1 Colunas novas (aditivas; colunas antigas ficam congeladas para auditoria)

`ai_credit_wallets`:

```
balance_cents, reserved_balance_cents,
lifetime_purchased_cents, lifetime_consumed_cents,
lifetime_consumed_openai_cents, lifetime_consumed_anthropic_cents, lifetime_consumed_gemini_cents,
monthly_quota_cents, monthly_quota_used_cents, monthly_quota_lifetime_granted_cents,
legacy_conversion jsonb null
```

`ai_credit_ledger_entries`:

```
amount_cents bigint null, balance_after_cents bigint null,
unit varchar(20) default 'legacy_credit'  // legacy_credit|brl_cent
```

`ai_runs`:

```
estimated_charge_cents, reserved_charge_cents, consumed_charge_cents (unsigned bigint default 0),
estimated_cost_usd decimal(14,8) null, actual_cost_usd decimal(14,8) null,
exchange_rate_brl_usd decimal(12,6) null, markup_multiplier decimal(6,3) null,
minimum_charge_cents unsigned int null, pricing_source varchar(20) null, priced_at timestamp null
```

`ai_run_provider_calls`: `normalized_charge_cents unsigned bigint null`.

`ai_credit_purchases`: `wallet_credit_cents bigint null`; tornar `credits` nullable para novas compras (histórico antigo preservado).

Não renomear as colunas antigas. O código novo lê/escreve só `*_cents`; qualquer leitor esquecido falha de forma visível nos testes em vez de tratar crédito como centavo.

### 2.2 Conversão dos saldos legados

Executar em migration de dados única, dentro de transação, com backup e janela de manutenção:

1. Por clínica, calcular:
   ```
   fator_compra = SUM(amount_cents de compras pagas que creditaram) / SUM(credits dessas compras)
   ```
   Se não houver compra, usar `200` centavos/crédito (decisão do dono). Guardar `fator_compra`, método e valores originais em `legacy_conversion`.
2. Carteira:
   - `balance_cents = round(balance × fator_compra)`;
   - `reserved_balance_cents = round(reserved_balance × fator_compra)`;
   - `lifetime_*_cents = round(campo × fator_compra)`, exceto cota;
   - cota: `monthly_quota_cents = monthly_quota × 100`, `monthly_quota_used_cents = monthly_quota_used × 100`, `monthly_quota_lifetime_granted_cents = ... × 100` (1 crédito = R$ 1,00).
3. Ledger antigo:
   - `amount_cents` por tipo: `purchase`/`consume`/`reserve`/`release`/`refund`/`adjustment` usam `amount × fator_compra`; `grant`/`expire` de cota usam `×100`; quando houver `metadata.from_quota` e `from_balance`, aplicar 100 e fator respectivamente.
   - `balance_after_cents` é aproximado (`balance_after × fator_compra` quando misto) e fica marcado `unit='legacy_credit'`; serve para auditoria/histórico, não para recálculo de saldo.
4. Compras antigas:
   - `credited`: `wallet_credit_cents = round(credits × fator_compra)`;
   - `pending`: `wallet_credit_cents = amount_cents` (se for paga depois, credita o valor pago);
   - `cancelled`/`failed`: 0; `refunded`: valor convertido que ainda corresponde ao crédito.
   - Novas compras: `wallet_credit_cents = amount_cents` (1:1).
5. Runs antigas: `estimated/reserved/consumed_charge_cents = round(créditos × fator_compra)`; `pricing_source='legacy_migration'`. O detalhe do manager marca “convertido”.
6. `ai_run_provider_calls.normalized_charge_cents`: custo bruto da chamada × câmbio histórico disponível × markup histórico (2,0 no legado); se não houver, `raw_cost_usd × 5,50 × 2,0 × 100`. É diagnóstico e não altera saldo.
7. Cota de plano: manter a chave `FeatureKey::AiMonthlyCredits` (nome legado), mas o valor passa a ser **centavos**. Migrar `plan_features.value × 100` (30 → 3000; 80 → 8000); atualizar `PlanSeeder`, labels, `PlanFeature::displayValue`, `AiCreditWalletService`, `AiPaywallService`, `PlansController`, `SiteController` e a UI de planos para formatar/validar R$. `UsageMeterService` passa a registrar centavos, se usado.

Critérios de reconciliação: nenhum saldo negativo; `balance_cents + reserved_balance_cents` não aumenta; soma do ledger novo fecha com a carteira; toda carteira tem `legacy_conversion`.

### 2.3 Deploy

- Janela de manutenção, backup, migrations e backfill antes de subir o código novo; drenar/parar workers e reiniciar após.
- Não rodar `migrate:fresh`, seeds ou PHPUnit `--parallel`. Teste de migration usa somente `easyeye_test`.
- Rollback de aplicação sem restaurar backup não é seguro: colunas novas e linhas em centavos não são lidas pelo código antigo. Rollback = restaurar backup + código anterior, ensaiado antes.

## 3. Pacotes, personalizado, checkout e NFS-e

### 3.1 Configuração

Substituir os pacotes atuais por:

| code | nome | valor | destaque |
|---|---|---:|---|
| `basic` | Básico | R$ 100,00 | — |
| `premium` | Premium | R$ 499,00 | “MAIS ESCOLHIDO” |
| `enterprise` | Enterprise | R$ 999,00 | — |
| `custom` | Personalizado | mínimo R$ 1.000,00 | — |

Cada pacote tem `value_cents`, `featured`, `custom` e textos pt_BR/en. Manter as traduções antigas `starter/operational/scale` para compras/faturas históricas. Rotas e classes `ai-credit*` continuam (compatibilidade); só a UI passa a falar “saldo de IA”.

### 3.2 Contrato do personalizado

- UI envia `package_code=custom` e `custom_amount_cents` inteiro, em reais inteiros (`step_cents=100`).
- `AiCreditPackPurchaseRequest`:
  ```
  package_code: required|in:basic,premium,enterprise,custom
  custom_amount_cents: required_if:package_code,custom|integer|multiple_of:100|between:{min},{max}
  ```
  `{min}`/`{max}` são lidos do service/config em tempo de validação (não fixar no FormRequest); o default recomendado é 100000/1000000 centavos. Campos de valor são proibidos para pacotes fixos. Limites vêm do servidor, não da UI.
- `AiCreditPurchaseService::resolvePackage(code, customCents)` recalcula o valor no servidor; nunca confiar em preço vindo do cliente.
- `AiCreditPackCheckoutService::purchase(..., ?int $customAmountCents)`: grava `amount_cents`, `wallet_credit_cents` e `metadata` da fatura com `package_code`, `wallet_credit_cents` e `package_name`.
- Idempotência e pedido aberto: incluir o valor na chave (`ai-pack:custom:{amount}:{method}`) e localizar pedido aberto por `package_code + amount_cents`. Não reutilizar um pedido personalizado de outro valor.
- `aiCreditOptions` aceita `custom_amount_cents`, valida e devolve formas de pagamento/valor; sem valor válido, não abre checkout.
- Fatura existente de pacote antigo continua pagável; ao confirmar, credita `wallet_credit_cents` já migrado. Não é preciso resolver o pacote antigo.
- NFS-e: `NfseDiscriminacao` ganha o placeholder `{valor}` formatado em R$; default passa a “Pacote de saldo de IA ({valor}) — fatura {fatura}”. `{creditos}` deixa de ser preenchido para novas notas (some do texto); revisar modelos customizados no manager. `invoiceExtra` devolve `value_cents`/`value_formatted`, não mais `credits`, com fallback de leitura para `metadata.credits` de faturas antigas.
- Reembolso/chargeback: revogar `wallet_credit_cents`; se já consumido, limitar ao saldo disponível e registrar insuficiência (comportamento atual de `revokePurchaseCredits`), sem saldo negativo.

### 3.3 Arquivos principais

Backend: `config/ai.php`, `AiCreditPurchaseService`, `AiCreditPackCheckoutService`, `CheckoutController`, `AiCreditPackPurchaseRequest`, `NfseDiscriminacao`, `NfseSettings`, `AiCreditPurchase`, `AiCreditWalletService`, migrations.

Frontend: `resources/js/Components/Billing/AiCreditPackCheckout.vue`, novo `AiCreditPackCard.vue`, `CheckoutFlow.vue`, `checkoutApi.js`, `MySubscription/Index.vue`, `Panel/AI/Index.vue`, `lang/pt_BR/ai.php`, `lang/en/ai.php`, `lang/pt_BR/checkout.php`, `lang/en/checkout.php`.

## 4. Manager: tela de precificação

Nova página **Manager → IA → Precificação**, somente dono/admin financeiro (`EntityGate::SaasOwnerFinancial`, o mesmo de Uso de IA). Não misturar com a tela de provedores, que tem outro público.

- Rota `GET /manager/ai-pricing` → `Panel/Manager/AiPricing/Index.vue`.
- `PUT /manager/ai-pricing/settings` (markup, pisos, limites do personalizado) com `reason` e auditoria.
- `POST /manager/ai-pricing/exchange-rates` (câmbio manual, data/hora, observação) com auditoria.
- `GET /manager/ai-pricing` devolve: câmbio atual (`rate`, `source`, `effective_at`, autor, `is_fallback`), markup, pisos, limites, preços dos pacotes (read-only), exemplo da conta e histórico paginado.
- Card “A conta”: `custo do provedor US$ 1,00 × câmbio × markup = R$ X`; tabela de exemplos. O detalhe de cada run em Uso de IA mostra custo USD, câmbio, markup, valor cobrado e margem.
- Ações exigem confirmação, `reason` mínimo e log `manager.ai_pricing.*`; histórico de câmbio é append-only (corrigir = novo registro).
- Navegação: incluir em `PanelNavigation` no grupo de IA com `route('manager.ai-pricing.index')`; atualizar `PanelNavigationTest` e `AiUsageControllerTest` para a nova rota/gate.
- `AiUsageReportService`/`AiProviderCostService`: receita = `SUM(ai_runs.consumed_charge_cents)/100`; custo BRL por run usa `raw_cost_usd × exchange_rate_brl_usd` congelado (fallback para a taxa atual em runs antigas); margem = receita − custo. Renomear KPIs “Créditos” para “Consumo (R$)” e remover a fórmula antiga `créditos × usd_per_credit × margin`.
- `AiUsageRunDrawer` e CSV do manager passam a exibir R$ e o snapshot de precificação; nada disso vai para rotas de clínica.
- Crédito manual/cortesia em `AiCreditPurchases`: campo em R$, grava `wallet_credit_cents`, exige motivo e auditoria; não aceitar mais “quantidade de créditos”. Histórico antigo mostra o valor pago convertido, com marca “legado”.

## 5. UI da clínica e auditoria de vazamento

### 5.1 Contrato público

A clínica recebe apenas:

- saldo disponível/reservado em centavos e formatado em R$;
- estimativa da execução em `charge_cents`/`charge_formatted` (sem `raw_cost_usd`, `cost_usd`, `margin_multiplier`, `usd_per_credit`, `exchange_rate`, `breakdown` de custo);
- valor cobrado no histórico/detalhe da execução;
- cota mensal em R$ (restante/total);
- pacotes com `value_cents` e `custom_min_cents`/`custom_max_cents`;
- mensagem de saldo insuficiente em R$ (`requested_cents`, `available_cents`, `missing_cents`; textos já formatados).

### 5.2 Superfícies a ajustar

- `AiRunsController::index` (saldo, cota, analytics, pacotes, compras), `estimate` (valor), `show`/detalhe (valor cobrado), `AiRunEstimateService::publicEstimate`.
- `EyeImagesController` (saldo/cota/estimativa) e `Panel/EyeImages` / `EyeImages` componentes.
- `MedicalRecordsController` (saldo/cota no prontuário) e componentes do prontuário.
- `HandleInertiaRequests` (`aiAssistant`) e `AiAssistantPanel` (saldo, paywall).
- `MySubscription/Index.vue`, `AiCreditPackCheckout.vue`, `CheckoutFlow.vue` e histórico de transações.
- `Panel/AI/Index.vue`: cards, medidor de cota, analytics por workflow/médico, custo médio por prontuário e tabela de runs em R$.
- `AiAnalyticsService`: `avg_credits`/`credits_total`/`avg_cost_per_record` passam a valores em centavos (nomes explícitos: `avg_charge_cents`, `charge_cents_total`, `avg_charge_cents_per_record`), com cache key versionada (`ai:analytics:v2:`).
- `AiPaywallService::shortMessage` e `InsufficientAiCreditsException`: formatar R$ com `Number::currency`, não contagem de créditos.
- `AiQuotaService::snapshot`: chaves `monthly_quota_cents`, `monthly_quota_used_cents`, `balance_cents`, `reserved_cents`.

### 5.3 Teste de não vazamento

Criar `tests/Feature/AI/AiPricingLeakageTest.php` com rotas de clínica (AI index/estimate/show, Eye Images, prontuário, MySubscription, widget) para perfis autorizados. Recursivamente, em JSON e props Inertia:

- falhar se aparecer chave proibida: `raw_cost_usd`, `cost_usd`, `cost_usd_with_margin`, `margin_multiplier`, `usd_per_credit`, `margin`, `gross_margin`, `exchange_rate`, `exchange_rate_brl_usd`, `provider_cost`, `revenue_estimate`, `pricing_source`;
- remover labels mortos `raw_cost_usd` dos controllers de clínica para o teste não dar falso positivo;
- garantir que manager (`/manager/ai-usage`, `/manager/ai-pricing`) continua podendo ver custo/câmbio/margem;
- um teste JS complementar verifica que os cartões e o histórico não renderizam “USD”, “custo do provedor” ou “crédito” na área da clínica (exceto texto histórico explicitamente legado).

## 6. UI dos pacotes (Prisma)

- `AiCreditPackCheckout.vue`:
  - `<fieldset>` + `<legend>Escolha o pacote</legend>`, 4 cartões com radio acessível; foco visível, `aria-describedby` para ajuda/erro, estados loading/disabled.
  - Básico/Premium/Enterprise mostram “R$ X em saldo”; Premium com selo “MAIS ESCOLHIDO”.
  - Personalizado mostra input `type=number` em R$, mínimo/máximo, `step=1`, erro inline e resumo “Você recebe R$ X em saldo”.
  - Botão “Comprar” envia `package_code` e `custom_amount_cents`; o checkout/modal existente é reutilizado.
  - Validação no cliente é só UX; erro do servidor é exibido no cartão e no modal.
- `MySubscription` mantém a seção e o histórico; textos trocam “créditos” por “saldo de IA” e valores em R$.
- `Panel/AI` e `EyeImages`: saldo/cota/estimativa em R$; sem menus ou tooltips com custo/margem.
- Traduções pt_BR/en com chaves novas; preservar chaves antigas para histórico. Revisar acessibilidade com teclado e leitor de tela.

## 7. Testes e critérios de aceite

### Pest (backend)

- `tests/Unit/AI/AiPricingServiceTest.php`: fórmula com câmbio/markup, HALF_UP, piso, fallback sem preço, snapshot de política, `normalized_charge_cents` por chamada não usado no saldo.
- `tests/Feature/AI/AiExchangeRateTest.php`: manual/histórico/autor, precedência, fallback 5,50, execução antiga não muda.
- `tests/Feature/AI/AiCreditWalletServiceTest.php`: saldo/reserva/consumo/liberação em centavos, cota, idempotência, refund sem negativo.
- `tests/Feature/AI/AiCreditSettlementTest.php` e `AiRunExecutionServiceFlowTest`: reserva/consumo com snapshot.
- `tests/Feature/AI/AiPricingMigrationTest.php`: backfill de carteira, ledger e compras; reconciliação; cota ×100; compra pendente antiga.
- `tests/Feature/Billing/AiCreditPackCheckoutTest.php`: 3 pacotes fixos, personalizado válido/ inválido (min/max/step), pedido aberto por valor, fatura, NFS-e, crédito 1:1, reembolso, código antigo pago após deploy.
- `tests/Feature/Manager/AiPricingControllerTest.php`: gate financeiro, salvar markup/câmbio, reason, auditoria, histórico, preview.
- `tests/Unit/Fiscal/NfseDiscriminacaoTest.php` e `tests/Feature/Fiscal/NfseIssueTest.php`: `{valor}` e pacote personalizado.
- `tests/Feature/AI/AiPricingLeakageTest.php` e atualização de `AiRunsControllerTest`, `AiUsageDashboardTest`, `Manager/AiUsageControllerTest`, `AiPaywallShortfallTest`.
- `tests/Feature/Manager/AiCreditPurchases*`: valores em R$, não créditos.

### Vitest (frontend)

- `AiCreditPackCheckout.test.js`: 4 cartões, seleção, personalizado, validação, envio de `custom_amount_cents`, erro, loading, bloqueio, acessibilidade (`fieldset/legend`, label, foco).
- `AiCreditPackPending.test.js`, `Panel/AI/AiUsageMeter.test.js`, `AiUsageIndex.test.js`: valores em R$, ausência de termos proibidos, histórico.
- Novo teste da tela de precificação do manager: markup/câmbio/histórico, confirmação e erro.

### Comandos (somente após implementação e autorização)

```bash
rtk proxy php artisan test tests/Unit/AI/AiPricingServiceTest.php
rtk proxy php artisan test tests/Feature/AI tests/Feature/Billing/AiCreditPackCheckoutTest.php
rtk npm test -- tests/JavaScript/Billing tests/JavaScript/Panel/AI
rtk proxy php vendor/bin/pint --test
```

Banco exclusivo `easyeye_test`, sem `--parallel`; não rodar migrations/seeds como preflight. Registrar comando, resultado e limitações. Falha legada não pode ser escondida.

### Aceite

- Pacotes Básico/Premium/Enterprise/Personalizado cobram exatamente o valor exibido; personalizado é validado no servidor e gera fatura/NFS-e corretas.
- Toda operação de IA é estimada, reservada e liquidada em centavos; câmbio/markup ficam congelados na run.
- Saldos legados migrados sem negativo e com trilha de conversão auditável; pedidos antigos continuam pagáveis.
- Manager vê custo, câmbio, markup, a conta e a margem; clínica vê somente R$, saldo, estimativa e histórico.
- Teste de vazamento falha se qualquer chave proibida aparecer em rota de clínica.
- Nenhum serviço externo novo, segredo, dado real ou commit sem “commit autorizado”.

## 8. Riscos e mitigação

| Risco | Mitigação |
|---|---|
| Erro de unidade (crédito × centavo) em produção | Colunas `_cents` explícitas, proibir leitura das antigas, testes de reconciliação e revisão independente. |
| Migração irreversível | Backup, ensaio em `easyeye_test`, `legacy_conversion`, janela de manutenção e rollback por restauração. |
| Câmbio manual esquecido/errado | Banner de fallback, histórico com autor, preview da conta e alerta no manager. PTAX só após autorização. |
| Vazamento de custo/margem | Teste recursivo por rota, gate `SaasOwnerFinancial`, remoção de labels mortos e revisão Lupa/Sentinela. |
| Personalizado abusivo/caro | Limites server-side, passo em reais, confirmação, limite de gateway e idempotência por valor. |
| NFS-e com texto antigo de créditos | Novo placeholder `{valor}`, migrar default, revisar modelos customizados em homologação. |
| Reembolso após consumo | Revogação limitada ao saldo, registro de insuficiência, sem saldo negativo. Para compras antigas, a carteira não distingue lotes: documentar que o valor convertido pode atingir saldo de outra origem e registrar shortfall. |
| Execuções pendentes no deploy | Drenar fila, snapshot obrigatório; runs sem snapshot usam política atual e persistem antes de reservar. |
| Arredondamento acumulado | Cobrança por execução com HALF_UP; por chamada é diagnóstico; testes de borda (0,5 centavo, 1 centavo). |
| Cache antigo de analytics | Versionar chave e limpar cache no deploy. |

## 9. Ordem de execução e divisão

1. **Decisões do dono** (tabela 0) e backup; sem elas, não iniciar a migration.
2. **Forja**: câmbio/histórico, policy/DTO/settings, fórmula e testes unitários.
3. **Forja**: migrations + backfill + carteira/ledger/runs/compras em centavos; reconciliação.
4. **Forja**: checkout/pacotes/personalizado/NFS-e + manager `AiPricing` + relatórios.
5. **Prisma**: cartões, personalizado, MySubscription, Panel/AI, EyeImages, widget e testes Vitest.
6. **Lupa**: auditoria de vazamento e revisão de segurança somente leitura.
7. **Sentinela**: regressão Pest/Vitest, Pint, build e evidências.
8. **Orquestrador**: revisão do diff/chamadores e, só com “commit autorizado”, commit.

**Arquivos novos previstos:** `app/DTOs/AI/AiPricingPolicyData.php`, `app/Domains/AI/Services/AiPricingPolicyResolver.php`, `AiExchangeRateService.php`, `AiPricingSettings.php`, `app/Domains/AI/Models/AiExchangeRate.php`, `app/Enums/AI/AiExchangeRateSource.php`, `app/Http/Controllers/Manager/AiPricingController.php`, requests do manager, `resources/js/Pages/Panel/Manager/AiPricing/Index.vue`, `resources/js/Components/Billing/AiCreditPackCard.vue`, migrations de câmbio/centavos/snapshot/feature e testes listados.

**Dependências entre etapas:** 2 antes de 3; 3 antes de 4; 4 e 5 podem correr em paralelo somente depois dos contratos de payload fechados; 6/7 depois de ambos; nenhum commit antes da revisão.

## 10. Pendências que viram decisão registrada

- Autorizar ou não PTAX/BCB como serviço externo (hoje: não).
- Aprovar o fator de conversão de compras sem histórico (recomendado R$ 2,00/crédito) e a cota 1 crédito = R$ 1,00.
- Aprovar faixa do personalizado (R$ 1.000–R$ 10.000) e passo de R$ 1.
- Aprovar pisos iniciais de R$ 0,10 e o markup 5,0 configurável.
- Confirmar nomes/copy e selo “MAIS ESCOLHIDO” no Premium.
- Definir se o excedente acima da reserva continuará não cobrado (recomendado manter nesta entrega e medir).
