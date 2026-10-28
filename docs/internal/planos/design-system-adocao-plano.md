# Plano — Adoção do design-system no painel (lotes 1 a 10)

**Data:** 2026-10-26 · **Autor:** Claude Code (orquestrador), sobre [MASTER.md](../../../design-system/MASTER.md) e [AUDIT.md](../../../design-system/AUDIT.md) de 23/10 · **Status:** proposta; lote 1 delegado ao time; `AGENTS.md` e `design-system/` aguardam "commit autorizado"
**Referências:** `AGENTS.md`, `design-system/MASTER.md` (§1, §6, §7, §8), `design-system/AUDIT.md` ("Estado da implementação", "Adoção por módulo"), `resources/css/system/_control-tokens.scss`, `tests/JavaScript/Components/ControlDensity.test.js`, `eslint-rules/`, `e2e/cypress/support/commands.js` (`cy.loginAs`).
**Objetivo:** transformar o MASTER de proposta em contrato, fechar o que a fase 0 deixou aberto, migrar os módulos na ordem do AUDIT e impedir que tela nova volte ao padrão legado, com dois gates automáticos (lint e medição em navegador) em vez de revisão manual.

## 0. Diagnóstico que o plano assume

- Fase 0 (tokens, regiões, `density` nos modais) e o piloto Médicos/Pacientes estão commitados: código em `64b373aa` (24/10), testes em `de48095a` (26/10). `design-system/` continua fora do git e o MASTER é proposta: `AGENTS.md` e CI não o citam.
- Prova de que sem regra o padrão não se sustenta: na mesma semana, a tela de precificação de IA seguiu o MASTER (região herdada do piloto) e a importação de pacientes não (`Patients/Import.vue` sem região, 3 `btn-sm` novos; `PatientImportCovenantMapping.vue` novo com 2 `btn-sm`).
- O harness de medição em navegador (Vite + puppeteer) vivia no scratchpad e se perdeu. `ControlDensity.test.js` prova só a matemática do SCSS compilado, não a tela renderizada.
- Dívida medida em 23/10 em `Pages/Panel`: 663 `btn-sm`, 151 `form-control-sm`, 76 `form-select-sm`, 688 `style`. Contagem, não nota.
- Restante do módulo Médicos/Pacientes fora de região (medidas locais por arquivo): `Doctors/Import.vue` 9, `Doctors/WorkSchedule.vue` 13, `Patients/Import.vue` 12, `Patients/PatientDetailDrawer.vue` 1 (`btn-sm` na linha 354), `Components/Panel/PatientImportCovenantMapping.vue` 2. Teleportados sem `density` (sem região dentro deles): `Patients/PatientMergeModal.vue:106` (aberto por `Patients/Index.vue`), `Doctors/DoctorDetailDrawer.vue:38` e `Patients/PatientDetailDrawer.vue:116`. `DoctorCards`, `DoctorTable`, `PatientCards`, `PatientTable` e `Patients/Index` já estão dentro da região do piloto; o que o grep acusa neles é `fs-11`/`fs-12` em badge e ícone de ordenação, que a receita mantém.
- Agenda (lote 2): 16 arquivos em `Pages/Panel/Schedules`, 9 com medida local; regra global `.schedule-card .btn-sm` em `resources/css/system.scss:122` (UI-10); `ScheduleFormModal` com `min-height: 480px` (UI-23).

## 1. Decisões

