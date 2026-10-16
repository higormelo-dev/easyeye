# Sandbox do Asaas + ngrok — testar o cadastro com pagamento na hora (E2E opt-in)

Como subir o EasyEye local, expor o webhook por um túnel e validar, **contra o sandbox real
do Asaas**, o fluxo "cliente entra no site, se cadastra em `/register` e fecha o plano na hora"
com Pix, boleto e cartão — mais o caso negativo de cartão recusado.

Isto é **fora** da suíte de regressão. A suíte E2E (`e2e/`) é autocontida e **não chama gateway
nenhum** (tudo por `cy.intercept`) — ver [`e2e/README.md`](../../../e2e/README.md). O spec deste
documento roda **sob demanda**, só com o sandbox configurado, e fica fora do padrão da suíte.
Configuração do Asaas no painel, eventos e variáveis: [Asaas — guia de configuração](../integracoes/billing/asaas-configuracao.md)
(§2 webhook, §3 conta, §4 Checkout, §6 sandbox, §7 variáveis).

| | |
|---|---|
| Spec (opt-in) | `e2e/cypress/e2e/sandbox/signup-checkout-sandbox.cy.js` — modo no §7 |
| Fora da suíte padrão | sim: sem `CYPRESS_SANDBOX=1` a pasta `sandbox/` não é coletada (§7) |
| Limpeza | manual nos dois lados — marcadores e passos no §6 (não há script de limpeza) |

## 1. Pré-requisitos

