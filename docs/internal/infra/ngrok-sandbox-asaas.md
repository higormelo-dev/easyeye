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
| Limpeza | o `sandbox-run.sh` remove o webhook, devolve o `public/hot` e encerra serve/ngrok; o `after()` do spec roda `e2e/scripts/clean-sandbox-signups.php` (contas, clínicas e clientes do Asaas sandbox); checkouts e cobranças já pagas no painel são manuais — §6 |

## Atalho: um comando só

```bash
e2e/scripts/sandbox-run.sh                          # ngrok + app 8086 + webhook + Cypress + limpeza
CYPRESS_SANDBOX_PAUSE=1 e2e/scripts/sandbox-run.sh  # pagamento à mão (Pix/boleto/cartão): não pausa, espera até 5 min; a URL do cartão sai no terminal
```

O script automatiza §1–§3 e §6 deste roteiro: confere que a credencial **efetiva** (Manager vence o `.env`) é de
sandbox (`php artisan billing:gateway-environment asaas`) e que **não há config em cache** nem `APP_ENV` fora de local/testing (lidos pelo bootstrap do próprio Laravel, só
leitura — vale também um `APP_CONFIG_CACHE` definido só no `.env`) — com cache o Laravel ignora o `APP_URL` do ambiente,
então ele aborta e manda rodar `php artisan config:clear` (não limpa por você: o cache é compartilhado com o `composer dev`); sobe `ngrok http 8086`
numa **instância própria** (config temporário mesclado ao seu, com a interface web numa porta livre — a 4040 se estiver
livre — para a URL nunca vir de outro agente ngrok já rodando; vale só o túnel https cujo `config.addr` termina em
`:8086`, teto de 30 s, e sem ele aborta); exporta `APP_URL=<url>` para tudo o que roda depois e sobe
`php artisan serve --port=8086 --no-reload` (não altera o `.env`; `--no-reload` é o que faz o serve repassar o
`APP_URL`; o `composer dev` da 8085 segue de pé — só perde o HMR, veja "Front pelo túnel" abaixo); registra o webhook do
sandbox; roda o Cypress **pela URL pública do túnel** (`CYPRESS_BASE_URL=https://<túnel>`: a origem do navegador é a
mesma do `APP_URL`, então os retornos do checkout voltam para a mesma sessão) e, ao sair — por erro ou Ctrl+C —,
restaura o `public/hot`, remove o webhook (3 tentativas; se ainda falhar sai com **código 3** e imprime o comando manual)
e encerra serve e ngrok. Se o `public/hot` não voltar, e nada pior já tiver sido decidido, sai com **código 4**.

- **Front pelo túnel (`public/hot` e build):** com o Vite dev do `composer dev` rodando existe `public/hot`, e então o
  Blade injeta `http://localhost:5173/…` na página — numa página `https://` do túnel o Chrome bloqueia isso (mixed
  content) e o `/register` abre em branco. Por isso o script **move** o `public/hot` para o diretório temporário dele
  **antes** de subir o serve e o **restaura** na saída (normal, erro, Ctrl+C/TERM) sempre que o backup existir — arquivo
  ou symlink, inclusive symlink pendente, e mesmo se o sinal chegar no meio do `mv` —, a não ser que o `public/hot` tenha
  reaparecido (aí mantém o atual). Sem o hot o Laravel serve `public/build`: a 8085 também passa a
  servir o build durante a rodada (sem HMR), então **não é preciso parar o `composer dev`**. O caminho temporário aparece
  no log (`public/hot movido para …`). O script **aborta** sem `public/build/manifest.json` (sem hot e sem build não há
  front) e **avisa, sem abortar,** quando o manifest é mais velho que algum arquivo de `resources/js`/`resources/css`
  (`build desatualizado, front antigo no teste` → `npm run build`; você pode estar testando o front antigo de propósito).
  **Recuperação** — `kill -9` não roda o trap e deixa o hot movido: `mv <tmp>/hot.easyeye-e2e public/hot` (caminho no log;
  `composer dev`/`npm run dev` também recriam o arquivo).