| # | Decisão | Justificativa |
|---|---|---|
| D1 | **MASTER vira contrato.** Uma linha em `AGENTS.md` ("Implementação e validação") aponta para `design-system/MASTER.md`; `MASTER.md` e `AUDIT.md` entram no git. `inventory.csv` não entra: é foto de 23/10 e o relatório do lint (D3) substitui a contagem. | Sem regra escrita o time copia o arquivo vizinho (importação de pacientes). Decisão do dono: o pedido de 26/10 ("melhore baseado no design-system") é lido como adoção; confirmar no "commit autorizado". |
| D2 | **A região é a unidade de migração**, nunca substituição textual global. Migrar uma página = receita da seção 2, nos arquivos daquela página. | MASTER §6: fora de região nada muda, então cada módulo migra sozinho e é medido sozinho. |
| D3 | **Gate estático:** regra ESLint `ee/no-local-control-sizing`, ativa só nos globs de módulos migrados (lista em `eslint.config.js`, cresce a cada lote). Acusa literal de classe `btn-sm`, `btn-lg`, `btn-md`, `form-control-sm`, `form-select-sm`, `input-group-sm`, `fs-13`/`fs-11` em controle, e `style`/`:style` com `height`, `min-height` ou `font-size` em `button`, `input`, `select`, `textarea` e nos componentes `SearchSelect`, `SearchInput`, `MoneyInput`. Não acusa `.btn-xs`, `.btn-link`, `.btn-close`, badges, `img`, `canvas`, `svg` nem geometria de documento. | AUDIT item 7 pede verificação dirigida sem bloquear toda geometria nem esconder dívida com ignore: a dívida fica fora da lista, visível, e nunca recebe `eslint-disable`. |
| D4 | **Gate de medida:** spec Cypress `e2e/cypress/e2e/design-system/density.cy.js` visita cada página migrada em 1440 px (mouse) e 375 px (toque por largura, MASTER §6) e afirma: todo controle de uma linha visível dentro de `[data-ee-density]` (seletor exclui `textarea`, `.btn-xs`, `.btn-link`, `.btn-close`) mede 40 px em 1440 e 44 px em 375 (tolerância 1 px); `textarea` ≥ 96 px; um menu do `ActionDropdown` aberto por página com itens ≥ 40/44 px; `document.documentElement.scrollWidth <= window.innerWidth`. Lista de páginas cresce por lote. | Substitui o harness perdido por ferramenta que o repo já tem e que roda contra a tela real, com seed real. |
| D5 | **Página interna de referência (MASTER §8) dispensada.** | O spec do D4 sobre telas reais cobre regressão visual de medida; uma página de catálogo seria código a manter sem usuário. |
| D6 | **Congelamento de comportamento:** migração visual não altera `v-model`, payload, parsing de dinheiro/data, `debounce`, ACL, rotas nem chaves de tradução. Diff em `useForm`, `router.*`, `axios`, `route(` precisa estar vazio ou justificado no progresso. | AUDIT UX-04. É o que Lupa e Sentinela checam primeiro. |
| D7 | **Residual da fase 0 entra no lote 1**, nos componentes compartilhados: checkbox/radio/switch, padding de célula de tabela por densidade, menu teleportado do `ActionDropdown` herdando a região, `prefers-reduced-motion` nos dois modais, `PageHeader` com tag de título configurável. Tudo só dentro de região; fora dela nada muda. | São pré-requisitos da Agenda e do Financeiro (tabelas e menus). Deixar para depois obriga a remigrar. |
| D8 | **Escala de larguras de modal (400/640/800/1120) e densidade compacta do Prontuário** ficam para os lotes dos próprios módulos, com medição; não entram como mudança global. | MASTER §4 manda não trocar larguras globalmente antes de piloto. |
| D9 | **Exceção de contexto** só com `design-system/pages/<contexto>.md` (MASTER §7.1). O Prontuário terá o primeiro. | Compacto é exceção explícita, não tradição do módulo. |

### 1.1 Fora de escopo

Cor primária/temas, biblioteca de componentes, `btn-xs` em chips, PDFs/impressão/imagens clínicas/thumbnails/zoom, landing (`DESIGN.md`), renomear classes legadas fora de região, virtualização de tabelas.

## 2. Receita de migração de uma página (Prisma)

