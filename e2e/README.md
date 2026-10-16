# EasyEye — Testes E2E (Cypress)

Suíte de testes end-to-end **100% autocontida** neste diretório (`e2e/`):
`package.json` próprio, `node_modules/` próprio. Nada aqui toca o Laravel/Vue
do repositório — e nenhum arquivo fora de `e2e/` deve ser criado/alterado
pelos testes.

## Pré-requisitos

1. **App no ar em `http://localhost:8085`** (ex.: `php artisan serve --port=8085`
   na raiz do projeto), apontando para o banco de dev `easyeye` (pgsql) com os
   usuários seedados da tabela abaixo.
2. **Assets**: Vite dev server em `:5173` **ou** build de produção existente
   (`public/build/manifest.json` presente e sem `public/hot`).
3. **Google Chrome** instalado (`/usr/bin/google-chrome`). Rodamos sempre com
   `--browser chrome` — o Electron embutido pode falhar por falta de `libXss`.
4. Node 24+.

Para apontar para outra URL: `CYPRESS_BASE_URL=http://outra:porta npx cypress run ...`

## Como rodar

```bash
cd e2e
npm install

# Modo interativo (Cypress App)
npx cypress open --browser chrome     # ou: npm run cy:open

# Headless (CI/terminal)
npx cypress run --browser chrome      # ou: npm run cy:run

# Um spec só
npx cypress run --browser chrome --spec cypress/e2e/00-smoke.cy.js
```

> **Troubleshooting**: se o runner morrer com `SIGILL` ou `bad option: --ping`,
> o ambiente do terminal está com `ELECTRON_RUN_AS_NODE=1` exportado (comum em
> terminais embutidos em apps Electron, ex.: VS Code). Rode com:
> `env -u ELECTRON_RUN_AS_NODE npx cypress run --browser chrome`

## Modo sandbox (opt-in)

O spec `cypress/e2e/sandbox/signup-checkout-sandbox.cy.js` homologa o cadastro com pagamento no ato
contra o **Asaas Sandbox de verdade** (sem `cy.intercept` para cobrança ou webhook) e só é coletado
com a variável abaixo — sem ela a pasta `sandbox/` fica fora da suíte padrão, e nenhuma execução
comum toca gateway:

**Um comando só** (ngrok + app na 8086 + webhook do sandbox + Cypress + limpeza, mesmo em erro ou Ctrl+C):

```bash
e2e/scripts/sandbox-run.sh                      # roda o spec de sandbox
CYPRESS_SANDBOX_PAUSE=1 e2e/scripts/sandbox-run.sh   # pagamento à mão no Asaas: inclui o cartão (a URL sai no terminal)
e2e/scripts/sandbox-run.sh --spec cypress/e2e/sandbox/signup-checkout-sandbox.cy.js   # argumentos vão ao `cypress run`
```