- **Códigos de saída do script:** o do Cypress; `1` aborto antes do teste (inclui `public/hot` reaparecido, abaixo); `3`
  Cypress passou mas o **webhook não foi removido**; `4` Cypress (e webhook) passaram mas o **`public/hot` não foi
  restaurado** — o HMR do `composer dev` segue fora, o backup fica no diretório temporário e a mensagem traz o `mv`
  manual. Status não-zero anterior é preservado; com `3` e `4` juntos vale o `3`.
- **Risco residual — não reinicie o `npm run dev`/`composer dev` durante a rodada:** o Vite recria o `public/hot` e o
  Blade volta a injetar `http://localhost:5173` (página em branco no `https://`; a CSP também reabriria o Vite). Não há
  vigia em background: o script confere o `public/hot` **uma vez** logo antes do `cypress run` (reapareceu → aborta com
  status 1 e mantém o novo) e no cleanup (reapareceu → avisa e mantém o novo). Reiniciar o Vite depois dessa checagem
  derruba só aquela rodada.
- **Sem realtime no modo túnel:** o build aponta o Reverb para `localhost:8080` e o túnel só expõe a 8086. Numa página
  `https://` o `ws://` é bloqueado (mixed content) e não há `wss://` — a conexão falha em silêncio no console, e
  `VITE_REVERB_SCHEME=http` **não** resolve. O spec espera o webhook pela API (`waitForWebhook`), então a rodada só perde o
  update ao vivo da tela. O bloqueio da CSP que isso causa (`[CSP] connect-src bloqueou wss://localhost:8080/…`) é
  tolerado pelo `e2e/cypress/support/e2e.js` **só** com `CYPRESS_SANDBOX_TUNNEL_HOST` setado (o script exporta); fora do
  modo túnel um bloqueio de CSP continua reprovando o caso. Futuro (fora do escopo): 2º túnel para a 8080 +
  `VITE_REVERB_*` no build.

- **Guard do spec:** `CYPRESS_BASE_URL` em `localhost`/`127.0.0.1` ou no host do túnel que o script exporta em
  `CYPRESS_SANDBOX_TUNNEL_HOST`; a task `asaasSandboxConfigured` só aprova o host do túnel se a API local do ngrok
  **desta** instância (`CYPRESS_SANDBOX_NGROK_API`) o reportar apontando para a porta do serve
  (`CYPRESS_SANDBOX_PORT`). No modo túnel o Cypress usa o User-Agent `EasyEye-E2E-Sandbox`: o plano free do ngrok
  serve uma página de aviso a User-Agent de navegador, que quebraria o fluxo.
- **Pré-requisitos:** ngrok instalado com authtoken válido (`ngrok config add-authtoken <token>`; token inválido → o
  script aborta com essa dica), `jq` e `curl`, o **worker da fila rodando** (`composer dev` sobe um) — ele processa o webhook que o
  Asaas envia ao túnel; o script avisa se não achar `queue:work` — e o **build do front** (`npm run build`;
  `public/build/manifest.json` é obrigatório e deve estar atual — "Front pelo túnel" acima).
- **Webhook:** `php artisan billing:asaas-webhook {register|remove|list} --url=<url do túnel>` — **só sandbox** (aborta
  em produção/credencial desconhecida, sem chamar a API). `register` cria ou atualiza (idempotente) o webhook
  `<url>/api/billing/webhooks/asaas` com o `authToken` = `webhook_secret` do Manager (mín. 32 caracteres; nunca
  impresso) — por isso `--url` é restrita: só `https://<rótulo>.<sufixo de config('billing.sandbox_webhook_hosts')>`
  (`.ngrok-free.app`, `.ngrok.app`, `.ngrok.io`, `.ngrok-free.dev`), sem porta, IP, localhost/rede privada, userinfo,
  caminho, query nem fragmento (exit 2, nada enviado à API). A allowlist é só a 2ª camada: `*.ngrok-free.app` é
  domínio de qualquer pessoa, então o `register` também exige **prova de túnel local** — consulta a API do ngrok em
  `--ngrok-api` (padrão `http://127.0.0.1:4040`; o `sandbox-run.sh` passa a porta do seu `web_addr`; só IP numérico
  `127.0.0.1`/`[::1]` — `localhost` é nome resolvível e não vale —, sem redirecionamento e sem proxy do ambiente) e só
  segue se a url for o `public_url` exato de um túnel (item de uma LISTA JSON `tunnels`) cujo `config.addr` aponta para
  `127.0.0.1`/`[::1]` (por isso o script sobe `ngrok http 127.0.0.1:8086`); API
  fora do ar, resposta inválida ou túnel de outro dono = exit 2, nada enviado ao Asaas. `remove` e `list` não pedem a
  prova (não enviam segredo; o `remove` roda na limpeza, com o ngrok possivelmente caído). O comando também só roda com
  `APP_ENV` local/testing (exit 1 antes de qualquer chamada). O webhook é achado pela url com esquema/host sem diferença de
  caixa e caminho exato, `enabled`, não interrompido, `apiVersion` 3, `SEQUENTIALLY` e os eventos `PAYMENT_*`, `SUBSCRIPTION_*` e
  `CHECKOUT_*` que o app trata; `remove` apaga só o webhook dessa url; `list` mostra os webhooks sem o token. Webhooks
  que não são os da sua url (ex.: um antigo apontando para outro túnel) não são tocados.