1. `data-ee-density="default"` no elemento raiz da página; `density="default"` em cada `CenteredModal`/`OffcanvasPanel` que a página abre (teleportados não herdam).
2. Nos arquivos da página, remover: `btn-sm`, `btn-lg`, `btn-md` (não existe no CSS), `form-control-sm`, `form-select-sm`, `input-group-sm`, `fs-13`/`fs-11` em controle, `style` com `height`/`min-height`/`font-size` em campo ou botão. Ficam: `.btn-xs`, `.btn-link`, `.btn-close`, badges, geometria de imagem/gráfico/documento.
3. Remover `sm`/`size="sm"` de `SearchSelect`, `SearchInput` e `MoneyInput` da página (inertes dentro de região; a prop continua existindo para quem está fora).
4. Botão só com ícone vira `ActionIconButton` (ou `.btn-icon`/`.ee-btn-square`) com nome acessível.
5. Teste Vitest existente do módulo ganha: região na raiz, `density` nos modais, ausência de classe local no botão principal (padrão de `de48095a`).
6. Prisma registra na nota de progresso os caminhos migrados; Forja, dono de `eslint.config.js` e do spec, inclui-os no glob da regra e na lista de páginas e roda `rtk npm run lint` nos arquivos e o spec em 1440 e 375.
7. Progresso registra comando, resultado e limitação (inglês, paisagem, leitor de tela seguem não medidos salvo quando o lote medir).

## 3. Lotes

| Lote | Escopo | Observações específicas |
|---|---|---|
| **1** | Fechar Médicos/Pacientes + residual fase 0 + gates + contrato | Detalhado em 3.1 |
| 2 | Agenda (`Pages/Panel/Schedules`, 16 arquivos) | UI-10: apagar `.schedule-card .btn-sm` de `system.scss` e usar `ActionIconButton`/`.btn-icon`; UI-23: `min-height: 480px` → reserva + `min(480px, 55svh)` como no `PatientFormModal`; não tocar regras de série/encaixe (ver `codex-review-rounds-index` na memória antes de abrir `ScheduleFormModal`) |
| 3 | Financeiro | `MoneyInput` sem `size`; colunas de dinheiro com `font-variant-numeric: tabular-nums` (utilitário `.ee-tabular` em `_control-tokens.scss`); contratos financeiros intactos (D6) |
| 4 | Estoque | UI-21: editor de lotes do `ProductFormModal` vira sub-região `data-ee-density="compact"` documentada em `design-system/pages/estoque-lotes.md` |
| 5 | Configurações/Laudos | UI-19 (`ReportSettings/Form`); medidas do documento impresso intocadas |
| 6 | Imagens | UI-20 toolbar; lupa, comparação, zoom, thumbnails e A4 intocados |
| 7 | Prontuário | Região `compact` com `design-system/pages/prontuario.md`; UI-11/12/13/25; dados clínicos sintéticos; texto clínico não encolhe |
| 8 | Cirurgia, Pronto atendimento, Retornos, Relatórios | Inclui `Components/Surgery` |
| 9 | Manager e dashboards | `Manager/AiPricing` já segue; demais telas do manager |
| 10 | Login, Portal, Portal do Paciente | Região `comfortable`; layout próprio por público |

Cada lote: implementação → Lupa + Sentinela → correção → "commit autorizado" (commit por lote; lote 1 em 4 commits, ver 3.1). Um lote só começa depois do anterior commitado.

### 3.1 Lote 1 em detalhe (um escritor por arquivo)

**Prisma A — páginas (receita da seção 2), depois do Prisma B:** `Pages/Panel/Doctors/{Import,WorkSchedule}.vue`, `Pages/Panel/Patients/Import.vue`, `Components/Panel/PatientImportCovenantMapping.vue`; `density="default"` em `Patients/PatientMergeModal.vue`, `Doctors/DoctorDetailDrawer.vue` e `Patients/PatientDetailDrawer.vue` (este também perde o `btn-sm` da linha 354); `title-tag="h1"` no `PageHeader` de `Doctors/Index.vue` e `Patients/Index.vue` (depois de confirmar que `AppLayout` não emite `h1`). Testes: `tests/JavaScript/Panel/Doctors/*`, `tests/JavaScript/Panel/Patients/*` (inclui `Import.test.js` e `ImportCovenantMapping.test.js`). Ao terminar, listar na nota de progresso os caminhos migrados para o Forja ampliar o glob e o spec.