| # | Item | Como |
|---|---|---|
| 1 | App no ar em `http://localhost:8085` | Na raiz: `composer dev` (sobe app `:8085`, fila `queue:listen`, Vite, `pail` e Reverb `:8080`) ou `php artisan serve --port=8085` + `npm run dev` + `php artisan queue:listen` |
| 2 | Banco de **desenvolvimento** (`easyeye`, pgsql) | O spec cria clínica/usuário de verdade pelo `/register` — nunca aponte para produção |
| 3 | Fila rodando | O webhook responde 200 na hora e processa na fila `BILLING_WEBHOOK_QUEUE` (padrão `default`). Sem worker, o pagamento não aparece pago |
| 4 | Conta **sandbox** do Asaas | <https://sandbox.asaas.com> (conta separada da de produção) |
| 5 | Chave de API do sandbox | Prefixo `$aact_hmlg_` |
| 6 | Segredo do webhook | Aleatório, 32–255 caracteres: `openssl rand -hex 32` |
| 7 | Chave Pix cadastrada na conta sandbox | Sem chave, pagar o QR Code do Pix no sandbox dá **404** (<https://docs.asaas.com/docs/testar-pagamento-de-qrcodes-pix>). Preferir chave aleatória/EVP |
| 8 | Cartão de crédito e Checkout habilitados na conta | Formas habilitadas na conta (<https://docs.asaas.com/docs/checkout-asaas>) |
| 9 | Turnstile **desligado** no ambiente, ou chave de teste | O `/register` exige o token quando `TURNSTILE_SITE_KEY`/`TURNSTILE_SECRET_KEY` estão preenchidas |
| 10 | ngrok instalado e autenticado | `ngrok config add-authtoken <token>` |
| 11 | Google Chrome | A suíte roda `--browser chrome` |

`.env` local (aspas simples na chave por causa do `$`):

```dotenv
ASAAS_BASE_URL=https://api-sandbox.asaas.com
ASAAS_SECRET='$aact_hmlg_…'
ASAAS_WEBHOOK_SECRET=<32 a 255 caracteres, igual ao cadastrado no painel>
ASAAS_HOSTED_CHECKOUT=true
ASAAS_CHECKOUT_MINUTES_TO_EXPIRE=60
```

**Atenção:** as credenciais do **Manager → Gateways → Asaas** têm **prioridade** sobre o `.env`
(§1, item 3, do guia do Asaas) — confirme qual valor está valendo antes de culpar o `.env`.
Depois de mexer no `.env`: `php artisan config:cache && php artisan queue:restart`.

Conferência rápida da chave/ambiente (antes de qualquer teste):

```bash
php artisan billing:gateway-health --gateway=asaas   # esperado: ok
```

## 2. Subir o túnel

```bash
ngrok http 8085
# Inspector (ver as requisições que o Asaas faz): http://127.0.0.1:4040
```

- **Só a porta 8085** (o app HTTP) vai para o túnel. O **Reverb fica em `:8080` e não passa pelo
  túnel** — o navegador (local) conecta direto em `localhost:8080`; o Asaas só precisa alcançar
  o POST do webhook.
- Plano gratuito: a URL muda a **cada reinício** do ngrok → **recadastre o webhook** (§3) toda vez.
  Para não repetir isso, use um domínio reservado (plano pago): `ngrok http --domain=<seu-domínio> 8085`.
- A página de aviso do ngrok aparece para requisições "de navegador" (GET); os POSTs do Asaas não
  passam por ela. Em teste manual com `curl`/navegador, mande o header `ngrok-skip-browser-warning: true`.
- `APP_URL=http://localhost:8085` continua valendo: as URLs de retorno do nosso checkout apontam
  para o navegador **local**, que alcança `localhost` normalmente. O domínio cadastrado na conta
  do Asaas serve ao retorno do **Asaas Checkout** (§3 e §4 do guia) — se a criação do checkout
  falhar por callback, é isso.

## 3. Cadastrar o webhook no sandbox

**Integrações → Webhooks → Adicionar** (ou `POST /v3/webhooks`):

| Campo | Valor |
|---|---|
| URL | `https://<subdomínio>.ngrok-free.app/api/billing/webhooks/asaas` |
| Token de autenticação | O **mesmo** `ASAAS_WEBHOOK_SECRET` (chega no header `asaas-access-token`) |
| Versão da API | 3 |
| Tipo de envio | **Sequencial** (`SEQUENTIALLY`) |
| Situação | Ativo, fila não interrompida |

Eventos: pelo menos os de cobrança e checkout — `PAYMENT_CREATED`, `PAYMENT_UPDATED`,
`PAYMENT_CONFIRMED`, `PAYMENT_RECEIVED`, `PAYMENT_OVERDUE`, `PAYMENT_DELETED`,
`PAYMENT_REFUNDED`, `PAYMENT_CREDIT_CARD_CAPTURE_REFUSED`, `SUBSCRIPTION_CREATED`,
`SUBSCRIPTION_UPDATED`, `CHECKOUT_CREATED`, `CHECKOUT_PAID`, `CHECKOUT_CANCELED`,
`CHECKOUT_EXPIRED` (lista completa e o que o EasyEye faz com cada um: §2 do guia do Asaas).

**IPs:** não use allowlist de IP no ambiente local. Os IPs oficiais do sandbox podem diferir dos
de produção — libere pelo log se houver firewall (o ngrok não tem).

### Conferir que o EasyEye aceita o webhook (fail-closed)

```bash
SECRET='<o mesmo ASAAS_WEBHOOK_SECRET>'
URL='https://<subdomínio>.ngrok-free.app/api/billing/webhooks/asaas'

curl -i -X POST "$URL" -H 'Content-Type: application/json' \
  -H "asaas-access-token: $SECRET" -d '{"id":"evt_test","event":"PAYMENT_CREATED"}'

curl -i -X POST "$URL" -H 'Content-Type: application/json' -d '{}'   # sem token → 401
```

Esperado: com o token, o EasyEye responde **200 na hora** (grava e processa na fila) — mesmo que o
corpo não faça sentido; sem token (ou com token errado), **401** (`{"ok":false,"message":"Unauthorized
webhook."}`). Sem `ASAAS_WEBHOOK_SECRET` configurado, **todo** webhook é recusado com 401
(fail-closed): se nada chega, é o primeiro lugar a olhar.

## 4. Roteiro manual dos 3 meios

Em todos: abrir `http://localhost:8085/register`, preencher o passo 1 (dados da empresa), escolher
o plano no passo 2 com **"Contratar agora"** (`start_mode=checkout`, sem período de teste) e pagar
no passo 3 (`data-test="signup-checkout"`). A confirmação é **sempre pelo webhook** — a tela mostra
"aguardando confirmação" e tem **Já paguei — atualizar** (`data-test="checkout-check"`).

**A. Pix**

1. Passo 3 → forma **Pix** → o EasyEye emite a cobrança e mostra QR + copia-e-cola + validade
   (`data-test="checkout-pix"`, `pix-qr`, `pix-expires`).
2. Pagar: no **painel do sandbox** → *Cobranças* → a cobrança → confirmar o pagamento
   (a confirmação manual de cobranças é o caminho previsto do sandbox). Alternativa fiel ao
   mundo real: **segunda conta sandbox com saldo** paga o payload pelo endpoint
   `POST /v3/pix/qrCodes/pay` (<https://docs.asaas.com/docs/testar-pagamento-de-qrcodes-pix>) —
   exige chave Pix cadastrada na conta de destino (item 7 do §1).
3. Esperado: `PAYMENT_CONFIRMED`/`PAYMENT_RECEIVED` → fatura paga, assinatura ativa e a tela
   liberando sozinha (broadcast) ou no **Já paguei — atualizar**.
4. Se o QR ficar sem validade: a tela mostra "expirado" e a cobrança nova precisa ser emitida
   de novo (o EasyEye descarta instruções vencidas).

**B. Boleto**

1. Passo 3 → forma **Boleto** → linha digitável + **vencimento** + PDF
   (`data-test="checkout-boleto"`, `boleto-due`, `boleto-pdf`).
2. No sandbox não há banco: use a confirmação manual da cobrança no painel (mesmo caminho do Pix).
3. Esperado: fatura paga pela cobrança do boleto (`PAYMENT_RECEIVED`), assinatura ativa.
4. Conferir que o vencimento exibido é o que o gateway devolveu (`dueDate`), não um cálculo local.

**C. Cartão (Asaas Checkout — página hospedada)**

1. Passo 3 → forma **Cartão** → o EasyEye cria o checkout e mostra **"Ir para o pagamento seguro"**
   (`data-test="checkout-hosted"` → `checkout-hosted-go`); o cartão **nunca** passa pelo EasyEye.
2. Pagar na página do Asaas com o **cartão de aprovação do sandbox**: `4444 4444 4444 4444`,
   CVV `123`, validade futura — é o que o spec usa (a doc do Asaas exige só um número fictício com
   dígito verificador válido; se este for recusado, gere outro em
   <https://www.4devs.com.br/gerador_de_numero_cartao_credito>):
   <https://docs.asaas.com/docs/testando-pagamento-com-cartão-de-crédito>.
   **Atenção:** a doc de cartões de teste do Asaas é de **cobranças** (criação de cobrança com
   cartão / checkout próprio). O número de aprovação acima é o que o **spec do EasyEye** usa no
   **Checkout hospedado** e funciona; já os cartões de **recusa** daquela doc **não estão
   confirmados para o Checkout hospedado** (ver D e §9).
3. Esperado: volta para o EasyEye com "aguardando confirmação" → `CHECKOUT_PAID`/`PAYMENT_CONFIRMED`
   → fatura paga e a **recorrência no cartão** criada (`RECURRENT`); no painel do sandbox a
   recorrência anterior (se havia) some e a nova fica.
4. A recorrência nova só é adotada com a assinatura ainda cobrada pelo Asaas, a fatura a pagar e os
   mesmos termos; senão é desfeita no sandbox (ver §4 do guia do Asaas).

**D. Cartão recusado (caso negativo)**

1. Repetir C, mas pagando com um dos cartões de **recusa** do sandbox:
   Mastercard `5184019740373151` · Visa `4916561358240741` (doc do Asaas, passo C.2 — números
   documentados para **cobranças**; **não confirmado para o Checkout hospedado**).
2. Esperado: o checkout **não** adota a recorrência; a fatura continua em aberto; a tela mostra o
   motivo (mensagem genérica — o EasyEye não ecoa o corpo do gateway); o acesso não muda; a
   tentativa fica registrada como falha; no painel do sandbox aparece a cobrança recusada
   (`PAYMENT_CREDIT_CARD_CAPTURE_REFUSED`).
3. Conferir também a idempotência: reenviar o mesmo evento no painel do sandbox não pode duplicar
   efeito na fatura.

## 5. Verificar a trilha

- **API**: `GET /v3/payments/{id}` no sandbox (ou o painel) para o status da cobrança.
- **No EasyEye**: fatura/assinatura da clínica nova (Manager → Assinaturas, ou a tela do painel
  da clínica), e `audit_logs`/`webhook_events` pelos eventos recebidos (`webhook_events` é
  idempotente pelo `id` do evento do Asaas — reentrega não duplica).
- **No ngrok**: `http://127.0.0.1:4040` mostra cada POST do Asaas e a resposta que o EasyEye deu.
  Um 429 aqui é ruim: o Asaas conta como falha e pausa a fila depois de 15 seguidas.

## 6. Limpeza

O roteiro suja **os dois lados**. Nada disso pode ir para produção.

**Local (banco de desenvolvimento).** O spec cria, pelo `/register`, uma **Entity** (clínica), um
**User** admin, uma **Subscription** e faturas — e **não limpa sozinho** (não existe script de
limpeza para este fluxo). Os marcadores são fixos: e-mail `cy-sandbox-<nonce>-<meio>@example.test` e
nome da clínica `Clínica Sandbox <meio>`. Confira e remova à mão por eles (o cascade leva
assinatura, faturas e vínculos):

```sql
-- confira antes de apagar
SELECT id, name, created_at FROM entities WHERE name LIKE 'Clínica Sandbox %' ORDER BY created_at DESC;
SELECT id, email, created_at FROM users WHERE email LIKE 'cy-sandbox-%@example.test' ORDER BY created_at DESC;
```

**Nunca** toque em `CLÍNICA TESTE INTEGRADOR` (nem na entity SaaS).

**Sandbox do Asaas.** Não há limpeza automática por API. No painel, procure o e-mail
`cy-sandbox-<nonce>-<meio>@example.test` e remova o que ficou ligado a ele — cliente, cobranças e
assinatura. Opcional pela API: cancelar o checkout (`POST /v3/checkouts/{id}/cancel`), remover a
assinatura (`DELETE /v3/subscriptions/{id}`), a cobrança (`DELETE /v3/payments/{id}`) e o cliente
(`DELETE /v3/customers/{id}`); conferir em **Cobranças/Assinaturas** que não sobrou nada "em aberto".

**Ambiente.** Ao terminar, **remova o `ngrok` do ar** e desfaça o que for de sandbox no `.env`
(`ASAAS_BASE_URL`, `ASAAS_SECRET`, `ASAAS_WEBHOOK_SECRET`) + `php artisan config:cache`.
**Nunca** deixe chave/URL de sandbox no `.env` de produção.

## 7. Rodar o spec opt-in

O modo é por variável de ambiente: sem `CYPRESS_SANDBOX=1` a pasta `sandbox/` **não é coletada**;
com ela, roda este fluxo. A partir de `e2e/`:

```bash
# num ambiente já configurado (§1, §2, §3)
CYPRESS_SANDBOX=1 env -u ELECTRON_RUN_AS_NODE npx cypress run --browser chrome

# pagando à mão (Pix/boleto pelo painel do sandbox), com pausa antes da espera:
CYPRESS_SANDBOX=1 CYPRESS_SANDBOX_PAUSE=1 env -u ELECTRON_RUN_AS_NODE npx cypress run --browser chrome
```

- **Guardas antes de cadastrar/cobrar** (o spec aborta explicando o que falta): `CYPRESS_SANDBOX=1`;
  `CYPRESS_BASE_URL` em `localhost`/`127.0.0.1` (host remoto **não** é aceito); e a task
  `asaasSandboxConfigured`, que chama `php artisan billing:gateway-environment asaas` e só deixa
  passar quando a credencial efetiva é **sandbox** (endereço de sandbox **e** chave `$aact_hmlg_`).
- **Espere pelo webhook**: o spec faz polling no resumo por até **3 minutos**; no Pix e no boleto,
  confirme o pagamento no painel do sandbox (§4.A e §4.B) durante a espera.
- O que ele cobre: `/register` com `start_mode=checkout` → escolha da forma → cobrança real
  no sandbox → pagamento (Pix/boleto por confirmação no painel ou segunda conta; cartão pelo
  Checkout com o cartão de aprovação) → webhook → **assinatura ativa**; e o negativo do cartão
  recusado. O que ele **não** consegue fazer sozinho: cadastrar o webhook no painel do Asaas,
  pagar um Pix/boleto (não há automação do painel do sandbox) e cadastrar a chave Pix.
- Ele **não** entra na suíte padrão: sem `CYPRESS_SANDBOX=1`, `npx cypress run` continua sem
  depender de sandbox e túnel.

## 8. Problemas comuns

| Sintoma | Causa provável | O que fazer |
|---|---|---|
| Nada chega no ngrok | URL do webhook desatualizada (a URL do ngrok mudou), webhook inativo ou fila pausada no painel | Recadastrar a URL; conferir **Logs** em Integrações → Webhooks; reativar a fila |
| Webhook responde 401 (`Unauthorized webhook.`) | `ASAAS_WEBHOOK_SECRET` diferente do token cadastrado, ou ausente (fail-closed: sem segredo, todo webhook cai em 401) | Igualar os dois (Manager → Gateways tem prioridade sobre o `.env`) e `config:cache` |
| Pagou e a tela não atualiza | Worker da fila parado, fila pausada no Asaas, ou evento não marcado | Subir o worker; conferir `webhook_events`; rodar `php artisan billing:reconcile-overdue` para aplicar pagamento perdido |
| 401 `invalid_environment` | Chave de um ambiente com a URL do outro | Chave `$aact_hmlg_` **com** `ASAAS_BASE_URL=https://api-sandbox.asaas.com` |
| Pix dá 404 ao pagar o QR | Conta sandbox de destino sem chave Pix cadastrada | Cadastrar chave Pix (aleatória/EVP) na conta sandbox |
| Checkout não abre / erro de callback | Domínio do site não cadastrado na conta; conta sem cartão habilitado | §3 e §4 do guia do Asaas |
| 429 no webhook | Flood de reentregas/eventos falsos | Corrigir a causa; não insistir — 15 falhas seguidas pausam a fila |
| Cypress morre com `SIGILL`/`bad option: --ping` | `ELECTRON_RUN_AS_NODE=1` no terminal | `env -u ELECTRON_RUN_AS_NODE npx cypress run --browser chrome` |
| Abriu a página de aviso do ngrok | Requisição de navegador no host gratuito | Header `ngrok-skip-browser-warning: true` (GET manual); POST do Asaas não sofre disso |

## 9. Limites e avisos

- **Sandbox ≠ produção.** Aprovação no sandbox não garante aprovação em produção; alguns recursos
  têm cobertura parcial (<https://docs.asaas.com/docs/o-que-pode-ser-testado>). O boleto não é
  liquidado por banco e o pagamento do Pix depende de chave cadastrada/saldo na conta pagadora.
- **Cartões de teste:** a doc oficial confirma os números de aprovação/recusa para **cobranças**
  (criação de cobrança com cartão / checkout próprio); o uso dos mesmos números no **Checkout
  hospedado** é o esperado, mas **não está confirmado** — se o comportamento divergir, registre
  aqui.
- **Só dados fictícios** no sandbox: nenhum dado real de paciente, cartão ou cliente.
- O spec é **opt-in** por desenho (`CYPRESS_SANDBOX=1`, §7): a suíte padrão continua sem tocar
  gateway (ver [`e2e/README.md`](../../../e2e/README.md)).
- O spec e o modo opt-in **já estão no repositório**; **não há script de limpeza** para este fluxo —
  limpe pelos marcadores do §6.