- **Cliente sem CPF/CNPJ:** o Asaas recusa criar a assinatura/cobrança de um cliente sem documento (`POST
  /v3/subscriptions` → 400 `invalid_object`, "Para criar esta cobrança é necessário preencher o CPF ou CNPJ do
  cliente."), e o CNPJ é opcional no `/register`: a clínica sem ele cai em 502 (`gateway_error`) ao escolher Pix/boleto.
  O spec sandbox preenche um CNPJ válido e novo a cada caso (único: `entities.national_registration`). A regra de
  negócio para o `/register` com "Contratar agora" exigir o documento está pendente de decisão.
- **Cliente sem endereço:** o Asaas Checkout exige telefone e endereço no cadastro do cliente; o app envia o endereço da
  clínica quando está completo e, se o Asaas recusar o cliente, abre o Checkout **sem** ele (a página coleta os dados) e
  registra o aviso em `billing_logs`.

O roteiro manual abaixo continua valendo para conferir cada passo à mão.

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
   **Identificação vazia**: a página do Asaas abre **sem** os dados do pagador. O EasyEye manda o `customer` (cliente já
   cadastrado), mas o Checkout só aceita cliente (`customer` ou `customerData`, nunca os dois) com **endereço completo**
   (`address`, `addressNumber`, `postalCode`, `province`; confirmado no sandbox: sem eles o `POST /v3/checkouts` volta
   400 `invalid_object` "O campo address deve existir para o customer informado"/"…deve ser informado"). O cadastro da
   clínica (/register) não tem endereço, então o app repete o pedido **sem cliente** (`customerOmitted`, registrado em
   `billing_logs` só com os nomes dos campos faltantes) e quem paga digita tudo na página; o vínculo com a fatura é pelo
   `externalReference`/`checkoutSession`. Clínica com endereço completo no cadastro (painel) segue com o cliente anexado.
   A página é um acordeão em três etapas — **Identificação** (E-mail, Nome completo, CPF ou CNPJ, Celular → Avançar),
   **Dados de endereço** (o CEP preenche rua, bairro e cidade; falta o Número → Avançar) e **Dados de pagamento**
   (cartão → Pagar) —, com reCAPTCHA no canto e o limite de 60 min do checkout.
2. Pagar na página do Asaas com o **cartão de aprovação do sandbox**: `4444 4444 4444 4444`,
   CVV `123`, validade futura — é o que o spec usa (a doc do Asaas exige só um número fictício com
   dígito verificador válido; se este for recusado, gere outro em
   <https://www.4devs.com.br/gerador_de_numero_cartao_credito>):
   <https://docs.asaas.com/docs/testando-pagamento-com-cartão-de-crédito>.
   **Atenção:** a doc de cartões de teste do Asaas é de **cobranças** (criação de cobrança com
   cartão / checkout próprio). O número de aprovação acima é o que o **spec do EasyEye** usa no
   **Checkout hospedado** e funciona; já os cartões de **recusa** daquela doc **não estão
   confirmados para o Checkout hospedado** (ver D e §9).
   **O cartão é pago FORA do Cypress.** O "Pagar" do Asaas Checkout é protegido por **reCAPTCHA Enterprise**, que
   **pontua o navegador**: num navegador automatizado o `POST /checkoutSession/pay` responde
   `{"success":false,"message":{"text":"Tente novamente e verifique o recaptcha."},"recaptchaV2Enabled":true}` e a página
   recarrega vazia (verificado num Chrome 155 real conduzido por puppeteer, com cartões aprovado e recusado). A janela do
   Cypress carrega `navigator.webdriver`, flags de automação e — no modo túnel — o User-Agent `EasyEye-E2E-Sandbox` (o
   contorno do aviso do ngrok), e é recusada **também com o clique de uma pessoa nela**. Por isso o spec **não preenche
   nem paga** nada no Asaas: sem `CYPRESS_SANDBOX_PAUSE=1` os dois casos de cartão são **pulados**; com ele o spec
   cadastra, escolhe cartão, abre a sessão (`POST /signup-checkout/contract` → `checkout_url`), **afirma que a página do
   Asaas abre com o valor da fatura** (só leitura, no `cy.origin`), **imprime a URL em destaque no terminal** (bloco entre
   linhas `====`) e espera o webhook por até **5 minutos**. **Você abre a URL no seu navegador** (não na janela do
   Cypress), digita a identificação e o endereço (a página vem vazia, ex.: CEP `01310-100`, número `1000`) e paga com o
   cartão de teste. Não precisa de `--headed`: o Cypress só espera. O spec **não pausa** (sem `cy.pause()`).
   **Depois do webhook** o spec **visita a página de retorno** que o Asaas usaria na `successUrl`:
   `/signup-checkout/return?checkout_return=success&checkout_invoice=<uuid da fatura>` (rota `signup-checkout.return`,
   montada por `HostedCheckoutService::callbackUrl`; `cancel` e `expired` no mesmo formato). **Não há token assinado**: a
   página vale pela sessão do cadastro e só mostra fatura da própria clínica (de outra clínica volta `null`). O
   **redirecionamento** do Asaas para essa URL, no navegador de quem pagou, é só UX e o spec **não o exercita**.
3. Esperado: volta para o EasyEye com "aguardando confirmação" → `CHECKOUT_PAID`/`PAYMENT_CONFIRMED`
   → fatura paga e a **recorrência no cartão** criada (`RECURRENT`); no painel do sandbox a
   recorrência anterior (se havia) some e a nova fica.
4. A recorrência nova só é adotada com a assinatura ainda cobrada pelo Asaas, a fatura a pagar e os
   mesmos termos; senão é desfeita no sandbox (ver §4 do guia do Asaas).

**D. Cartão recusado (caso negativo)**

1. Repetir C, mas pagando com um dos cartões de **recusa** do sandbox:
   Mastercard `5184 0197 4037 3151` · Visa `4916 5613 5824 0741` (doc do Asaas, passo C.2 — números
   documentados para **cobranças**; **não confirmado para o Checkout hospedado**). O spec imprime o Mastercard.
2. Esperado: o checkout **não** adota a recorrência; a fatura continua em aberto; a tela mostra o
   motivo (mensagem genérica — o EasyEye não ecoa o corpo do gateway); o acesso não muda; a
   tentativa fica registrada como falha; no painel do sandbox aparece a cobrança recusada
   (`PAYMENT_CREDIT_CARD_CAPTURE_REFUSED`).
3. Conferir também a idempotência: reenviar o mesmo evento no painel do sandbox não pode duplicar
   efeito na fatura.
4. O spec afirma pela API (`/signup-checkout/summary`) que a fatura virou `failed` e continua pagável (`can_pay`), visita a
   página de retorno `checkout_return=cancel` ("não foi concluído") e confere que a **nova tentativa** (`POST
   /signup-checkout/contract` de novo) devolve uma `checkout_url` que **abre com o mesmo valor**. Se é a mesma sessão ou
   outra o spec só registra no log: pelo código a sessão recusada vira `failed` e não é reaproveitada
   (`HostedCheckoutService::usableFor` só reusa sessão `active`), mas isso depende de o webhook de recusa tê-la ligado à
   sessão (`checkoutSession`).

## 5. Verificar a trilha

- **API**: `GET /v3/payments/{id}` no sandbox (ou o painel) para o status da cobrança.
- **No EasyEye**: fatura/assinatura da clínica nova (Manager → Assinaturas, ou a tela do painel
  da clínica), e `audit_logs`/`webhook_events` pelos eventos recebidos (`webhook_events` é
  idempotente pelo `id` do evento do Asaas — reentrega não duplica).
- **No ngrok**: `http://127.0.0.1:4040` mostra cada POST do Asaas e a resposta que o EasyEye deu (pelo `sandbox-run.sh` a porta é a que ele imprime em "API de túneis em …": a 4040 só se estiver livre).
  Um 429 aqui é ruim: o Asaas conta como falha e pausa a fila depois de 15 seguidas.

## 6. Limpeza

O roteiro suja **os dois lados**. Nada disso pode ir para produção.

**Local (banco de desenvolvimento) e Asaas sandbox — automática.** O spec cria, pelo `/register`, uma **Entity**
(clínica), um **User** admin, uma **Subscription** e faturas, e um **cliente** no Asaas sandbox
(`externalReference` = id da clínica) — mais, no cartão, uma sessão do **Checkout hospedado**. Cada e-mail gerado é
registrado ANTES do cadastro em `e2e/cypress/.sandbox-run/<nonce>.json` (fora do git) e o `after()` do spec roda
`e2e/scripts/clean-sandbox-signups.php` com esse estado:

```bash
SANDBOX_CLEAN_STATE=e2e/cypress/.sandbox-run/<nonce>.json php artisan tinker --execute="require 'e2e/scripts/clean-sandbox-signups.php';"
SANDBOX_CLEAN_ALL=1 php artisan tinker --execute="require 'e2e/scripts/clean-sandbox-signups.php';"   # restos de rodadas mortas (opt-in)
SANDBOX_CLEAN_DRY_RUN=1 SANDBOX_CLEAN_ALL=1 php artisan tinker --execute="require 'e2e/scripts/clean-sandbox-signups.php';"   # só lista
```

- **Guardas** (falhou, aborta com exit 1, nesta ordem, antes de qualquer chamada ao Asaas, de ler a credencial e de
  qualquer DELETE): `APP_ENV` local/testing; **todo destino do banco efetivo** — `DB_URL` e suas opções, host, database,
  `read[]`, `write[]` e a conexão viva — em host local e num banco da lista `E2E_ALLOWED_DATABASES` (o mesmo guard do
  `clean-eye-images.php`: um app local apontado a um banco de produção apagaria clínicas de verdade); e credencial
  efetiva do Asaas em sandbox (`billing:gateway-environment asaas`).
- **O que entra:** no modo da rodada (padrão), só os usuários do arquivo de estado, criados depois do início dela — uma
  conta preexistente que apenas combine com `cy-sandbox-%@example.test` **não** é tocada; sem estado (e sem
  `SANDBOX_CLEAN_ALL=1`) o script aborta e lista os estados pendentes. Nos dois modos a clínica só entra se **todos** os
  usuários dela são desse conjunto **e** o nome começa com `Clínica Sandbox `; usuário só sai se todas as clínicas dele
  saem. O que não entra aparece como `IGNORADO`. **Nunca** toca em `CLÍNICA TESTE INTEGRADOR` (nem na entity SaaS).
- **Asaas primeiro:** por clínica, os clientes por `externalReference` (conferindo a referência de cada linha, com listas
  validadas e paginadas), as assinaturas deles e as sessões do Checkout hospedado (`billing_hosted_checkouts`: cancela a
  sessão e, se foi paga, a recorrência que nasceu dela). Cada remoção é **confirmada** (`deleted:true` ou 404).
  Qualquer incerteza — lista inválida, status inesperado, remoção não confirmada, sessão paga sem recorrência
  registrada — **mantém** a clínica e o usuário no banco, sai com exit 2 e deixa o estado para a próxima execução
  (idempotente).
- **Trava antes do Asaas, banco no fim:** o script abre uma transação, trava (`FOR UPDATE`) clínicas (ordenadas) e
  depois usuários, decide a elegibilidade JÁ SOB a trava e a segura até o commit — um vínculo novo (usuário real na
  clínica, usuário de teste em outra) espera, então não nasce entre a decisão e os DELETEs, e só clínica elegível chega ao
  Asaas. A transação fica aberta durante as chamadas remotas (script de teste em banco de dev). No fim, revalida na mesma
  transação e apaga (como o `clean-billing.php`: o cascade leva assinatura, faturas, vínculos e checkouts; o resto sai à
  mão, inclusive os `billing_logs`); mudou algo → a clínica é **pulada** e reportada. Sem restos: `nada a limpar`.
- **Opt-out:** `CYPRESS_SANDBOX_KEEP=1` (repassado pelo `sandbox-run.sh`, que herda o ambiente) mantém tudo de pé para
  investigar uma falha; limpe depois com o comando acima. Se a limpeza do `after()` sair com código ≠ 0 (ex.: cobrança já
  paga impede apagar o cliente no Asaas), o spec só **registra** (`cy.log` + `console.warn` com o comando manual): a
  limpeza nunca reprova um caso verde.
- **Webhook ainda registrado:** a limpeza roda antes de o `sandbox-run.sh` remover o webhook, então o Asaas pode mandar
  eventos de cancelamento/remoção de contas que acabaram de sumir. Cobrança que o app não conhece é registrada como
  `ignored_unknown_charge`; se aparecer outro ruído no log da rodada, é esse o motivo.

**Sandbox do Asaas — o que a limpeza não alcança.** Cobranças **já pagas** no painel (e o que a limpeza marcar como incerto). Procure o
e-mail `cy-sandbox-<nonce>-<meio>@example.test` e remova à mão; opcional pela API: cancelar o checkout
(`POST /v3/checkouts/{id}/cancel`) e a cobrança (`DELETE /v3/payments/{id}`); conferir em **Cobranças/Assinaturas** que
não sobrou nada "em aberto".

**Ambiente.** Ao terminar, **remova o `ngrok` do ar** e desfaça o que for de sandbox no `.env`
(`ASAAS_BASE_URL`, `ASAAS_SECRET`, `ASAAS_WEBHOOK_SECRET`) + `php artisan config:cache`.
**Nunca** deixe chave/URL de sandbox no `.env` de produção.

## 7. Rodar o spec opt-in

O modo é por variável de ambiente: sem `CYPRESS_SANDBOX=1` a pasta `sandbox/` **não é coletada**;
com ela, roda este fluxo. A partir de `e2e/`:

```bash
# num ambiente já configurado (§1, §2, §3)
CYPRESS_SANDBOX=1 env -u ELECTRON_RUN_AS_NODE npx cypress run --browser chrome

# pagando à mão (Pix/boleto pelo painel do sandbox; cartão no SEU navegador, pela URL impressa no terminal)
CYPRESS_SANDBOX=1 CYPRESS_SANDBOX_PAUSE=1 env -u ELECTRON_RUN_AS_NODE npx cypress run --browser chrome
```

`CYPRESS_SANDBOX_PAUSE=1` (valor exatamente `1`) **não pausa o teste**: o spec só instrui no terminal e no log e o
polling do webhook corre enquanto você paga — um `cy.pause()` deixava o teste parado até o Resume mesmo com o webhook já
processado. Sem `PAUSE=1` os dois casos de **cartão** são **pulados** (o reCAPTCHA Enterprise do Asaas recusa o navegador
do Cypress; Pix e boleto não têm essa limitação).

- **Guardas antes de cadastrar/cobrar** (o spec aborta explicando o que falta): `CYPRESS_SANDBOX=1`;
  `CYPRESS_BASE_URL` em `localhost`/`127.0.0.1` (a única exceção é o host do túnel que o
  `sandbox-run.sh` exporta, provado na API local do ngrok dele; qualquer outro host remoto **não** é aceito); e a task
  `asaasSandboxConfigured`, que chama `php artisan billing:gateway-environment asaas` e só deixa
  passar quando a credencial efetiva é **sandbox** (endereço de sandbox **e** chave `$aact_hmlg_`).
- **Espere pelo webhook**: o spec faz polling no resumo por até **3 minutos** (**5** com `PAUSE=1`); no Pix e no boleto,
  confirme o pagamento no painel do sandbox (§4.A e §4.B) durante a espera; no cartão, pague pela URL impressa no
  terminal (§4.C). A volta pela `successUrl` não ativa nada, só o webhook — e o spec visita a página de retorno depois
  dele, não antes.
- O que ele cobre: `/register` com `start_mode=checkout` → escolha da forma → cobrança real
  no sandbox → pagamento (Pix/boleto por confirmação no painel ou segunda conta; cartão por você, no seu navegador, na
  página do Asaas Checkout) → webhook → **assinatura ativa**; e o negativo do cartão
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