**Prisma B — residual da fase 0 (componentes compartilhados):**
- `resources/css/system/_control-tokens.scss`, só sob `[data-ee-density]`: checkbox/radio/switch (indicador 18–20 px, linha clicável com `min-height` da região, label clicável); `--ee-table-padding-x` 12/16/16 e `--ee-table-padding-y` por densidade aplicados a `.table > :not(caption) > * > *` com altura de referência 44/52/56 sem `height` rígido; `.ee-tabular`.
- `CenteredModal.vue` e `OffcanvasPanel.vue`: transições 150–200 ms sem `transition: all` e bloco `@media (prefers-reduced-motion: reduce)` que mantém o estado final visível.
- `ActionDropdown.vue`: ao abrir, o menu teleportado recebe o `data-ee-density` do ancestral mais próximo do gatilho (`trigger.closest('[data-ee-density]')`); itens com `min-height` da região. Fora de região: nada muda.
- `PageHeader.vue`: prop `titleTag` (default `'h4'`, aparência igual à atual); páginas migradas passam `'h1'`. Antes, confirmar que `AppLayout` não emite `h1` próprio.
- `tests/JavaScript/Components/ControlDensity.test.js` ganha casos para cada item acima (escopo de região em todo seletor novo, sem `:root`, sem `!important`).

**Forja A — regra ESLint:** `eslint-rules/no-local-control-sizing.cjs` (D3) no padrão de `eslint-rules/core-rules.cjs`, com visitor de template do `vue-eslint-parser` (`context.sourceCode.parserServices.defineTemplateBodyVisitor`) para ler `class`, `:class` com literais e `style`/`:style`; exportada por `eslint-rules/index.cjs` sob o plugin já existente `quality` (id `quality/no-local-control-sizing`). `eslint.config.js` hoje não registra o plugin (só `eslint.quality.config.js` faz): adicionar nele um bloco `{ files: [...], plugins: { quality }, rules: { 'quality/no-local-control-sizing': 'error' } }`. Globs em duas etapas, para o lint não ficar vermelho durante a migração: (1) agora, só o que já está em região (`Doctors/{Index,DoctorFormModal,DoctorInvitationsPending,DoctorTable,DoctorCards}.vue`, `Patients/{Index,PatientFormModal,PatientFormSections,PatientTable,PatientCards}.vue`, `Manager/AiPricing/**`); (2) quando o Prisma A registrar o fim no progresso, trocar por `Doctors/**`, `Patients/**`, `Manager/AiPricing/**` e `Components/Panel/PatientImportCovenantMapping.vue`. Teste Vitest com `RuleTester` em `tests/JavaScript/eslint-rules/no-local-control-sizing.test.js` (padrão `include` do `vitest.config.js`), com caso negativo de geometria legítima (`img`/`canvas` com `style`) e de `.btn-xs`/`.btn-link`/`.btn-close`; `scripts/verify-quality-rules.mjs` só conhece as 3 regras antigas (linhas 37, 150, 198, 262): estender para cobrir a nova; `rtk npm run quality:rules` verde; entrada no `eslint-rules/README.md`. Critério: `rtk npm run lint` falha num arquivo do glob com `btn-sm` e não muda em arquivo legado.

**Forja B — spec de medida:** `e2e/cypress/e2e/design-system/density.cy.js` (D4) com helper `assertDensity()` reutilizável; páginas e rotas do lote 1: `/panel/doctors` (`routes/web.php:461`), `/panel/doctors/import` (:450), `/panel/doctors/{doctor}/work-schedule` (:448; obter o id seguindo a ação de escala da primeira linha da lista, nunca fixar id), `/panel/patients` (:515), `/panel/patients/import` (:474), `/panel/manager/ai-pricing` (`routes/manager.php:413`, perfil `saas.admin`, `throttle:manager-read` 60/min: visitar uma vez por rodada); `cy.loginAs` com `clinic.admin`/`saas.admin` de `e2e/cypress/support/commands.js:11` e `profiles.json` (precedente de medição: `billing.cy.js:598`). Seletor de controles: `button:visible, input:visible:not([type=checkbox]):not([type=radio]):not([type=hidden]):not([type=color]), select:visible, .form-control:visible:not(textarea), .form-select:visible` dentro de `[data-ee-density]`, excluindo `.btn-xs`, `.btn-link`, `.btn-close`; `textarea` à parte (≥ 96 px); abrir cada modal/offcanvas da página e medir dentro dele; abrir um `ActionDropdown` por página e medir os itens. Começar pelas páginas já em região (lista e cadastro de Médicos e Pacientes, precificação de IA) e incluir as demais quando o Prisma A registrar o fim. Pré-condição de `e2e/README.md` (app em `:8085` apontando para o banco de dev com os usuários seedados; Vite em `:5173` ou build sem `public/hot`); rodar SEMPRE de `e2e/`; não rodar junto de seed/clean de outro agente. Registrar o comando e a saída.

