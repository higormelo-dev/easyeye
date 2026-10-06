# Asaas — guia de configuração (painel do Asaas, EasyEye e deploy)

O Asaas é um dos gateways do **dono do EasyEye** para cobrar as clínicas: assinatura (mensal, trimestral, semestral, anual), pacotes de créditos de IA e a diferença do upgrade. Clínica não tem gateway próprio.

O que o EasyEye usa do Asaas:

| Recurso | Como | Doc oficial |
|---|---|---|
| Assinatura (recorrência "pergunte ao cliente") | `POST /v3/subscriptions` com `billingType: UNDEFINED` — o Asaas gera cada cobrança | <https://docs.asaas.com/docs/assinaturas> |
| Pix e boleto na tela do EasyEye | `GET /v3/payments/{id}/pixQrCode` e `/identificationField` | <https://docs.asaas.com/reference/obter-qr-code-para-pagamentos-via-pix> |
| Cartão | **Asaas Checkout** (página hospedada): fatura do plano → assinatura no cartão (`RECURRENT`); pacote de IA, upgrade → avulso (`DETACHED`). O cartão nunca passa pelo EasyEye | <https://docs.asaas.com/docs/checkout-asaas> |
| Estorno pelo manager | `POST /v3/payments/{id}/refund` (cartão/Pix, total ou parcial) e `POST /v3/payments/{id}/bankSlip/refund` (boleto, só total) | <https://docs.asaas.com/reference/estornar-cobranca>, <https://docs.asaas.com/reference/estornar-boleto> |
| Saúde da chave | `GET /v3/myAccount/status/` todo dia (`billing:gateway-health`) | <https://docs.asaas.com/reference/consultar-situacao-cadastral-da-conta> |
| Notificações ao pagador | **desligadas** em todo cliente (`notificationDisabled: true`) — só a régua do EasyEye fala com a clínica | <https://docs.asaas.com/docs/notificacoes> |

---

## 1. Chave de API e ambiente

