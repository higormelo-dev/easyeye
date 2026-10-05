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

Sem a chave pública o cartão não é transparente: vai pelo link do gateway (Asaas, InfinitePay, Stripe) ou some das formas de pagamento (Mercado Pago, Pagar.me). Formatos aceitos:

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

- Sai pela **instância GLOBAL** (Manager → WhatsApp, linha sem clínica — a mesma do código de verificação do cadastro) com `WHATSAPP_DRIVER=zapi`. **Nunca** pela instância da clínica, e o `ClinicServiceGate` não barra estes avisos (clínica bloqueada continua recebendo a régua).
- Destinatário: contato de cobrança (admin, financeiro, dono) com **WhatsApp verificado** (`users.phone_verified_at`). Sem número verificado → só e-mail (fica no log `[whatsapp:saas-notice]`).
- Fila `WHATSAPP_QUEUE`, até 3 tentativas (1 min, 10 min); falha final só no log — o e-mail já saiu por outro job e a régua segue.
- Texto curto, sem dado de paciente, com o link **dentro do sistema** (`/panel/my-subscription`, com `?invoice=<id>` quando há fatura a pagar). O link do gateway nunca vai no WhatsApp.

### Asaas — eventos de webhook a assinar no painel

No painel do Asaas: **Integrações → Webhooks → (webhook do EasyEye) → Eventos**, marcar:

- **Cobranças:** todos os `PAYMENT_*` (já usados desde a régua).
- **Assinaturas:** `SUBSCRIPTION_INACTIVATED` e `SUBSCRIPTION_DELETED` (obrigatórios nesta rodada). `SUBSCRIPTION_CREATED`/`SUBSCRIPTION_UPDATED`/`SUBSCRIPTION_SPLIT_*` podem ficar marcados — só são registrados.

Doc: <https://docs.asaas.com/docs/eventos-para-assinaturas>.

### Recorrência desativada pelo gateway (decisão: avisar o manager + régua)

- `SUBSCRIPTION_INACTIVATED`/`SUBSCRIPTION_DELETED` do Asaas (e o equivalente dos outros: `customer.subscription.deleted` do Stripe, preapproval cancelado do Mercado Pago) **não** cancelam nem bloqueiam mais a assinatura. Quem pagou segue com acesso até o fim do período pago.
- A cobrança passa para a **renovação local** (`gateway_subscription_id` fica nulo): a próxima fatura sai do `subscriptions:renew` (01:00, alguns dias antes do vencimento) e a clínica paga em Minha assinatura (Pix/boleto/cartão; no Asaas o cartão é o link). Sem pagamento, régua normal (D-5, D+1, D+3, D+7).
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
