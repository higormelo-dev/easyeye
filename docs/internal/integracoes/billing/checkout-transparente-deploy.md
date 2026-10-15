# Checkout transparente — configuração obrigatória e deploy das correções da revisão

## Banco e agendador

- `php artisan migrate` — `2026_10_05_100000_add_first_charge_grace_to_subscriptions_table` (D5 vale uma vez). Linhas já existentes ficam com a regra anterior (coluna nula).
- Agendador: nova tarefa `subscriptions:plan-changes` (00:15) aplica os downgrades agendados na data. Ela já está em `routes/console.php`; o cron do `schedule:run` existente basta.
- Fila: `RecreateGatewayRecurrenceJob` (troca de plano numa assinatura com recorrência no Asaas) roda na fila `billing.webhooks.queue`.

## Mercado Pago — parcelado "sem juros" (obrigatório)

A API de pagamentos não tem campo para "sem juros": quem decide é a **conta**. Sem a configuração, o cliente paga os juros do parcelamento e o EasyEye promete o contrário.

1. No painel do Mercado Pago: **Seu negócio → Custos → QR Code e pagamentos online** → ativar o **parcelamento sem acréscimo** e definir o máximo de parcelas (2x a 12x) igual ou maior que o configurado no manager (Planos → parcelas do checkout); conferir também o valor mínimo de compra do parcelamento sem juros. O Checkout Transparente usa essa configuração da conta.
2. Conferência automática: todo pagamento aprovado com `transaction_details.total_paid_amount` acima de `transaction_amount` gera um **log crítico** em `billing_logs` ("Mercado Pago cobrou juros do cliente…") com a diferença — devolver ao cliente e corrigir a conta.

Docs: <https://www.mercadopago.com.br/blog/guia-completo-parcelamento-sem-juros>, <https://www.mercadopago.com.br/developers/pt/docs/iset/set-interestfree-installments> e o pagamento (`transaction_details.total_paid_amount`) em <https://www.mercadopago.com.br/developers/pt/reference/online-payments/checkout-api-payments/get-payment/get>.

Renovação no cartão (Automatic Payments) depende de liberação comercial do Mercado Pago.

## Renovação do anual parcelado