1. Crie a chave em **Integrações → Chaves de API** (só administrador da conta; ela aparece **uma vez** — copie na hora). <https://docs.asaas.com/docs/chaves-de-api>
2. Cada ambiente tem a sua chave e a sua URL (<https://docs.asaas.com/docs/autenticação-1>):

   | Ambiente | Prefixo da chave | `ASAAS_BASE_URL` |
   |---|---|---|
   | Produção | `$aact_prod_` | `https://api.asaas.com` |
   | Sandbox | `$aact_hmlg_` | `https://api-sandbox.asaas.com` |

   A base vai **sem** o `/v3` (se vier com ele, o EasyEye remove o repetido). Chave de um ambiente com a URL do outro = 401 `invalid_environment`; o health check confere o prefixo × a base **antes** de chamar a API e alerta o time.
3. Cadastre a chave em **Manager → Gateways → Asaas → Credenciais** (tem prioridade) ou no `.env` (`ASAAS_SECRET`). No `.env`, a chave começa com `$`: use aspas simples (`ASAAS_SECRET='$aact_prod_…'`) — <https://docs.asaas.com/docs/padrão-de-chave-com-e-clientes-php>.
4. **User-Agent**: obrigatório para contas raiz criadas a partir de 13/06/2024. O EasyEye manda `<APP_NAME>/billing` (ex.: `EasyEye/billing`) em toda chamada.
5. **Inatividade**: sem uso por 3 meses a chave é desabilitada (401) e por 6 meses expira de vez. O `billing:gateway-health` (diário, 06:10) faz uma chamada autenticada leve e mantém a chave em uso; os eventos `ACCESS_TOKEN_*` (ver webhook) avisam antes.
6. **Rotação**: crie a chave nova, troque em Manager → Gateways (ou `.env` + `php artisan config:cache`), confira o card do Asaas ("Conexão com a API: Funcionando" — ou rode `php artisan billing:gateway-health --gateway=asaas`) e só então desabilite/exclua a antiga no painel do Asaas.
7. **Permissões da chave**: se a chave tiver escopo restrito, ela precisa ler/criar clientes, cobranças, assinaturas e checkouts, e **estornar cobranças** (permissão de estorno — `PAYMENT_REFUND:WRITE` no estorno de boleto).

### Whitelist de IP (recomendado)

**Menu do usuário → Integrações → Mecanismos de segurança** (<https://docs.asaas.com/docs/whitelist-de-ips>): cadastre o(s) IP(s) **de saída** dos servidores do EasyEye (app, fila e agendador). IP fora da lista = 403 em toda chamada — o EasyEye registra e manda **alerta ao time** (admin, financeiro e dono do SaaS) no máximo uma vez por hora.

---

## 2. Webhook

**Integrações → Webhooks → Adicionar** (ou `POST /v3/webhooks` — <https://docs.asaas.com/docs/criar-novo-webhook-pela-api>):

| Campo | Valor |
|---|---|
| URL | `https://<domínio do EasyEye>/api/billing/webhooks/asaas` |
| Token de autenticação | aleatório, **32 a 255** caracteres (ex.: `openssl rand -hex 32`). O mesmo valor vai em Manager → Gateways → Asaas (segredo do webhook) ou `ASAAS_WEBHOOK_SECRET`. Chega no header `asaas-access-token`; sem token configurado no EasyEye, todo webhook é recusado (fail-closed). Nunca use a chave de API como token. |
| Versão da API | 3 |
| Tipo de envio | **Sequencial** (`SEQUENTIALLY`) — a ordem importa (ex.: OVERDUE antes de CONFIRMED) — <https://docs.asaas.com/docs/tipos-de-envio> |
| E-mail | e-mail do time técnico: o Asaas avisa na 5ª e na 10ª falha seguida e quando **pausa a fila** (15ª falha) — <https://docs.asaas.com/docs/penalização-de-filas> |
| Situação | Ativo, fila não interrompida |

**Eventos** (marque exatamente estes):

- Cobranças: `PAYMENT_CREATED`, `PAYMENT_UPDATED`, `PAYMENT_CONFIRMED`, `PAYMENT_RECEIVED`, `PAYMENT_ANTICIPATED`, `PAYMENT_OVERDUE`, `PAYMENT_DELETED`, `PAYMENT_RESTORED`, `PAYMENT_REFUNDED`, `PAYMENT_PARTIALLY_REFUNDED`, `PAYMENT_REFUND_IN_PROGRESS`, `PAYMENT_REFUND_DENIED`, `PAYMENT_RECEIVED_IN_CASH_UNDONE`, `PAYMENT_CHARGEBACK_REQUESTED`, `PAYMENT_CHARGEBACK_DISPUTE`, `PAYMENT_AWAITING_CHARGEBACK_REVERSAL`, `PAYMENT_REPROVED_BY_RISK_ANALYSIS`, `PAYMENT_CREDIT_CARD_CAPTURE_REFUSED`, `PAYMENT_DUNNING_RECEIVED`, `PAYMENT_BANK_SLIP_CANCELLED` — <https://docs.asaas.com/docs/webhook-para-cobrancas>
- Assinaturas: `SUBSCRIPTION_CREATED`, `SUBSCRIPTION_UPDATED`, `SUBSCRIPTION_INACTIVATED`, `SUBSCRIPTION_DELETED` — <https://docs.asaas.com/docs/eventos-para-assinaturas>
- Checkout: `CHECKOUT_CREATED`, `CHECKOUT_PAID`, `CHECKOUT_CANCELED`, `CHECKOUT_EXPIRED` — <https://docs.asaas.com/docs/eventos-para-checkout>
- Chaves de API: `ACCESS_TOKEN_DISABLED`, `ACCESS_TOKEN_EXPIRING_SOON`, `ACCESS_TOKEN_EXPIRED`, `ACCESS_TOKEN_DELETED` — <https://docs.asaas.com/docs/eventos-para-chaves-de-api>

O que o EasyEye faz com eles:

- responde **200** na hora (grava e processa na fila `BILLING_WEBHOOK_QUEUE`); idempotente pelo `id` do evento — reentrega não duplica;
- **limite por gateway, só para quem tem o token**: a requisição com o `asaas-access-token` correto conta no balde do gateway (`BILLING_WEBHOOK_RATE_LIMIT_PER_MINUTE`, padrão 3000/min — 429 conta como falha no Asaas e pausaria a fila); sem token ou com token falso cai num balde próprio, pequeno e por IP (`BILLING_WEBHOOK_INVALID_RATE_LIMIT_PER_MINUTE`, padrão 30/min) — um flood falso nunca gera 429 para o Asaas;
- `PAYMENT_REFUND_IN_PROGRESS` mantém o estorno pedido pelo manager como "solicitado" (em processamento); `PAYMENT_REFUND_DENIED` (só boleto) marca o pedido como recusado e libera um novo;
- `PAYMENT_UPDATED` (valor/vencimento) e `PAYMENT_BANK_SLIP_CANCELLED` apagam o Pix/linha digitável guardados da cobrança (a próxima abertura consulta de novo);
- `SUBSCRIPTION_UPDATED` com valor, ciclo ou vencimento diferentes da assinatura no EasyEye → **alerta ao time** (o que vale é o contratado no EasyEye);
- `ACCESS_TOKEN_*` → alerta ao time (o objeto da chave é guardado sem os dados — só o nome do evento chega ao alerta).

**IPs oficiais do webhook em produção** (para firewall/WAF/Cloudflare do EasyEye — <https://docs.asaas.com/docs/ips-oficiais-do-asaas>): `52.67.12.206`, `18.230.8.159`, `54.94.136.112`, `54.94.183.101`. No sandbox pode haver outros: libere pelo log do firewall.

**Fila pausada** (<https://docs.asaas.com/docs/fila-pausada>): corrija a causa (logs em Integrações → Webhooks → Logs), reative a fila no painel. Os eventos ficam guardados por 14 dias. Enquanto isso, `billing:reconcile-overdue` (diário) confere as faturas vencidas na API e aplica os pagamentos perdidos — começando pelas nunca/há mais tempo conferidas (`invoices.gateway_checked_at`) e pulando as já conferidas no dia, para que, com mais faturas que o teto, as recentes também sejam alcançadas —, e a régua confere o Asaas antes de limitar (D+3) ou encerrar (D+7). A régua só encerra com "não pago" **conclusivo**: timeout, 5xx, chave recusada (401/403) ou 429 na conferência **adiam** a etapa para a rodada seguinte; depois de `BILLING_DUNNING_MAX_GATEWAY_CHECK_DEFERRALS` (padrão 3) adiamentos seguidos o time recebe alerta crítico ("Régua parada…") — a régua nunca encerra sozinha sem conferir.

---

## 3. Configurações da conta

1. **Chave Pix**: cadastre uma chave Pix na conta (de preferência **aleatória/EVP**). Sem chave, o QR Code dinâmico ainda funciona por uma instituição parceira, mas só pode ser pago até as 23:59 do mesmo dia e o Asaas avisou que vai descontinuar — <https://docs.asaas.com/docs/cobrancas-via-pix>.
2. **Boleto vencido**: deixe a conta **permitindo o pagamento do boleto depois do vencimento por pelo menos 7 dias** (a régua só encerra no D+7). O EasyEye não manda `daysAfterDueDateToRegistrationCancellation` na cobrança — vale a configuração padrão da conta (<https://docs.asaas.com/reference/criar-nova-cobranca>). Com a conta configurada para não aceitar boleto vencido, o registro é cancelado e chega `PAYMENT_BANK_SLIP_CANCELLED` (o EasyEye apaga a linha digitável guardada; a clínica paga por Pix ou cartão). *O caminho exato desse ajuste no painel não está na documentação da API — confirme na Central de Ajuda do Asaas.*
3. **Antecedência da geração das cobranças da assinatura**: o padrão do Asaas é gerar cada cobrança **40 dias** antes do vencimento; ajuste para **7 dias** (ou 14) — <https://docs.asaas.com/docs/faq-assinaturas>. Com 40 dias a clínica vê a fatura do próximo ciclo logo depois de pagar a atual; com 7 ela aparece perto do lembrete do EasyEye (D-5).
4. **Notificações do Asaas desligadas**: todo cliente que o EasyEye cria vai com `notificationDisabled: true`; cliente reaproveitado (achado pelo CNPJ) tem as notificações desligadas na hora (`PUT /v3/customers/{id}`, aceito pelo update — <https://docs.asaas.com/reference/atualizar-cliente-existente>) e fica marcado em `billing_gateway_customers` (não se pede de novo). Para os clientes que **já existiam**, rode uma vez no deploy:

   ```bash
   php artisan billing:asaas-disable-notifications --dry-run   # lista o que mudaria
   php artisan billing:asaas-disable-notifications             # desliga (idempotente; pausa de 300 ms entre clientes)
   ```

5. **Dados comerciais / domínio** (Asaas Checkout): em **Configurações da conta → Informações**, informe o site com o **domínio do EasyEye** (o mesmo das URLs de volta `https://<domínio>/panel/my-subscription?checkout_return=…`). A doc exige o domínio cadastrado para o retorno da fatura (<https://docs.asaas.com/docs/redirecionamento-apos-o-pagamento>); para o Checkout ela não diz — cadastre por segurança. Se a criação do checkout falhar com erro de callback, é isso.
6. **Cartão de crédito habilitado** na conta (o Checkout usa as formas habilitadas).
7. **Tokenização (opcional, recomendada)**: quem paga a fatura atrasada pelo cartão ganha uma assinatura no cartão que nasce hoje; o EasyEye ajusta o próximo vencimento dela para o fim do período pago (`PUT /v3/subscriptions/{id}`). Em assinatura no cartão a doc exige a **tokenização habilitada** na conta para mudar o vencimento (<https://docs.asaas.com/reference/atualizar-assinatura-existente>). Sem ela, o EasyEye manda alerta "Ajustar o vencimento da recorrência…" e o ajuste é feito à mão no painel.

---

## 4. Cartão pelo Asaas Checkout (como funciona)

- A clínica escolhe **Cartão de crédito** → o EasyEye cria o checkout (`POST /v3/checkouts`, `billingTypes: [CREDIT_CARD]`, `minutesToExpire` = `ASAAS_CHECKOUT_MINUTES_TO_EXPIRE`, padrão 60) e mostra **"Ir para o pagamento seguro"**; a clínica paga na página do Asaas e volta para **Minha assinatura** com "aguardando confirmação". A confirmação é **sempre** pelo webhook (o `successUrl` só traz o pagador de volta).
- **Fatura do plano** → `RECURRENT` com o ciclo e o valor da assinatura e a 1ª cobrança no vencimento da fatura (ou hoje, se já venceu). **Troca segura**: a recorrência anterior (boleto/Pix) só é cancelada depois que a 1ª cobrança da nova é confirmada; a cobrança antiga em aberto da fatura é cancelada no Asaas. Recusa do cartão ou fatura paga antes por outro meio → a recorrência nova é desfeita e a antiga continua.
- A recorrência nova só é **adotada** com a assinatura ainda cobrada pelo Asaas (ativa/em atraso, cobrança automática), a fatura ainda a pagar e os **mesmos termos** (valor/ciclo, sem mudança de plano agendada diferente). Senão ela é desfeita no Asaas (`DELETE /v3/subscriptions/{id}`) e o pagamento entra pelo fluxo normal (alerta; termos trocados também alertam o time).
- **Assinatura que mudou com checkout pendente** — cancelada, encerrada pela régua (D+7), substituída pelo manager, virou cortesia, trocou de plano (upgrade aplicado, downgrade agendado ou desfeito): o checkout aberto é cancelado no Asaas (`POST /v3/checkouts/{id}/cancel`) e a assinatura no cartão que um checkout já pago criou (aguardando a 1ª cobrança) é desfeita — o Asaas não segue cobrando o cartão. Com mudança de plano agendada, o cartão da fatura atual sai **avulso** (`DETACHED`).
- **Um pagamento no cartão por vez**: com o checkout pago aguardando a confirmação, a tela esconde "Pagar" e o servidor recusa outro (409 `card_awaiting_confirmation`). Se mesmo assim a fatura for paga duas vezes (ex.: dois links abertos), o 2º pagamento fica registrado como **"Em duplicidade"** (não quita nada) e o manager estorna pela tela.
- As cobranças seguintes da recorrência do checkout podem herdar a referência do checkout: o EasyEye usa dela só a assinatura (a fatura é a do período, pelo vencimento); a 1ª cobrança se liga pelo `checkoutSession` ou pela assinatura criada pelo checkout.
- **Troca de plano com a recorrência no cartão**: a recorrência é refeita nos termos novos, mas **sem o cartão** (a API só cria assinatura no cartão com os dados/token do cartão, que o EasyEye não guarda — PCI): sai como "pergunte ao cliente" (`UNDEFINED`), os dados do cartão saem da assinatura, a clínica vê em Minha assinatura o aviso para pagar a próxima fatura no cartão (o que volta a recorrência para o cartão) e o time recebe o aviso "Recorrência … refeita sem o cartão".
- **Pacote de créditos de IA, diferença do upgrade, fatura que não vira recorrência** → `DETACHED` (à vista). Pix e boleto seguem na tela do EasyEye.
- Ligação: o Asaas devolve o checkout no campo `checkoutSession` da cobrança e da assinatura; a referência do checkout é `easyeye:sub:<assinatura>:inv:<fatura>`.
- `items[].imageBase64`: a referência marca como obrigatório, mas os exemplos da doc criam sem. O EasyEye não manda; se o Asaas recusar por isso, aponte `ASAAS_CHECKOUT_ITEM_IMAGE` para um PNG/JPG (até 1 MB).
- Desligar: `ASAAS_HOSTED_CHECKOUT=false` (o cartão volta ao link da fatura `invoiceUrl`).

---

## 5. Estorno pelo manager

Manager → Assinaturas → detalhe → **Faturas** → **Estornar** (só admin e financeiro do SaaS; justificativa obrigatória; auditoria em `audit_logs`). Total ou parcial (cartão e Pix); boleto só total — o Asaas devolve o link em que a clínica informa a conta (aparece no detalhe). O estorno fica **"Estorno solicitado"** até o Asaas confirmar (`refunds[].status = DONE`, `PAYMENT_REFUNDED`, `PAYMENT_PARTIALLY_REFUNDED`). Estorno parcial só registra o valor devolvido (o acesso não muda); no pacote de IA tira os créditos proporcionais (sem deixar saldo negativo — o que faltar vira alerta) e o estorno do restante depois tira só o que ainda não saiu. Estorno "total" depois de um parcial vai sempre com `value` = o restante (sem `value` o Asaas estornaria o valor integral). As taxas da cobrança não voltam: estorno de Pix logo depois do recebimento pode falhar por falta de saldo na conta (<https://docs.asaas.com/reference/estornar-cobranca>).

- **Um pedido por vez** por pagamento (trava): duplo clique/duas abas não estornam em dobro.
- **Sem resposta definitiva** (timeout, conexão, 5xx): o pedido fica "solicitado" com "Sem resposta do gateway — conferir" e nenhum outro é liberado até conferir. **429**: o pedido entra na fila e é enviado automaticamente depois do tempo pedido, com a mesma chave (no Mercado Pago, a mesma `X-Idempotency-Key`).
- **Conferir**: no detalhe, o pedido em aberto tem **Conferir** — consulta o `refunds[]` da cobrança (`GET /v3/payments/{id}`; o estorno só vale com `DONE` — <https://docs.asaas.com/docs/estornos>): devolvido → concluído; em processamento ou boleto aguardando a conta do pagador → segue solicitado, com a situação; cancelado/inexistente no Asaas → liberado para um novo pedido. `billing:check-refunds` (de hora em hora) faz o mesmo sozinho com os sem resposta (depois de 10 min) e com qualquer pedido parado há mais de `BILLING_REFUND_EXPIRE_DAYS` (padrão 30) — nunca libera sem conferir; o que não puder conferir vira alerta ao time.
- **Pagamento em duplicidade** (fatura já quitada por outra cobrança) aparece como "Em duplicidade" com **Estornar**; o estorno dele não mexe na fatura nem na assinatura.

---

## 6. Sandbox — roteiro antes de produção

1. Conta sandbox (<https://sandbox.asaas.com>), chave `$aact_hmlg_…`, `ASAAS_BASE_URL=https://api-sandbox.asaas.com`, webhook apontando para o ambiente de teste com os eventos acima.
2. `php artisan billing:gateway-health --gateway=asaas` → "ok".
3. Contratar no Pix; pagar pelo painel do sandbox ("Confirmar pagamento") → fatura paga em tempo real.
4. Pagar uma fatura no cartão (cartões de teste do sandbox) → volta com "aguardando confirmação" → `CHECKOUT_PAID`/`PAYMENT_CONFIRMED` → assinatura passa a mostrar o cartão; no painel do sandbox a recorrência antiga sumiu e a nova (cartão) ficou.
5. Comprar um pacote de IA no cartão → créditos na carteira.
6. Estornar parcial e total pelo manager → "solicitado" → concluído com o webhook; num estorno de boleto, usar **Conferir** e ver "Aguardando a clínica informar a conta".
7. `php artisan billing:asaas-disable-notifications --dry-run` e depois sem `--dry-run`.
8. Pausar a fila de propósito (URL errada por alguns minutos), pagar uma cobrança, voltar a URL e rodar `php artisan billing:reconcile-overdue` → pagamento aplicado.

---

## 7. Variáveis (`.env`)

| Variável | Padrão | Para quê |
|---|---|---|
| `ASAAS_BASE_URL` | `https://api.asaas.com` | Base da API (sem `/v3`) |
| `ASAAS_SECRET` | — | Chave de API (se não estiver no manager) |
| `ASAAS_WEBHOOK_SECRET` | — | Token do webhook (se não estiver no manager) |
| `ASAAS_BILLING_TYPE` | `UNDEFINED` | Forma das cobranças da recorrência |
| `ASAAS_HOSTED_CHECKOUT` | `true` | Cartão pelo Asaas Checkout (`false` = link da fatura) |
| `ASAAS_CHECKOUT_MINUTES_TO_EXPIRE` | `60` | Validade do checkout (10 a 1440) |
| `ASAAS_CHECKOUT_ITEM_IMAGE` | — | Imagem do item, só se o Asaas exigir |
| `BILLING_WEBHOOK_RATE_LIMIT_PER_MINUTE` | `3000` | Limite da rota de webhook por gateway (só requisições com o token válido) |
| `BILLING_WEBHOOK_INVALID_RATE_LIMIT_PER_MINUTE` | `30` | Limite por IP das requisições sem token/com token inválido |
| `BILLING_DUNNING_MAX_GATEWAY_CHECK_DEFERRALS` | `3` | Adiamentos seguidos da régua (conferência sem resposta) antes do alerta crítico |
| `BILLING_REFUND_EXPIRE_DAYS` | `30` | Pedido de estorno parado há tantos dias é conferido (e liberado se não existir no gateway) |
| `BILLING_REFUND_CHECK_PER_RUN` | `50` | Pedidos conferidos por execução do `billing:check-refunds` |
| `BILLING_RATE_LIMIT_CONCURRENCY_BACKOFF_SECONDS` | `5` | Espera da rota depois de um 429 sem tempo pedido (limite de GETs simultâneos) |
| `BILLING_RECONCILE_MAX_PER_RUN` | `100` | Faturas conferidas por execução do `billing:reconcile-overdue` |
| `BILLING_RECONCILE_PAUSE_MS` | `300` | Pausa entre consultas (limite da API: <https://docs.asaas.com/reference/rate-e-quota-limit>) |
| `BILLING_RECONCILE_LOOKBACK_DAYS` | `60` | Até quantos dias de atraso a conciliação olha |

Limites da API (<https://docs.asaas.com/reference/rate-e-quota-limit>): 429 respeita `RateLimit-Reset`/`Retry-After` **só na rota que estourou** (método + endpoint — o limite de frequência é por endpoint); a cota da conta (25 mil requisições em 12 h, mensagem de "cota") congela todas as chamadas; 429 sem tempo pedido (concorrência) espera só alguns segundos naquela rota. Enquanto isso a chamada nem sai e os jobs reagendam para depois. Caminhos críticos vão para job com novas tentativas: cancelar a cobrança que deixou de valer quando a fatura é paga por outra (`CancelGatewayChargeJob` — esgotado, alerta crítico para cancelar à mão), cancelar recorrência (`CancelGatewaySubscriptionJob`) e enviar estorno (`SendBillingRefundJob`).