**Orquestrador:** linha no `AGENTS.md`; status em `MASTER.md`/`AUDIT.md`; `maestri connect` das notas; commits.

**Lupa + Sentinela (paralelo, só leitura):** D6 primeiro (nenhuma mudança de payload/ACL/tradução), depois escopo de região em todo seletor novo, acessibilidade (nome de botão de ícone, label, foco), e a regra ESLint não ter falso positivo em geometria de imagem.

**Commits do lote 1, nesta ordem:** `docs(design-system): MASTER como contrato` (AGENTS.md + design-system/ + este plano) → `feat(ui): residual da fase 0 nos componentes compartilhados` → `feat(ui): Médicos e Pacientes inteiros em região de densidade` → `chore(qualidade): regra ee/no-local-control-sizing e spec Cypress de densidade`.

## 4. Critérios de aceite por lote

- Vitest do módulo e `ControlDensity.test.js` verdes; `rtk npm run lint` verde nos arquivos migrados com a regra ativa; `rtk npm run quality:rules` verde.
- Spec de densidade verde em 1440 e 375 para todas as páginas do lote, incluindo modais abertos; sem rolagem lateral.
- D6 provado: diff sem mudança em `useForm`/`router`/`axios`/`route(`/chaves de `lang`, ou justificativa no progresso.
- Lupa e Sentinela com zero P1/P2 abertos.
- Progresso com comando, resultado e limitações; nenhuma falha escondida por `eslint-disable`, `skip` ou severidade menor.

## 5. Riscos e mitigação

| Risco | Mitigação |
|---|---|
| `btn-sm` removido muda largura/`padding-x`, não só altura, e um botão deixa de caber na toolbar | Medir no spec; em largura curta, ação secundária vai para `ActionDropdown`, não volta ao `btn-sm` |
| Padding de tabela por densidade alarga tabelas densas (Financeiro, Agenda) | Só dentro de região e medido no lote do módulo; `td` com conteúdo multilinha pode crescer |
| Menu do `ActionDropdown` aberto enquanto a região muda | Copiar o atributo a cada abertura, não só na montagem |
| `PageHeader` como `h1` duplica `h1` do layout | Verificar `AppLayout` antes; se houver, o layout cede |
| Cypress usa o banco de dev com seed; colisão com outro agente | Um agente de cada vez em `e2e/`; `cy.loginAs` com cache de sessão; não rebuildar durante a rodada |
| Dívida fora da allowlist fica invisível no `lint` | `lint:quality` mede adoção; a tabela de lotes é o cronograma de entrada |
| Regra ESLint acusa geometria legítima (imagem, gráfico) | Regra só olha tags/componentes de controle; caso real vira teste negativo da regra, não `disable` |
| CI não roda ESLint nem Cypress (`.github/workflows` sem job de lint/e2e): os dois gates valem só localmente | Lote 1 entrega os gates; incluir `npm run lint` dos globs migrados na CI é decisão do dono depois do lote 1 (muda a CI) |
| Lint vermelho durante a migração (regra ativa antes das páginas migrarem) | Globs em duas etapas (Forja A) |
| `throttle:manager-read` 60/min devolve 429 na precificação de IA | Uma visita por rodada do spec; sem retry em loop |
| Menus teleportados do `ActionDropdown` nunca foram medidos | Spec abre um menu por página (D4) |

## 6. Ordem de execução do lote 1