**O cartão é pago fora do Cypress.** O "Pagar" do Asaas Checkout é protegido por **reCAPTCHA Enterprise**, que pontua o
navegador: a janela do Cypress (`navigator.webdriver`, flags de automação e o User-Agent `EasyEye-E2E-Sandbox` do modo
túnel) é recusada — também com o clique de uma pessoa nela (`POST /checkoutSession/pay` → "Tente novamente e verifique o
recaptcha." e a página recarrega vazia). Por isso os dois casos de cartão (aprovado e recusado) **só rodam com
`CYPRESS_SANDBOX_PAUSE=1`**: o spec cadastra, escolhe cartão, abre a sessão do Asaas só para **ler** (afirma que a página
abre com o valor da fatura), **imprime a URL do checkout em destaque no terminal** (bloco entre linhas `====`) e espera o
webhook por até **5 minutos** sem preencher nada. Você abre a URL **no seu navegador** (não na janela do Cypress) e paga
com o cartão de teste: **`4444 4444 4444 4444` (aprovado)** ou **`5184 0197 4037 3151` (recusado)**, validade futura,
CVV `123`. A página do Asaas vem **vazia** (o Checkout só aceita cliente com endereço completo e o cadastro da clínica não
tem endereço): digite e-mail, nome, CPF/CNPJ, celular e endereço (ex.: CEP `01310-100`, número `1000`). Não precisa de
`--headed`: o Cypress só espera. Depois do webhook o spec **visita a página de retorno** que o Asaas usaria na
`successUrl` — `/signup-checkout/return?checkout_return=success` (ou `cancel`) `&checkout_invoice=<uuid da fatura>`, sem
token assinado (vale pela sessão do cadastro) — e afirma o status; o **redirecionamento** do Asaas para ela, no seu
navegador, é só UX e o spec **não o exercita**. Sem `PAUSE=1` os dois casos de cartão são **pulados** (antes de
cadastrar qualquer coisa; Pix e boleto rodam normalmente). `PAUSE=1` **não pausa** o teste (sem `cy.pause()`): o spec
instrui no terminal e no log, o polling do webhook corre enquanto você paga e o teto da espera sobe de 3 para 5 minutos.

Ele confere `billing:gateway-environment asaas` == `sandbox` (credencial efetiva: a do Manager vence o `.env`) e que
**não há config em cache** nem `APP_ENV` fora de local/testing (lidos pelo bootstrap do próprio Laravel, só leitura, então
vale também `APP_CONFIG_CACHE` definido no `.env`: com cache o Laravel ignora o `APP_URL` do ambiente — o script aborta e
manda rodar `php artisan config:clear`, sem limpar o cache por você), sobe `ngrok http 8086`
numa instância própria (config temporário com a interface web numa porta livre: a URL vem só do ngrok que ele iniciou,
do túnel https cujo `config.addr` aponta para `:8086`; sem esse túnel, aborta), exporta `APP_URL=<url do túnel>` (serve,
webhook e Cypress leem a mesma configuração; o `.env` não é tocado e o `composer dev` da 8085 segue de pé), **move o
`public/hot` do Vite dev** para o diretório temporário do script (veja abaixo), sobe
`php artisan serve --port=8086 --no-reload`, registra o webhook com
`php artisan billing:asaas-webhook register --url=<url do túnel>` (só sandbox; `--url` só aceita
`https://<rótulo>.<domínio de config('billing.sandbox_webhook_hosts')>` — ngrok — sem porta, IP, localhost, caminho nem
credenciais, **e** o `register` exige prova de túnel local: consulta `--ngrok-api` — só IP numérico `127.0.0.1`/`[::1]`, a porta do `web_addr` do
ngrok do script, sem proxy nem redirecionamento — e só aceita a url que for o `public_url` de um túnel (lista JSON) com
destino numérico em loopback (o script sobe `ngrok http 127.0.0.1:8086`), porque o `webhook_secret`
viaja como `authToken` para o host cadastrado e `*.ngrok-free.app` é domínio de qualquer pessoa; o comando só roda com
`APP_ENV` local/testing), roda o Cypress **pela URL pública
do túnel** (a origem do navegador é a mesma do `APP_URL` e dos retornos do checkout; um User-Agent próprio evita a página
de aviso do ngrok free) e, na saída, restaura o `public/hot`, remove o webhook (3 tentativas; se ainda falhar, sai com
código 3 e imprime o comando manual; se o `public/hot` não voltar, código 4 — veja abaixo) e mata serve e ngrok. Precisa de: authtoken do ngrok válido
(`ngrok config add-authtoken <token>`), `jq`, do **worker da fila rodando** (o `composer dev` sobe um) — é ele que
processa o webhook do Asaas — e do **build do front** (`npm run build`). A suíte padrão continua sem tocar gateway.

- **`public/hot` e build:** com o Vite dev rodando (`composer dev`) o Blade injeta `http://localhost:5173/…`, que o Chrome
  bloqueia numa página `https://` do túnel (mixed content: `/register` em branco). O script move o `public/hot` antes do
  serve e o restaura na saída (normal, erro, Ctrl+C/TERM) sempre que o backup existir — arquivo ou symlink, inclusive
  symlink pendente, e mesmo se o sinal chegar no meio do `mv` —, a não ser que o `public/hot` tenha reaparecido (aí
  mantém o atual); sem o hot o Laravel serve `public/build`, e a 8085 também (sem HMR) durante a rodada — não precisa parar
  o `composer dev`. O caminho temporário sai no log (`public/hot movido para …`). Aborta sem `public/build/manifest.json`
  e **avisa, sem abortar,** se o manifest for mais velho que algum arquivo de `resources/js`/`resources/css`
  (`build desatualizado, front antigo no teste` → `npm run build`). `kill -9` não roda o trap e deixa o hot movido:
  `mv <tmp>/hot.easyeye-e2e public/hot` (caminho no log; `composer dev`/`npm run dev` também recriam o arquivo).
- **Limpeza das contas do spec:** cada e-mail que o spec gera é registrado (antes do cadastro) em
  `e2e/cypress/.sandbox-run/<nonce>.json` (ignorado pelo git) e o `after()` roda `e2e/scripts/clean-sandbox-signups.php`
  com esse estado: apaga do Asaas sandbox (clientes por `externalReference` = id da clínica, assinaturas e sessões do
  Checkout hospedado, tudo paginado e com cada remoção confirmada) e do banco de DEV **só** os usuários da rodada e as
  clínicas `Clínica Sandbox …` cujos usuários são todos dela — uma conta anterior que só combine com o padrão não é
  tocada. Guardas (aborta com exit 1 antes de qualquer chamada ao Asaas): `APP_ENV` local/testing, **todo destino do banco
  efetivo** (`DB_URL`, host, database, `read[]`/`write[]`) local e da lista `E2E_ALLOWED_DATABASES`, e credencial efetiva
  do Asaas em sandbox. O script trava (`FOR UPDATE`) clínicas e usuários ANTES de tocar no Asaas e segura a trava até o commit (um vínculo novo espera; só clínica elegível sob a trava chega ao Asaas), com revalidação final.
  Incerteza no Asaas mantém a clínica (exit 2; o estado fica e a próxima execução retoma). À mão:
  `SANDBOX_CLEAN_STATE=e2e/cypress/.sandbox-run/<nonce>.json php artisan tinker --execute="require 'e2e/scripts/clean-sandbox-signups.php';"`;
  restos de rodadas mortas, sem estado: `SANDBOX_CLEAN_ALL=1` (varredura por padrão, **opt-in**); `SANDBOX_CLEAN_DRY_RUN=1`
  só lista. `CYPRESS_SANDBOX_KEEP=1` mantém tudo de pé para investigar uma falha. Cobranças já pagas no painel ficam
  manuais ([§6 do roteiro](../docs/internal/infra/ngrok-sandbox-asaas.md)).
- **Códigos de saída:** o do Cypress; `1` aborto antes do teste (inclui `public/hot` reaparecido, abaixo); `3` Cypress
  passou mas o **webhook não foi removido**; `4` Cypress (e webhook) passaram mas o **`public/hot` não foi restaurado** (o
  HMR do `composer dev` segue fora; o backup fica no diretório temporário e a mensagem traz o `mv` manual). Status
  não-zero anterior é preservado; com `3` e `4` juntos vale o `3`.
- **Não reinicie o `npm run dev`/`composer dev` durante a rodada:** o Vite recria o `public/hot` e o Blade volta a
  injetar `http://localhost:5173` (página em branco no `https://`). Não há vigia em background: o script confere o
  `public/hot` **uma vez** logo antes do `cypress run` (reapareceu → aborta com status 1 e mantém o novo) e no cleanup
  (reapareceu → avisa e mantém o novo). Reiniciar o Vite depois dessa checagem derruba só aquela rodada.
- **Sem realtime no modo túnel:** o build aponta o Reverb para `localhost:8080` e o túnel só expõe a 8086; numa página
  `https://` o `ws://` é bloqueado (mixed content) e não há `wss://` — falha em silêncio no console, e
  `VITE_REVERB_SCHEME=http` não resolve. O spec espera o webhook pela API (`waitForWebhook`), então só se perde o update ao
  vivo da tela. O bloqueio da CSP que isso causa (`[CSP] connect-src bloqueou wss://localhost:8080/…`) é tolerado pelo
  `cypress/support/e2e.js` **só** com `CYPRESS_SANDBOX_TUNNEL_HOST` setado (o script exporta); fora do modo túnel um
  bloqueio de CSP continua reprovando o caso. Futuro (fora do escopo): 2º túnel para a 8080 + `VITE_REVERB_*` no build.

O Cypress isolado (ambiente já montado à mão, §1–§3 do roteiro) continua valendo:

```bash
cd e2e
CYPRESS_SANDBOX=1 env -u ELECTRON_RUN_AS_NODE npx cypress run --browser chrome
# com cartão (pago à mão, fora do Cypress: a URL do checkout sai no terminal):
CYPRESS_SANDBOX=1 CYPRESS_SANDBOX_PAUSE=1 env -u ELECTRON_RUN_AS_NODE npx cypress run --browser chrome
```

O spec exige `CYPRESS_BASE_URL` em `localhost`/`127.0.0.1` — ou, só pelo script, na URL pública do túnel
(`CYPRESS_SANDBOX_TUNNEL_HOST`, que a task confere na API local do ngrok apontando para a porta do serve) —,
`ASAAS_BASE_URL` de sandbox, chave `$aact_hmlg_` (a task `asaasSandboxConfigured` confere com
`php artisan billing:gateway-environment asaas` — o mesmo comando do script) e o webhook do sandbox apontando para o túnel.

Roteiro completo (pré-requisitos, webhook, Pix/boleto/cartão, cartão recusado e limpeza):
[`docs/internal/infra/ngrok-sandbox-asaas.md`](../docs/internal/infra/ngrok-sandbox-asaas.md).

## Credenciais / Perfis (fixtures/profiles.json)

Perfis **com credencial seedada** no banco de dev — usar via `cy.loginAs('<chave>')`:

| Chave              | E-mail                      | Senha           | Rule      | Landing pós-login        |
|--------------------|-----------------------------|-----------------|-----------|--------------------------|
| `clinic.admin`     | admin@clinicateste.com      | Admin@123       | admin     | /panel/dashboard         |
| `clinic.doctor`    | dra.ana@clinicateste.com    | Medico@123      | doctor    | /panel/dashboard         |
| `clinic.secretary` | secretaria@clinicateste.com | Secretaria@123  | secretary | /panel/dashboard         |
| `clinic.financial` | financeiro@clinicateste.com | Financeiro@123  | financial | /panel/dashboard         |
| `saas.admin`       | higor_ap89@icloud.com       | Admin@2024!     | admin     | /panel/manager/dashboard |

Entity clínica: **CLÍNICA TESTE INTEGRADOR** · Entity SaaS: **medicalgroup**.

Perfis **de cenário** `billing.*` (senha `CyBilling@2026`, e-mails `@cy-billing.test`) e
`billing.patient` (portal do paciente): usuários das clínicas de demonstração
`CY-BILL …` criadas por `scripts/seed-billing.php` e removidas por
`scripts/clean-billing.php` — isoladas da CLÍNICA TESTE INTEGRADOR (que continua em
cortesia e nunca é bloqueada pelos specs de cobrança). O spec
`profiles/billing.cy.js` roda o seed no `before` e a limpeza no `after`.

| Clínica de cenário | Situação | Perfis |
|---|---|---|
| CY-BILL EM DIA | Pro mensal pago, fatura de renovação em aberto, pedido de créditos de IA pendente | `billing.ok.{admin,financial,secretary,doctor}` |
| CY-BILL ATRASO | 1 dia de atraso (aviso, acesso total) | `billing.overdue.{admin,financial}` |
| CY-BILL LIMITADO | 4 dias de atraso (acesso limitado) | `billing.limited.{admin,doctor,secretary,financial}` |
| CY-BILL BLOQUEADO | 8 dias de atraso (bloqueio total), TV de chamada ligada, paciente com conta no portal | `billing.blocked.{admin,secretary}`, `billing.patient` |
| CY-BILL RECORRENCIA | pago, com o alerta "Recorrência desativada" no manager | — |
| CY-BILL TRIAL FIM / TRIAL VENCIDO | teste grátis acabando em 2 dias / acabou ontem | `billing.trial_ending.{admin,doctor}`, `billing.trial_over.admin` |

Nenhum gateway de pagamento é chamado: as assinaturas de cenário não têm recorrência no
gateway e o que emitiria cobrança (Pix/boleto, contratar, comprar créditos, "Enviar
cobrança à clínica", `POST /register`) é respondido por `cy.intercept` com o contrato
das rotas JSON do sistema (`cypress/support/billing.js`).

Perfis **sem credencial seedada** (`clinic.user`, `saas.financial`, `saas.support`,
`saas.user`): estão em `cypress/fixtures/profiles.json` com `"email": null` e um
campo `"todo"` explicando o que fazer. **Não** crie usuários pelos testes.

### Como ativar um perfil sem credencial

1. Crie o usuário no ambiente de dev (seeder/admin do painel) com a rule
   indicada, vinculado à entity correta (clínica ou SaaS), e-mail verificado e
   sem 2FA obrigatória.
2. Preencha `email`/`password` da chave correspondente em
   `cypress/fixtures/profiles.json` (remova o campo `todo`).
3. Remova o `describe.skip` dos specs marcados com
   `// SEM CREDENCIAL SEEDADA — ...` para esse perfil.

## Convenções

- **Login sempre via `cy.loginAs('<chave>')`** — usa `cy.session()` cacheada por
  e-mail (evita o throttle do `POST /login` e o rate-limit de leitura do
  manager, 60/min). Após `loginAs`, navegue com `cy.visit(...)` — a sessão não
  deixa você em página nenhuma.
- **`cy.expectPanelPage(marker?)`** — garante que a página realmente montou
  (`#sidebar-menu` visível, ou `.ee-auth-form` em telas guest), sem
  "Internal Server Error"/"Page Expired". `marker` opcional: seletor CSS
  (começando com `#`, `.` ou `[`) ou texto pt-BR.
- **`cy.expectForbidden(url)`** — negação de acesso via `cy.request` full-page
  (nunca XHR Inertia): aceita `403` OU redirect para fora da URL pedida
  (`/panel/manager/*` negado a usuário de clínica = 302 → `/panel/dashboard`
  + flash). Depois revisita a landing para garantir que a sessão continua viva.
- **`console.error` é falha**: o support global captura `console.error` de
  **todas** as páginas carregadas no teste (não só da última) e falha o teste no
  `afterEach` (detecta tela em branco por rota Ziggy quebrada — marker
  `[AppLayout] Rota de menu inválida`). Allowlist em `cypress/support/e2e.js`
  (começa vazia; só adicionar com comentário justificando).
- **Violação de CSP é falha**: as telas de checkout (Minha assinatura,
  `/subscription/expired`, `/register`, IA → Uso e créditos) enviam
  Content-Security-Policy. O Cypress tira esse header por padrão;
  `cypress.config.js` (`experimentalCspAllowList`) mantém tudo menos
  `default-src`/`script-src`/`script-src-elem` (o nonce que o Cypress injeta
  anularia o `'unsafe-inline'` da página — falso positivo). O evento
  `securitypolicyviolation` entra na mesma lista de erros. `script-src` só é
  validado no navegador de verdade (fora do Cypress).
- **Tour guiado do painel**: abre sozinho no 1º acesso ao Dashboard de quem
  ainda não o viu e bloqueia os cliques (`pointer-events: none`). O support
  marca o tour como visto na aba (sessionStorage, a mesma chave que o app usa)
  — não grava nada no banco.
- **Flashes auto-dismiss em 6s**: assertar `.alert-danger`/`.alert-success`
  IMEDIATAMENTE após o redirect (`timeout` curto), nunca depois de outras esperas.
- **Não usar `cy.clock()` global no painel** — o AppLayout agenda
  `window.location.reload()` para o fim do lifetime da sessão.
- **Menu ≠ autorização**: specs de visibilidade de menu e specs de acesso
  (200/403) devem ser independentes — ex.: `financial` vê "Agendas" no menu
  mas leva 403; `doctor` não vê "Médicos" mas `/panel/doctors` responde 200.
- Dados de negócio criados por specs devem ser **não-destrutivos** e criados
  pela própria UI. Não criar/alterar usuários ou entities.
- Specs em `cypress/e2e/`, nomeados `NN-assunto.cy.js` (ordem de execução).
- Textos da UI em **pt-BR**; preferir markers estruturais (classe/href/id) a
  texto quando houver opção.

## Estrutura

```
e2e/
├── cypress.config.js          # baseUrl :8085, chrome, retries runMode=1
├── package.json               # cypress + otplib (autocontido)
├── cypress/
│   ├── e2e/                   # specs (*.cy.js)
│   │   ├── 00-smoke.cy.js     # login page + login/painel + logout
│   │   ├── profiles/          # um spec por perfil + billing.cy.js (cobrança)
│   │   ├── sandbox/           # homologação real do Asaas (opt-in: CYPRESS_SANDBOX=1)
│   │   └── docs/              # geradores de capturas dos manuais (sob demanda)
│   ├── fixtures/
│   │   └── profiles.json      # credenciais/perfis (fonte única)
│   └── support/
│       ├── commands.js        # loginAs / expectPanelPage / expectForbidden
│       ├── billing.js         # seed/clean + respostas simuladas do checkout
│       └── e2e.js             # console.error + CSP de todas as páginas, allowlist, tour
├── scripts/                   # seed-*.php / clean-*.php (dados de cenário, via tinker)
└── README.md
```