A renovação no cartão salvo é **à vista** em todos os gateways: o Pagar.me exige 1 parcela em recorrência (<https://docs.pagar.me/reference/cart%C3%A3o-de-cr%C3%A9dito-1>) e Mercado Pago/PagBank só documentam 1 parcela na cobrança iniciada pelo lojista. A tela de contratação, "Minha assinatura" e o lembrete da régua avisam o cliente.

## Chave pública do SDK (manager → Gateways → credencial)

Sem a chave pública o cartão não é transparente: vai pelo link do gateway (InfinitePay, Stripe) ou some das formas de pagamento (Mercado Pago, Pagar.me). No **Asaas** o cartão é sempre pelo **Asaas Checkout** (página hospedada, com volta para Minha assinatura) — ver [asaas-configuracao.md](asaas-configuracao.md). Formatos aceitos:

| Gateway | Chave pública |
|---|---|
| Mercado Pago | `APP_USR-` ou `TEST-` + UUID (o access token também começa com `APP_USR-`, mas não é UUID) |
| Pagar.me | `pk_…` / `pk_test_…` — e liberar o domínio do site no painel do Pagar.me (exigência do tokenizecard.js) |
| Stripe | `pk_live_…` / `pk_test_…` |
| PagBank | chave RSA (PEM ou o texto `MII…`); opcional — sem ela, o sistema obtém pela API |

A chave secreta (`sk_`, `rk_`, access token) e uma chave igual ao secret são recusadas.

## Antifraude do cadastro

- `POST /register`: até `BILLING_REGISTER_PER_IP_HOUR` (10) por hora e `BILLING_REGISTER_PER_IP_DAY` (30) por dia por IP.
- Recusas de cartão (cadastro e painel): `BILLING_CHECKOUT_DECLINES_PER_IP_HOUR` (3), `…_PER_IP_DAY` (6), `…_PER_ENTITY_DAY` (5) e globais por hora `BILLING_CHECKOUT_SIGNUP_DECLINES_GLOBAL_HOUR` (20) / `BILLING_CHECKOUT_PANEL_DECLINES_GLOBAL_HOUR` (60). Estourou: cartão indisponível por algumas horas (Pix/boleto seguem).
- Cadastro: depois de `BILLING_CHECKOUT_SIGNUP_UNVERIFIED_DECLINES` (1) recusa, o cartão exige o e-mail confirmado.
- Atrás de proxy/CDN, configurar `TrustProxies` para o IP real do cliente chegar ao Laravel (senão todos os limites por IP viram um só).

### Cloudflare Turnstile (opcional)

Criar o widget no painel da Cloudflare (Turnstile → Add widget, domínio do site) e preencher **as duas** chaves no `.env`:

```
TURNSTILE_SITE_KEY=0x4AAAAAAA…
TURNSTILE_SECRET_KEY=0x4AAAAAAA…
```

Sem as duas, o captcha fica desligado. Validação no servidor por `siteverify` (<https://developers.cloudflare.com/turnstile/get-started/server-side-validation/>). `php artisan config:cache` depois de mudar o `.env`.

## Content-Security-Policy

Aplicada só em **Minha assinatura**, **/subscription/expired** e **/register** (`checkout.csp`). `BILLING_CHECKOUT_CSP=enforce|report-only|off` — na primeira subida, se quiser observar antes, usar `report-only` e olhar o console do navegador. Inclui os SDKs (Mercado Pago, Pagar.me, Stripe, PagBank), o Turnstile, o Reverb (`REVERB_HOST/PORT/SCHEME`, como o navegador acessa) e, em `npm run dev`, o servidor do Vite.

## Troca de plano

- Upgrade: fatura `plan_change` com a diferença proporcional; o plano muda quando ela é paga. Não paga, nada muda.
- Downgrade: agendado para o fim do período pago (`subscriptions.gateway_payload.scheduled_change`); a cobrança daquele vencimento já sai no novo valor.
- Asaas (recorrência própria): a recorrência é refeita nos termos novos (criada a nova, cancelada a antiga). Falha final → log crítico para cancelar à mão.

## Rodada 4 — créditos de IA, clínica bloqueada, troca pelo manager, webhooks sem dado pessoal

### Comandos do deploy (nesta ordem)

```bash
php artisan migrate                                     # 2026_10_06_000000_add_ai_credit_pack_checkout
php artisan billing:sanitize-webhook-events --dry-run   # quantos webhooks guardados têm dado pessoal
php artisan billing:sanitize-webhook-events             # one-off: limpa os já gravados (idempotente)
php artisan config:cache && php artisan route:cache
php artisan queue:restart                               # jobs do WhatsApp/billing com o código novo
npm run build
```

A migration deixa `subscription_id` **nulo** em `invoices`, `payments` e `payment_attempts` (só para as faturas de pacote de IA, que não pertencem a nenhuma assinatura) e cria `ai_credit_purchases.invoice_id`. O `down` volta a exigir a assinatura — só funciona se não houver fatura de pacote gravada.

### Variáveis novas (`.env`)

| Variável | Padrão | Para quê |
|---|---|---|
| `BILLING_WEBHOOK_RETENTION_DAYS` | `90` | `billing:prune-webhook-events` apaga os webhooks **processados** há mais que isso (com falha/não processados ficam). |

`BILLING_ENFORCE_SUBSCRIPTION_ACCESS=false` (chave de emergência) também desliga tudo da clínica bloqueada abaixo. `AI_CREDIT_PURCHASES_AUTO_CREDIT` foi **removida** (ver "Correções da revisão" abaixo).

### Agendador novo

- `billing:prune-webhook-events` — diário às 03:50 (`--days=N` e `--dry-run` disponíveis para rodar à mão).

### Pacotes de créditos de IA no checkout

- Compra em **IA → Uso e créditos** (admin/dono) e em **Minha assinatura → Créditos de IA** (admin, financeiro, dono). Só com o acesso **total**; cortesia pode comprar.
- Rotas: `GET/POST /panel/my-subscription/ai-credits` (mesmos limites `billing-checkout-read`/`billing-checkout-pay` e o mesmo antifraude de cartão). Pix/boleto/cartão (à vista, sem guardar o cartão) pelo gateway da assinatura cobrada; sem ela (cortesia), o padrão do SaaS.
- Fatura própria (`billing_reason = ai_credit_pack`, referência `IA-…`, sem assinatura) que aparece no histórico de Minha assinatura. Paga (cartão aprovado ou webhook) → créditos na carteira uma vez só (saldo comprado, não expira). Estorno/chargeback no gateway → mesma regra do estorno do manager.
- Manager → Compras de créditos continua para pedidos antigos e cortesia. Pedido do checkout cancelado pelo manager que depois é pago no gateway é **reaberto e creditado** (fica `metadata.reopened_by_payment`).

### Clínica com acesso bloqueado (nível `none`) fora do painel

- WhatsApp automático (confirmação, pesquisa, resposta automática — inclusive pela instância global): pulado com o motivo (`clinic_access_blocked`): o comando não enfileira e a mensagem que já estava na fila fica `skipped`. Volta sozinho quando o acesso volta (a linha pulada é reaproveitada). Acesso limitado continua enviando.
- TV de chamada: "Serviço indisponível" e feed sem chamadas; a TV volta sozinha.
- Portal do paciente: só leitura (documentos e "Baixar meus dados" seguem); qualquer escrita no portal recusa com mensagem neutra (middleware `patient.read-only`). O aceite de convite continua liberado (só dá acesso de leitura).
- API de integradores: já recusava (403) sem assinatura com acesso — mantido.

### Troca de plano pelo manager

- Manager → Assinaturas → Nova assinatura → Cobrança automática numa clínica com plano **pago e vigente**: vira troca de plano (prévia antes de confirmar). Upgrade gera a fatura da diferença proporcional (a clínica paga em Minha assinatura); downgrade fica agendado para o fim do período. Cortesia e trial seguem substituindo como antes.
- Mudança agendada pode ser desfeita no detalhe da assinatura (com justificativa) antes da data — `POST /panel/manager/subscriptions/{id}/scheduled-change/cancel`.

### Webhooks (`webhook_events.payload`)

- Gravado sem dado pessoal do pagador (nome, CPF/CNPJ, e-mail, telefone, endereço, IP, cartão) — `PayloadSanitizer::cleanPersonal`. A assinatura (HMAC/token/Basic) é conferida antes, no corpo bruto em memória; `event_hash` e a chave de idempotência também saem do corpo bruto.

### Correções da revisão (rodada 4)

**Comandos do deploy** (além dos de cima):

```bash
php artisan billing:sanitize-webhook-events --dry-run   # agora conta também os eventos com headers secretos
php artisan billing:sanitize-webhook-events             # limpa payload E headers já gravados (idempotente)
php artisan ai:expire-credit-pack-orders --dry-run      # quantos pedidos de créditos abandonados seriam descartados
php artisan route:cache && php artisan config:cache
```

**Trocar as credenciais de webhook depois do deploy (obrigatório).** Até esta rodada, `webhook_events.headers` guardava os headers brutos: o **Basic Auth do webhook do Pagar.me** (usuário:senha), o **`asaas-access-token`** do Asaas e cookies vazaram para o banco (e para qualquer backup/dump dele). Depois de rodar `billing:sanitize-webhook-events`:

1. Pagar.me — gerar novo usuário/senha do webhook no painel e atualizar a credencial em Manager → Gateways (e o `.env`, se usado).
2. Asaas — gerar novo token de autenticação do webhook no painel e atualizar em Manager → Gateways (e o `.env`, se usado).
3. Conferir um webhook de teste de cada um (Manager → Gateways / logs de billing).

A lista de headers que nunca são gravados é uma só (`PayloadSanitizer::storableHeaders`): a ingestão e o comando usam a mesma.

**Variável nova (`.env`)**

| Variável | Padrão | Para quê |
|---|---|---|
| `AI_CREDIT_PACK_PENDING_EXPIRY_DAYS` | `7` | Pedido de pacote de créditos de IA pendente, sem pagamento nem nova cobrança há mais que isso, é descartado por `ai:expire-credit-pack-orders`. |

`AI_CREDIT_PURCHASES_AUTO_CREDIT` (e `ai.credit_purchases.auto_credit_without_gateway`) foi **removida**: pode sair do `.env` — nada mais a lê.

**Agendador novo**

- `ai:expire-credit-pack-orders` — diário às 04:10 (`--dry-run` para conferir à mão). Cancela pedido + fatura e as cobranças (Pix/boleto) no gateway quando possível; a que não cancelar fica no log de billing e, se o cliente pagar mesmo assim, **credita** (o dinheiro entrou). Pedidos antigos sem fatura (fluxo manual do manager) não são tocados.

**Rota removida**

- `POST /panel/ai/credit-purchases` (`panel.ai-credit-purchases.store`) — criava pedido fora do checkout sem a regra de acesso total (e, com a variável removida acima, creditava sem pagamento). A compra é só pelo checkout (`/panel/my-subscription/ai-credits`). O fluxo manual do manager (Compras de créditos) continua.

**Rota nova**

- `DELETE /panel/my-subscription/ai-credits/{invoice}` (`panel.my-subscription.ai-credits.discard`, contatos de cobrança, só da própria clínica, só pedido não pago): "Descartar" o pedido em Minha assinatura. Em Minha assinatura os pedidos de créditos não pagos aparecem à parte ("Pedidos de créditos de IA aguardando pagamento", `open_ai_packs`) — não como fatura vencida da assinatura.

**Comportamento corrigido (sem ação no deploy)**

- Estorno/chargeback da cobrança que **quitou** a fatura (pacote ou assinatura) sempre reverte, mesmo que ela tenha sido desligada pelo checkout (Pix pago depois de trocar para boleto).
- Estorno/chargeback de pagamento em **duplicidade** (a fatura já tinha sido quitada por outra cobrança) só registra esse pagamento: créditos, pedido, fatura e assinatura ficam (nada de atraso).
- Reentrega de estorno/chargeback depois da retenção de `webhook_events` não duplica evento financeiro, log nem atraso.
- Cartão aprovado cujo webhook confirmou antes da resposta do gateway não gera mais o alerta crítico "estornar"; na assinatura, o cartão aprovado vira o da renovação.
- Manager → Nova assinatura (cobrança automática) não envia enquanto a prévia da troca não carregou ou falhou ("Tentar de novo"); na troca o gateway é o da assinatura paga — outro gateway é recusado com 422.

## Rodada 5 — recorrência desativada pelo gateway, CSP na tela de IA, cobrança enviada pelo manager, avisos à clínica

### Comandos do deploy (nesta ordem)

```bash
php artisan migrate                       # 2026_10_07_000000 (subscriptions.recurrence_alert_at) e 2026_10_07_000100 (subscription_trial_notices)
php artisan config:cache && php artisan route:cache
php artisan queue:restart                 # jobs novos: SendSaasWhatsAppNoticeJob (avisos pelo WhatsApp)
npm run build
php artisan billing:trial-notices --dry-run   # quantos avisos de fim de teste sairiam hoje
```

### Variáveis novas (`.env`)

| Variável | Padrão | Para quê |
|---|---|---|
| `BILLING_TRIAL_NOTICES_ENABLED` | `true` | Avisos de fim do teste grátis (`billing:trial-notices`). Chave própria — **não** depende de `BILLING_DUNNING_ENABLED`. |
| `BILLING_NOTICES_WHATSAPP_ENABLED` | `true` | Avisos do SaaS à clínica (régua, fim do trial, cobrança enviada pelo manager) também pelo WhatsApp. `false` = só e-mail. |
| `BILLING_NOTICES_WHATSAPP_START` / `_END` | `08:00` / `20:00` | Janela (fuso `APP_TIMEZONE`) do WhatsApp — a mesma das confirmações aos pacientes. Fora dela a mensagem espera o próximo início. |
| `BILLING_CHARGE_NOTICE_COOLDOWN_MINUTES` | `10` | "Enviar cobrança à clínica": no máximo um envio por fatura nesse intervalo (429). |

### Agendador novo

- `billing:trial-notices` — diário às 09:10 (`--dry-run` para conferir à mão). Já está em `routes/console.php`.

### WhatsApp do SaaS para a clínica (obrigatório para os avisos pelo WhatsApp)

- Sai pelo **app GLOBAL do EasyEye na Gupshup** (Manager → WhatsApp, "Número do EasyEye" — o mesmo do código de verificação do cadastro) com `WHATSAPP_DRIVER=gupshup` e as credenciais `GUPSHUP_*` no `.env` (passo a passo em `docs/integracoes/whatsapp-gupshup.md`). **Nunca** pelo número da clínica, e o `ClinicServiceGate` não barra estes avisos (clínica bloqueada continua recebendo a régua).
- Cada etapa é um **template aprovado na Meta** (`easyeye_cobranca_*`, `easyeye_teste_termina_*`, `easyeye_cobranca_enviada`, `easyeye_cobranca_troca_plano` — lista e texto em `docs/integracoes/whatsapp-gupshup.md` §6). Template não aprovado = falha permanente registrada em `whatsapp_messages` (o e-mail segue).
- Destinatário: contato de cobrança (admin, financeiro, dono) com **WhatsApp verificado** (`users.phone_verified_at`) e que não respondeu SAIR ao número do EasyEye. Sem número verificado → só e-mail (fica no log `[whatsapp:saas-notice]`).
- Fila `WHATSAPP_QUEUE`, até 3 tentativas só para falha transitória (1 min, 10 min); falha final registrada em `whatsapp_messages` (tipo `saas_notice`) — o e-mail já saiu por outro job e a régua segue.
- Sem dado de paciente, com o link **dentro do sistema** no botão do template (`/panel/my-subscription`, com `?invoice=<id>` quando há fatura a pagar). O link do gateway nunca vai no WhatsApp.

### Asaas — eventos de webhook a assinar no painel

No painel do Asaas: **Integrações → Webhooks → (webhook do EasyEye) → Eventos**, marcar:

- **Cobranças:** todos os `PAYMENT_*` (já usados desde a régua).
- **Assinaturas:** `SUBSCRIPTION_INACTIVATED` e `SUBSCRIPTION_DELETED` (obrigatórios nesta rodada). `SUBSCRIPTION_CREATED`/`SUBSCRIPTION_UPDATED`/`SUBSCRIPTION_SPLIT_*` podem ficar marcados — só são registrados.

Doc: <https://docs.asaas.com/docs/eventos-para-assinaturas>.

### Recorrência desativada pelo gateway (decisão: avisar o manager + régua)

- `SUBSCRIPTION_INACTIVATED`/`SUBSCRIPTION_DELETED` do Asaas (e o equivalente dos outros: `customer.subscription.deleted` do Stripe, preapproval cancelado do Mercado Pago) **não** cancelam nem bloqueiam mais a assinatura. Quem pagou segue com acesso até o fim do período pago.
- A cobrança passa para a **renovação local** (`gateway_subscription_id` fica nulo): a próxima fatura sai do `subscriptions:renew` (01:00, alguns dias antes do vencimento) e a clínica paga em Minha assinatura (Pix/boleto/cartão; no Asaas o cartão é o Asaas Checkout). Sem pagamento, régua normal (D-5, D+1, D+3, D+7).
- Alerta: badge **"Recorrência desativada"** em Manager → Assinaturas (filtro e card de resumo próprios, aviso no detalhe com "Marcar como visto"), log de billing (warning) e e-mail para os usuários **admin, financeiro e dono** da empresa do SaaS (com e-mail confirmado). Uma vez por recorrência (o Asaas manda INACTIVATED e depois DELETED; reentregas não duplicam). Histórico: `SubscriptionChange` `gateway_recurrence_lost`.
- Sem alerta (só o log do webhook): assinatura que não é a vigente (substituída, cancelada, cortesia), recorrência desconhecida e recorrência que o **próprio sistema** cancelou (troca, cortesia, encerramento, recorrência refeita — gravado em `gateway_payload.recurrences_cancelled_by_us` antes da chamada).

### Content-Security-Policy também na tela de IA

- `checkout.csp` agora também em **IA → Uso e créditos** (`/panel/ai/usage`), onde o pacote de créditos é pago com o SDK de cartão.
- O painel é SPA: a CSP do documento continua valendo enquanto o usuário navega dessas telas para o resto do painel. Por isso a política libera: storage (`filesystems.disks.public.url`, `AWS_URL`, `AWS_ENDPOINT` + subdomínio do bucket, ou o host do bucket na AWS) em `img/media/frame/connect-src`; `blob:` (prévias, PDF, montagem) em `img/media/frame-src` e workers; `https://viacep.com.br` (CEP → endereço); Reverb pelo `REVERB_HOST` (sem ele, o host do próprio site na `REVERB_PORT`). `script-src` continua só com o app e os SDKs oficiais.
- Mudou o storage (bucket, `AWS_URL`, endpoint do MinIO) → `php artisan config:cache`. Na validação, se aparecer violação de CSP no console, use `BILLING_CHECKOUT_CSP=report-only` até ajustar.

### "Enviar cobrança à clínica" (manager)

- Manager → Assinaturas → detalhe: **Fatura em aberto** (a diferença do upgrade primeiro) e cada fatura em aberto na aba Faturas têm o botão. No modal de Nova assinatura, o upgrade oferece "Enviar a cobrança à clínica agora" (marcado por padrão).
- E-mail + WhatsApp aos contatos de cobrança com o link `/panel/my-subscription?invoice=<id>` (pagar dentro do sistema). Mensagem: plano, valor e vencimento.
- Fatura paga/cancelada/substituída → 409; um envio por fatura a cada `BILLING_CHARGE_NOTICE_COOLDOWN_MINUTES` → 429. Auditoria: `SubscriptionChange` `charge_notice_sent` (quem, quando, canais, quantos contatos) + `audit_logs` `manager.subscription.charge_notice`.
- Rota: `POST /panel/manager/subscriptions/{subscription}/invoices/{invoice}/send-charge` (admin/financeiro do SaaS).

### Fim do teste grátis

- E-mail + WhatsApp aos contatos de cobrança 3 dias antes, 1 dia antes e no dia do fim do trial, com o botão **Contratar agora** (Minha assinatura). Não sai para quem contratou, cortesia ou trial já vencido; trial estendido recalcula pela nova data. Registro em `subscription_trial_notices` (único por assinatura, passo e data de fim).

### Tela de IA — pedido de créditos pendente

- IA → Uso e créditos mostra o pedido de créditos ainda não pago com **Continuar pagamento** (abre o checkout da fatura do pedido) e **Descartar** (confirmação; mesma rota `DELETE /panel/my-subscription/ai-credits/{invoice}`).

## Gateways só do dono do SaaS (sem gateway por clínica)

Os gateways de Manager → Gateways são da empresa dona do EasyEye e servem só para cobrar as clínicas (assinatura e pacotes de créditos de IA). Clínica não tem gateway próprio nem recebe pagamento por eles.

- Removidos: "Acesso por Clínica" (rotas `manager.gateways.entity-access*`, modal, contagem no card) e a credencial por clínica (`GatewayCallContext::useTenantCredentials`, ramo `tenant` do `GatewayCredentialResolver`, case `CredentialScope::Tenant`). O resolvedor só lê `scope = global` com `entity_id` NULL; sem ela, cai no `.env`.
- `php artisan migrate` roda:
  - `2026_10_11_000000_drop_entity_gateway_access_table` — derruba `entity_gateway_access` (o `down` recria o schema vazio);
  - `2026_10_11_000100_delete_tenant_gateway_credentials` — apaga de vez as credenciais com `entity_id` ou `scope` diferente de `global` (inclusive soft-deleted). Sem volta: o `down` é vazio.
- Antes de migrar (opcional, para registro): `SELECT count(*) FROM entity_gateway_access;` e `SELECT count(*) FROM gateway_credentials WHERE entity_id IS NOT NULL OR scope <> 'global';`.
- Depois: `php artisan optimize:clear` (rotas/config em cache) e `npm run build`. Nada muda para a credencial global cadastrada no manager.

## Rodada 6 — Asaas: notificações desligadas, Asaas Checkout, correções de risco e estorno pelo manager

Guia completo do painel do Asaas (webhook, eventos, chave Pix, boleto vencido, antecedência da recorrência, domínio, whitelist, sandbox × produção, rotação de chave): **[asaas-configuracao.md](asaas-configuracao.md)**.

### Comandos do deploy (nesta ordem)

```bash
php artisan migrate                       # billing_hosted_checkouts, billing_gateway_customers, billing_refunds + refunded_amount, gateways.health, billing_refunds.gateway_state/last_checked_at/check_note e invoices.gateway_checked_at
php artisan config:cache && php artisan route:cache
php artisan billing:asaas-disable-notifications --dry-run   # clientes já cadastrados no Asaas
php artisan billing:asaas-disable-notifications             # uma vez (idempotente)
php artisan billing:gateway-health                          # conferir a chave (card do Asaas em Manager → Gateways)
npm run build
```

Sem mudança de canal/evento do Reverb (o aviso de pagamento continua o `InvoicePaid` em `billing.{entityId}`) — não precisa de `reverb:restart`.

### No painel do Asaas (obrigatório)

- Webhook: incluir os eventos **`CHECKOUT_*`**, **`SUBSCRIPTION_CREATED`/`SUBSCRIPTION_UPDATED`**, **`PAYMENT_PARTIALLY_REFUNDED`**, **`PAYMENT_BANK_SLIP_CANCELLED`** e **`ACCESS_TOKEN_*`** (lista completa no guia), envio **sequencial** e o e-mail de alerta de fila pausada.
- Domínio do EasyEye nos dados comerciais (volta do Asaas Checkout).
- Antecedência da geração das cobranças da assinatura em 7 dias; boleto pagável depois do vencimento por pelo menos 7 dias; chave Pix cadastrada.

### Agendador novo

- `billing:gateway-health` (06:10) — chamada leve e autenticada (`GET /v3/myAccount/status/`): mantém a chave em uso (3 meses sem uso = desabilitada) e alerta o time em chave recusada/de outro ambiente.
- `billing:reconcile-overdue` (08:30, antes da régua das 09:00) — confere no gateway as faturas vencidas com cobrança emitida e aplica o pagamento cujo webhook não chegou (teto `BILLING_RECONCILE_MAX_PER_RUN`, pausa `BILLING_RECONCILE_PAUSE_MS`, para no 429; começa pelas nunca/há mais tempo conferidas e pula as conferidas no dia).
- `billing:check-refunds` (de hora em hora) — confere no gateway os estornos pedidos pelo manager parados em "solicitado" (sem resposta definitiva: depois de 10 min; os demais: depois de `BILLING_REFUND_EXPIRE_DAYS`, padrão 30) e só libera um novo pedido quando o gateway diz que o estorno não existe ou foi negado.

### Variáveis novas (`.env`)

| Variável | Padrão | Para quê |
|---|---|---|
| `ASAAS_HOSTED_CHECKOUT` | `true` | Cartão no Asaas pelo Asaas Checkout (`false` volta ao link da fatura) |
| `ASAAS_CHECKOUT_MINUTES_TO_EXPIRE` | `60` | Validade do checkout (10–1440) |
| `ASAAS_CHECKOUT_ITEM_IMAGE` | — | Imagem do item do checkout, só se o Asaas exigir `imageBase64` |
| `BILLING_WEBHOOK_RATE_LIMIT_PER_MINUTE` | `3000` | Limite da rota de webhook **por gateway**, só para requisições com o token/assinatura válidos |
| `BILLING_WEBHOOK_INVALID_RATE_LIMIT_PER_MINUTE` | `30` | Sem token/token inválido: balde próprio por IP (flood falso não gera 429 para o gateway) |
| `BILLING_DUNNING_MAX_GATEWAY_CHECK_DEFERRALS` | `3` | Régua: adiamentos seguidos (conferência sem resposta conclusiva) antes do alerta crítico |
| `BILLING_REFUND_EXPIRE_DAYS` / `BILLING_REFUND_CHECK_PER_RUN` | `30` / `50` | Conferência dos estornos parados (`billing:check-refunds`) |
| `BILLING_RATE_LIMIT_CONCURRENCY_BACKOFF_SECONDS` | `5` | Espera da rota depois de 429 sem tempo pedido (concorrência) |
| `BILLING_RECONCILE_MAX_PER_RUN` / `BILLING_RECONCILE_PAUSE_MS` / `BILLING_RECONCILE_LOOKBACK_DAYS` | `100` / `300` / `60` | Conciliação diária |

### O que mudou no comportamento

- **Notificações do Asaas desligadas** (`notificationDisabled`) em todo cliente criado ou reaproveitado; marcado em `billing_gateway_customers`.
- **Cartão no Asaas = Asaas Checkout**: a fatura do plano vira assinatura no cartão; a recorrência boleto/Pix antiga só é cancelada depois da 1ª cobrança no cartão confirmada (e a cobrança antiga da fatura é cancelada). Pacote de IA e diferença do upgrade: checkout avulso.
- **Idempotência no Asaas** (sem chave de idempotência na API): depois de timeout/5xx, a emissão (renovação, reemissão do checkout, pacote de IA, upgrade) procura a cobrança pela referência (`GET /v3/payments?externalReference=`) e a reaproveita.
- **Régua**: antes de limitar (D+3) e encerrar (D+7), confere a cobrança no gateway; paga lá → aplica e não limita/encerra.
- **Pix/boleto**: 401/403/429/5xx do Asaas mostram "não foi possível gerar agora, tente de novo" (antes viravam "forma indisponível"); 401/403 alertam o time.
- **429**: respeita `RateLimit-Reset`/`Retry-After`; jobs reagendam para depois.
- **Webhook**: sem limite por IP; `occurredAt` = `dateCreated` do evento; ciclo desconhecido é erro (não vira mensal); `SUBSCRIPTION_UPDATED` divergente alerta o time.
- **Estorno pelo manager** (Asaas e Mercado Pago): Assinaturas → detalhe → faturas → Estornar (total/parcial, justificativa, auditoria); "solicitado" até o gateway confirmar. Estorno parcial registra `refunded_amount` (pagamento e fatura) e o evento financeiro `payment_partially_refunded`; pacote de IA tira os créditos proporcionais.

#### Correções da revisão (mesma rodada)

- Checkout de cartão recorrente pendente deixa de valer quando a assinatura é cancelada, encerrada (D+7), substituída, vira cortesia ou troca de plano: o aberto é cancelado no Asaas e a assinatura no cartão que um checkout pago criou é desfeita antes de cobrar. A recorrência nova só é adotada com a assinatura governada, a fatura a pagar e os mesmos termos.
- Segundo checkout com o 1º pago aguardando confirmação: recusado (409) e "Pagar" some da tela. Pagamento em duplicidade vira `payments.status = duplicate` (não quita a fatura) e é estornável pelo manager.
- Cobranças seguintes da recorrência do checkout com a referência herdada renovam o período (nunca caem na fatura já paga).
- Troca de plano com a recorrência no cartão: refeita sem o cartão (`UNDEFINED`), cartão sai da assinatura, aviso em Minha assinatura e ao time.
- Estorno: timeout/5xx = "solicitado" inconclusivo (conferido antes de outro pedido), 429 = enviado por job com a mesma chave, "Conferir" no manager, `PAYMENT_REFUND_DENIED`/`_IN_PROGRESS`, "total" depois de parcial com o valor restante, um pedido por vez. Pacote de IA: estorno do restante depois de um parcial não tira créditos em dobro.
- Régua: só encerra com "não pago" conclusivo; conferência sem resposta adia e alerta depois de N adiamentos. 429 congela só a rota (a cota, a conta); cobrança que deixou de valer e não cancelou agora vai para `CancelGatewayChargeJob`. Chave recusada: um registro crítico por janela. Timeout na criação nunca reaproveita a cobrança vigente/vencida.