1. Bussola: revisão de lacunas deste plano, só leitura, uma rodada (escreve achados na seção 8 deste arquivo).
2. Prisma A+B e Forja A+B em paralelo (arquivos disjuntos). Forja B pode começar pelo que já está em região (Médicos/Pacientes lista e cadastro, precificação de IA) enquanto Prisma migra o resto.
3. Lupa + Sentinela em paralelo. Correção por quem escreveu. Repetir até zero P1/P2.
4. "commit autorizado" pelo orquestrador, 4 commits da seção 3.1.

## 7. Como medir que o plano funcionou

- Lote 1: 0 medida local nos globs migrados (lint), 0 controle fora de 40/44 nas páginas do lote (spec), sem rolagem lateral em 375.
- Ao fim do lote 10: `lint:quality` com 100% dos arquivos de `Pages/Panel` dentro da regra. `inventory.csv` continua fora do git (D1) e o dono o apaga quando a regra cobrir `Pages/Panel` inteiro.

## 8. Lacunas apontadas pela Bussola

Revisão de 26/10/2026 (Bussola, só leitura; texto aplicado pelo orquestrador porque o `opencode.json` negou a edição em `docs/internal/planos/`, ver pendência). Cada item com a decisão tomada.

1. As contagens da seção 0 batem e os arquivos de 3.1 existem, mas `DoctorCards`, `DoctorTable`, `PatientCards`, `PatientTable` e `Patients/Index` já estão dentro da região do piloto (`Doctors/Index.vue:129`, `Patients/Index.vue:159`) e só têm `fs-11/12` em badge e ícone. **Aplicado:** saíram do Prisma A; seção 0 corrigida.
2. Faltavam dois teleportados sem `density`: `Patients/PatientMergeModal.vue:106` e `Doctors/DoctorDetailDrawer.vue:38` (`OffcanvasPanel.vue:39,83` só cria região com a prop). **Aplicado** no Prisma A, mais `Patients/PatientDetailDrawer.vue:116`, que o orquestrador conferiu.
3. Dois escritores: o passo 6 da receita mandava o Prisma mexer no glob (Forja A) e na lista do spec (Forja B); `titleTag` h1 em `Doctors/Index.vue:130` não tinha dono. **Aplicado:** Prisma registra caminhos no progresso e Forja amplia glob/spec; `title-tag` nas duas páginas de índice é do Prisma A, depois do Prisma B criar a prop.
4. O seletor do D4 pegava `textarea` via `.form-control:visible` e `DoctorFormModal.vue:582` tem um: a asserção de 40 px falharia. **Aplicado:** `:not(textarea)` e asserção própria ≥ 96 px.
5. D4 viável: `cy.loginAs` (`commands.js:11`), perfis `clinic.admin` (`profiles.json:2`) e `saas.admin` (:41), precedente de medição em `billing.cy.js:598`; rotas reais listadas no Forja B. Faltava receita para `{doctor}` na escala. **Aplicado:** id obtido pela lista, nunca fixo.
6. Regra ESLint: o plugin só existe como `quality` em `eslint.quality.config.js:11`; `eslint.config.js` não tem `plugins:`; `quality:rules` só testa as 3 regras antigas (`verify-quality-rules.mjs:37,150,198,262`); regra nova precisa de visitor de template do `vue-eslint-parser` e teste Vitest no `include` de `vitest.config.js:26`. **Aplicado** no Forja A.
7. Riscos não listados: CI sem ESLint/Cypress; pré-condição de `e2e/README.md:10-13`; lint vermelho na transição; `throttle:manager-read` 60/min (`manager.php:65`); menus teleportados do `ActionDropdown.vue:202` fora da região nunca medidos; seção 7 contradizia D1 sobre `inventory.csv`. **Aplicado:** cinco linhas novas na tabela de riscos e seção 7 alinhada ao D1.

Pendência para o dono (fora deste plano): a Bussola recebeu `permission.rejected` ao editar `docs/internal/planos/design-system-adocao-plano.md`, caminho que a política de autonomia de 24/10 dizia liberado; conferir `opencode.json` na raiz.
