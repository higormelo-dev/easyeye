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
