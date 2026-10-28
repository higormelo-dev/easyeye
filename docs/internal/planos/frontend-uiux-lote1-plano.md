# Plano — UI/UX Lote 1 (auditoria de frontend, itens 1–13)

Data: 22/10/2026 · Autor: Bússola (planejador) · Origem: Claude Code, a partir de `docs/internal/planos/frontend-uiux-auditoria-2026-10-22.md`, itens 1–13.

Âncoras conferidas no working tree em 22/10/2026: `resources/css/system/components/_reboot.scss`, `_buttons.scss`, `_forms.scss`, `resources/css/auth.scss`, `resources/js/Pages/Auth/Login.vue`, `resources/js/Pages/Panel/Doctors/DoctorFormModal.vue`, `resources/js/Pages/Panel/Patients/PatientFormModal.vue`, `resources/js/Components/Panel/OffcanvasPanel.vue`, `resources/js/Components/Panel/SearchSelect.vue`, `resources/js/Pages/Panel/MedicalRecords/Index.vue`, `resources/js/Pages/Panel/Schedules/CalendarView.vue`, `EventCard.vue`, `resources/js/Layouts/AppLayout.vue`, `lang/pt_BR/ui.php`, `lang/en/ui.php`, `app/Http/Controllers/SchedulesController.php` e `docs/internal/engineering/domain-invariants.md`.

**Este documento é só plano.** Não altera código, não lê `.env`, não executa migrations, não usa banco real e não faz commit/push. A implementação do Lote 1a é da Prisma (Vue/CSS/JS) e da Forja apenas nos arquivos PHP de tradução; o Lote 1b é da Forja depois do commit da série. Revisão de Lupa/Sentinela; commit só com a frase **“commit autorizado”**.

**Hipótese de invalidação:** refazer a leitura se (a) o commit da série “remarcar” alterar `OffcanvasPanel`, `CalendarView`, `EventCard`, `SearchSelect` ou `MedicalRecords/Index`; (b) `Schedule` ganhar `ends_at`/`duration_minutes` antes do Lote 1a; (c) `Doctor::effectiveInterval()` mudar de significado; (d) o orquestrador recusar `window.confirm`; (e) `DoctorWorkSchedule`/`Entity` passar a entregar expediente ao front antes do Lote 1b.

---

## 0. Regras que valem para esta entrega

- **Isolamento por tenant:** nenhuma mudança pode alterar `entity_id`, sessão `selected_entity_id`, gates, policies, queries ou payloads de Agenda/Pacientes/Prontuário. O plano só mexe em apresentação e estados de rede.
- **Prontuário/assinatura:** a timeline do prontuário é somente leitura nesta entrega. Não alterar `clinical_revision`, `isLocked`, `sign()`, `GuardedBySignedRecord`, payload de prontuário, auditoria ou documentos. A mudança é apenas `fetch` + estados de erro/retry; preservar o comportamento de leitura e o botão de criar quando `isDoctor`. Referência: `docs/internal/engineering/domain-invariants.md`, seção **Compliance**.
- **Agenda/locks:** não editar `Schedules/Index.vue`, `RescheduleModal.vue`, `ScheduleDetailDrawer.vue`, `SchedulesController.php`, `ScheduleService.php`, `ScheduleSeriesService.php`, `app/Services/Schedules/*`, `routes/web.php`, `lang/*/schedules.php`, `tests/Feature/Schedules/*` nem `ScheduleSeriesAndFitInForms.test.js` antes do commit da série. O plano não altera ordem de locks, transações, série, encaixe ou disponibilidade.
- **TISS/billing/recall:** não tocados.
- **LGPD:** não salvar dado clínico, nome, CPF, e-mail, conteúdo de prontuário ou payload em `localStorage`, `sessionStorage`, logs ou mensagens de erro. A confirmação de fechamento é em memória (`form.isDirty`). Não imprimir dados de paciente em `console`.
- **Traduções:** todo texto novo em `lang/pt_BR` e `lang/en`; chaves compartilhadas em `ui.php`; erros da timeline em `actions.medical_records`. `ui.php` ganhará teste de paridade (o teste de `actions.php` já existe). Não adicionar chaves em `schedules.php` no Lote 1a.
- **Testes:** Vitest pontual por arquivo na Prisma; o orquestrador roda a suíte completa e o build. PHP só depois de confirmar ambiente local/testing, conexão local, banco `easyeye_test` e ausência de outro escritor; nunca `--parallel`. Sem migrations/seeds como setup.
- **Escopo de arquivos:** F0/F1 (`config/queue.php`, `app/Jobs/*`, `routes/console.php`, `.docker/*`, runbook de filas) não entram. A série “remarcar” não entra. `CalendarView.vue` e `EventCard.vue` estão livres; `Schedules/Index.vue` não está.
- **Sem commit/push** nesta etapa.

---

## 1. Resultado esperado

1. Foco de teclado visível e consistente em links, botões, menus, ações de ícone e campos, sem depender de `outline: none !important`.
2. Login e formulário de médico com labels associados, incluindo o input interno do `SearchSelect`; erros descritos e associados.
3. Cabeçalho do painel com nomes acessíveis para menu móvel, recolher sidebar, idioma e tema; tema anuncia estado.
4. Mostrar/ocultar senha acessível por Tab, com nome e estado, em Login e médico.
5. Fechamento de modal sujo (backdrop, Escape, X e cancelar) pede confirmação; salvar não pede; nada de dado clínico em storage.
6. Erro de submit abre a aba correta, foca o primeiro campo inválido, associa a mensagem e mostra resumo quando há vários.
7. Falha ao carregar edição de médico mostra erro + “Tentar novamente” e impede editar dados não carregados.
8. Busca remota do `SearchSelect` só aplica a última resposta e limpa timer/requisição ao desmontar.
9. `SearchSelect` separa carregando, vazio, falha e seed; falha permite retry e não reapresenta opções antigas como resultado atual.
10. Timeline do prontuário distingue erro inicial, erro de paginação e ausência de prontuários; preserva itens já carregados e oferece retry.
11. Calendário e cartões de evento usam pares fundo/texto com contraste ≥ 4,5:1 para texto pequeno.
12. Bloco de agendamento usa duração real quando o payload tiver; sem duração, usa fallback explícito de 20 min e comunica que é estimado.
13. Grade horária inclui eventos fora de 07:00–20:30, mantendo 07:00–20:30 como fallback quando não há expediente.

**Fora de escopo:** itens 14–30 da auditoria (sidebar semântica, skip link, links comuns com recarga, safeRoute, alvos de 44 px, tipografia, min-height do paciente, documentação visual, i18n completa de cadastros, gráficos, movimento reduzido, flash de erro, etc.), migrations, schema, uso de `effectiveInterval()` como duração de procedimento, e qualquer edição na série “remarcar”.

---

## 2. Decisões

### 2.1 Decisões técnicas (adotadas)

| # | Decisão | Justificativa |
|---|---|---|
| D1 | Remover `button, a { outline: none !important; }` de `_reboot.scss` e criar token de foco `--ee-focus-*` aplicado só em `:focus-visible`. Light: `#0b5ed7`; dark: `#93c5fd`; largura 2 px, offset 2 px. Atualizar `_buttons.scss` e `_forms.scss` para não zerarem o outline no `focus-visible`; remover o `outline: none !important` do bloco de input focado em `auth.scss`. | O reset global anula até o outline local do `ActionIconButton`. `:focus-visible` evita anel em clique de mouse e preserva a aparência atual. Token theme-aware cobre claro/escuro sem trocar a stack. |
| D2 | Adicionar `id`/`for` e `aria-describedby`/`aria-invalid` em Login e em todos os campos do `DoctorFormModal`. Adicionar ao `SearchSelect` props `id`, `ariaLabelledby` e `ariaDescribedby`, repassadas ao input interno do `@vueform/multiselect`; expor `focus()`. | O label visual precisa apontar para o controle real. O multiselect já aceita `id`/`aria`; não é necessário trocar a lib nem criar um select novo. |
| D3 | Nomear os controles do `AppLayout` com chaves de `t_ui`: menu móvel, recolher sidebar, fechar sidebar, idioma e tema. Tema usa `aria-pressed="isDark"` e `title` dinâmico; ícones ficam `aria-hidden="true"`. | Nome acessível explícito sem depender do ícone. `aria-pressed` comunica o estado do tema sem mudar o nome do botão. |
| D4 | Mostrar/ocultar senha: remover `tabindex="-1"`, manter `aria-pressed`, nomear “Mostrar senha”/“Ocultar senha” via `t_ui`, ícone `aria-hidden`. Aplicar em Login e nos dois botões do médico. | O botão é uma ação real e deve entrar na ordem de Tab; o estado precisa ser anunciado. |
| D5 | `OffcanvasPanel` ganha prop opcional `confirmClose?: () => boolean`. Um único `requestClose()` cobre backdrop, clique no wrapper, X e Escape (callback do `useModalA11y`). `false` mantém o modal aberto. Doctor/Patient passam `confirmClose` e o botão “Cancelar” do footer chama o mesmo guard local. Guard usa `window.confirm` porque não existe confirmador genérico e `ConfirmationWithReasonModal` exige motivo; é o mesmo padrão de `useUnsavedChangesGuard`. | Centraliza todas as saídas no componente compartilhado, sem duplicar listeners. `window.confirm` é síncrono e evita uma nova abstração; não salva nada em storage. |
| D6 | Em Doctor, após carregar edição, chamar `form.defaults(form.data())` para o baseline de `isDirty`; em `resetForm`, restaurar os defaults vazios antes de `form.reset()`. Em Patient, o `usePatientForm` já faz isso (`defaults` após load e no reset). | Sem baseline, abrir edição já deixa `isDirty=true` e o guard daria falso positivo. O reset não pode “resetar” para o último registro carregado. |
| D7 | Erros de submit: computar a primeira aba com erro, mudar `activeTab`, aguardar `nextTick` e focar o primeiro `.is-invalid`/`[aria-invalid="true"]` dentro do painel da aba; se houver mais de um erro, renderizar um resumo `role="alert"` no topo. Doctor usa `onError`; Patient chama o mesmo helper após `form.put/post` e após `savePatient()` retornar `ok=false`. | Atende “abrir aba, focar campo, associar erro e resumo quando houver vários”. O helper local é pequeno e evita nova dependência. |
| D8 | `loadEditData` do médico usa `latestRequest()` (`begin`, `signal`, `current`), verifica `res.ok`, não aplica resposta antiga/abortada, e expõe `loadError` + retry. O formulário só é renderizado quando não há erro de carga. | Corrige o `finally` que encerrava o spinner sem recuperação e evita aplicar resposta de outro médico. |
| D9 | `SearchSelect` usa `latestRequest()` para a busca remota, limpa o timer e aborta no unmount. Estado explícito: `idle`, `loading`, `success`, `empty`, `error`. Em busca ativa, `effectiveOptions` usa **somente** `remoteOptions` (+ opção selecionada fixada); nunca cai no seed. Retry reexecuta a mesma query. | Elimina resposta antiga, seed enganoso e falha silenciosa. O seed continua valendo apenas antes da busca (`idle`). |
| D10 | Timeline do prontuário: `initialError` e `loadMoreError` separados; checar `res.ok` antes de `json()`; erro inicial não vira vazio; erro de paginação preserva `records`, `nextPage` e `hasMore`; retry usa a mesma página. Usar `latestRequest` para descartar resposta tardia. | Atende o item 10 sem alterar o endpoint, o payload ou a assinatura. |
| D11 | Paleta de eventos isolada em `resources/js/Support/eventPalette.js`. Fundos escuros com texto branco para status do calendário; **não** alterar os badges globais do Bootstrap. `EventCard` usa `var(--bs-body-color)` no horário e o par acessível no badge. Cores desconhecidas escolhem branco/`#111827` pelo maior contraste; fallback `#4b5563` + branco. | Evita quebrar `bg-info text-dark`/`bg-warning text-dark` existentes em `ScheduleCard`/`NoticesPanel`. Mantém a semântica de cor e garante 4,5:1 no texto pequeno. |
| D12 | Duração: helper aceita `ends_at`/`ends_at_iso` válido; senão `duration_minutes`; senão 20 min com `duration_estimated=true`. O bloco do calendário e o `aria-label`/`title` comunicam “~20 min”/“duração estimada” quando estimado. Lote 1b expõe `duration_minutes`/`ends_at` a partir de `Doctor::effectiveInterval()`, tratado como **duração da vaga/ocupação**, não como duração do procedimento. | É a única duração coerente disponível hoje no backend. O fallback explícito evita fingir que 20 min é dado real. |
| D13 | Bounds: partir de 07:00–20:30; se `calendarBounds` opcional vier do backend, usar como base; expandir com `floor`/`ceil` de 15 min para incluir início/fim de todos os eventos; clampar em 00:00–24:00. Atualizar `slotMinTime`/`slotMaxTime` de forma reativa e via `getApi().setOption()` quando a API existir. | Inclui clínicas que atendem cedo/noite sem esconder eventos; o fallback continua válido. O backend pode mandar expediente depois, sem mudar o contrato do componente. |
| D14 | Testes de comportamento por componente; o restante é checklist manual. CSS de foco, contraste final e zoom não são confiáveis em jsdom. Build e suíte completa ficam com o orquestrador. | Evita falsa confiança em testes de DOM que não aplicam CSS real. |

### 2.2 Paleta final do calendário (texto branco, fundos escuros)

Contrastes calculados contra `#ffffff` (sRGB). Todos ≥ 4,5:1.

| Classe/uso | Fundo | Texto | Contraste |
|---|---|---|---|
| `bg-primary` | `#0b5ed7` | `#fff` | 5,84:1 |
| `bg-secondary` | `#4b5563` | `#fff` | 7,56:1 |
| `bg-success` | `#15803d` | `#fff` | 5,02:1 |
| `bg-info` | `#0e7490` | `#fff` | 5,36:1 |
| `bg-warning` | `#92400e` | `#fff` | 7,09:1 |
| `bg-danger` | `#b91c1c` | `#fff` | 6,47:1 |
| `bg-dark` | `#212529` | `#fff` | 15,43:1 |
| `bg-purple` | `#6d28d9` | `#fff` | 7,10:1 |
| `bg-orange` | `#c2410c` | `#fff` | 5,18:1 |
| `bg-teal` | `#0f766e` | `#fff` | 5,47:1 |
| Bloqueio | `#4b5563` | `#fff` | 7,56:1 |

Observações:
- `#0d6efd` com branco dá 4,5008:1 (passa no limite); usamos `#0b5ed7` para margem.
- `#dc3545` com branco dá 4,5275:1; usamos `#b91c1c`.
- `#198754` com branco dá 4,5308:1; usamos `#15803d`.
- Navy `#0A1B39` sobre teal `#0f766e` dá 3,12:1; **não usar**.
- Texto branco sobre os fundos antigos `#0aa2c0` (3,02:1), `#b7791f` (3,64:1), `#ea580c` (3,56:1) e `#0e9384` (3,80:1) reprova; por isso os fundos escuros acima.
- O texto de horário do `EventCard` passa a `var(--bs-body-color)` (theme-aware); a cor do evento permanece na borda/ícone/badge.
- `.fc-ev-tag` usa `background: transparent` e `border: 1px solid currentColor`; remover `opacity` de `fc-ev-time`, `fc-ev-label` e `fc-ev-doctor` para não derrubar o contraste efetivo. `fc-ev-tag--fit`/`--urgent` continuam com fundo branco e texto escuro.
- Badges globais do Bootstrap e `ScheduleCard`/`NoticesPanel` **não** são alterados neste lote.

---

## 3. Contratos

### 3.1 `OffcanvasPanel.vue`

```js
confirmClose: { type: Function, default: null }
```

- `requestClose()`:
  - se `confirmClose` existir e retornar `false`, não emite `close`;
  - caso contrário, emite `close`.
- Usar `requestClose` no backdrop, no `@click.self` do wrapper, no botão X e no callback de fechamento do `useModalA11y` (Escape).
- O footer é slot do consumidor; Doctor/Patient devem chamar o mesmo guard no botão “Cancelar”.
- `confirmClose` síncrono; se lançar, manter o modal aberto (fail-closed) e reportar sem dados clínicos.
- Sem mudança para os demais consumidores (default `null` = comportamento atual).

### 3.2 `SearchSelect.vue`

Novas props:

```js
id: { type: String, default: '' },
ariaLabelledby: { type: String, default: '' },
ariaDescribedby: { type: String, default: '' },
```

- Passar `:id="id || undefined"`, `:aria="{ 'aria-labelledby': ..., 'aria-describedby': ..., 'aria-invalid': invalid ? 'true' : undefined }"` ao `Multiselect`.
- `defineExpose({ focus })`; `focus()` procura o `input` dentro de `multiselectRef.$el` e chama `.focus()`.
- Estado remoto exposto apenas internamente; não criar prop de estado.
- `effectiveOptions`:
  - sem `remoteSearchUrl`: `props.options`;
  - com URL e termo abaixo de `remoteMinChars`: seed (`props.options`);
  - com termo ativo: `remoteOptions` (mesmo vazio/erro), mais a opção selecionada fixada para não perder o label.
- Textos de estado via `t_ui` (`search_loading`, `search_error`, `search_retry`), com fallback pt. Para não quebrar testes que mockam `@inertiajs/vue3` sem `usePage`, importar o namespace (`import * as Inertia from '@inertiajs/vue3'`) e só chamar `Inertia.usePage()` se for função, dentro de `try/catch`; `const ui = computed(() => page?.props?.t_ui ?? {})`. Não importar `usePage` como binding direto no `SearchSelect`.
- `#beforelist` mostra loading/erro quando já há opção selecionada; `#nooptions` mostra loading/vazio/erro quando a lista está vazia, para não duplicar mensagem.
- `aria-busy` no controle durante `loading`; retry é `<button type="button">` e não fecha o dropdown.

### 3.3 `CalendarView.vue`

Nova prop opcional:

```js
calendarBounds: {
    type: Object,
    default: null,
    // { start: 'HH:mm', end: 'HH:mm' }
}
```

- `toCalendarEvents` usa `resolveScheduleEnd(item)`:
  1. `ends_at_iso` ou `ends_at` válido e posterior ao início;
  2. `duration_minutes > 0`;
  3. fallback 20 min + `duration_estimated: true`.
- `renderEventContent` adiciona tag `~20 min`/duração quando `duration_estimated` e a visão não é mês; `eventDidMount` inclui duração/“estimada” no `aria-label`/`title`. Textos de duração vêm de `t_ui` (`calendar_duration_*`) via `usePage`, com fallback pt; `schedules.php` não é tocado no Lote 1a. No teste da Agenda, usar o mock global de `usePage` (ou o mesmo guard seguro do SearchSelect se o mock local não tiver `usePage`).
- `computeSlotBounds(items)` começa em `calendarBounds` válido ou 07:00–20:30; expande para incluir todos os eventos; alinha a 15 min; clamp 00:00–24:00. Calcular o valor inicial antes de criar `options` e recalcular no `watch` de `scheduleItems` **e** de `calendarBounds`.
- Emits e demais props não mudam.

### 3.4 `EventCard.vue`

- Sem mudança de props/emits.
- Horário: `color: var(--bs-body-color)`; borda/ícone usam a cor do evento.
- Badge: fundo do par acessível + `color` do par; não herdar branco do `.badge`.
- Textos pequenos secundários (`text-muted`) continuam como estão; não fazem parte do aceite deste item (resíduo para o item 19 se o contraste final falhar).

### 3.5 `MedicalRecords/Index.vue`

- Sem mudança de props/endpoint.
- Estados: `loading`, `loadingMore`, `initialError`, `loadMoreError`, `isEmpty` (somente quando `!loading && !initialError && records.length === 0`).
- `loadPage(page)`:
  - página 1: limpa `records` antes da busca (evita dado do paciente anterior), mas em falha mantém `initialError`;
  - página >1: não muta `records`, `nextPage` ou `hasMore` até sucesso;
  - `fetch` com `signal` de `latestRequest`; checar `res.ok`; `json()` só em sucesso; descartar resposta se `!req.current()`;
  - `finally` só desliga flags se a requisição ainda for atual.
- Erro inicial: bloco `role="alert"` com mensagem + “Tentar novamente”; nunca mostrar empty no mesmo estado.
- Erro de paginação: bloco `role="alert"` abaixo da lista, preservando itens; retry chama `loadPage(nextPage)`; botão “Carregar mais” fica desabilitado durante loading e oculto quando há erro inicial.

### 3.6 Chaves de tradução exatas

`lang/pt_BR/ui.php` e `lang/en/ui.php` (topo do array, ao lado de `close`/`loading`; a Forja aplica os arrays, sem lógica de negócio):

| Chave | pt_BR | en |
|---|---|---|
| `retry` | `Tentar novamente` | `Try again` |
| `cep_lookup` | `Buscar CEP` | `Look up postal code` |
| `password_show` | `Mostrar senha` | `Show password` |
| `password_hide` | `Ocultar senha` | `Hide password` |
| `discard_changes` | `Há alterações não salvas. Descartar?` | `You have unsaved changes. Discard them?` |
| `form_errors_summary` | `Corrija os campos destacados.` | `Fix the highlighted fields.` |
| `load_error` | `Não foi possível carregar os dados.` | `Could not load the data.` |
| `open_menu` | `Abrir menu` | `Open menu` |
| `toggle_sidebar` | `Recolher ou expandir menu lateral` | `Collapse or expand sidebar` |
| `close_menu` | `Fechar menu` | `Close menu` |
| `language` | `Idioma` | `Language` |
| `theme_toggle` | `Alternar tema` | `Toggle theme` |
| `theme_dark` | `Ativar tema escuro` | `Enable dark theme` |
| `theme_light` | `Ativar tema claro` | `Enable light theme` |
| `search_loading` | `Buscando…` | `Searching…` |
| `search_error` | `Não foi possível buscar as opções.` | `Could not load search options.` |
| `search_retry` | `Tentar novamente` | `Try again` |
| `calendar_duration_estimated` | `Duração estimada` | `Estimated duration` |
| `calendar_duration_estimated_short` | `~:minutes min` | `~:minutes min` |
| `calendar_duration_estimated_aria` | `:minutes min (duração estimada)` | `:minutes min (estimated duration)` |
| `calendar_duration_minutes` | `:minutes min` | `:minutes min` |

Em `lang/pt_BR/actions.php`/`lang/en/actions.php`, no bloco `medical_records`:

| Chave | pt_BR | en |
|---|---|---|
| `timeline_load_error` | `Não foi possível carregar os prontuários.` | `Could not load medical records.` |
| `timeline_load_more_error` | `Não foi possível carregar mais prontuários.` | `Could not load more medical records.` |
| `timeline_retry` | `Tentar novamente` | `Try again` |

Regras: substituir `:minutes` por `String(minutes)`; não interpolar HTML; não usar texto de erro do servidor como label de campo; não adicionar essas chaves em `schedules.php`. Nos componentes, usar `ui.chave || 'fallback pt'` para não gerar `aria-label` vazio quando `t_ui` faltar em teste/SSR.

---

## 4. Itens 1–13 — tarefas

### Item 1 — Foco visível

**Arquivos:** `resources/css/system/components/_reboot.scss`, `_buttons.scss`, `_forms.scss`, `resources/css/auth.scss`, `resources/js/Components/Panel/ActionIconButton.vue` (apenas CSS local, se necessário).

1. Remover o bloco global `button, a { outline: none !important; }`.
2. Definir em `_reboot.scss` (ou novo parcial importado depois de buttons/forms):
   ```scss
   :root {
       --ee-focus-color: #0b5ed7;
       --ee-focus-width: 2px;
       --ee-focus-offset: 2px;
   }
   :root[data-bs-theme='dark'] {
       --ee-focus-color: #93c5fd;
   }
   :where(a, button, input, select, textarea, summary, [tabindex]:not([tabindex='-1'])):focus-visible {
       outline: var(--ee-focus-width) solid var(--ee-focus-color);
       outline-offset: var(--ee-focus-offset);
   }
   ```
3. `_buttons.scss`: trocar o outline de baixa opacidade por `var(--ee-focus-*)`.
4. `_forms.scss`: remover `outline: 0` do `.form-control:focus`; adicionar `.form-control:focus-visible`/`.form-select:focus-visible` com o token.
5. `auth.scss`: remover `outline: none !important` do `input-group:focus-within`; manter o box-shadow existente e deixar o outline do token aparecer.
6. `ActionIconButton.vue`: trocar o outline local por `var(--ee-focus-*)`; manter `outline-offset` 2 px.
7. `SearchSelect.vue`: garantir anel no wrapper com `.search-select:has(:focus-visible)` (ou `:deep(.multiselect-input:focus-visible)` se a lib suprimir o outline); não depender do outline interno da lib.
8. Não adicionar `outline` em `:focus` (mouse); só `:focus-visible`.

**Aceite:** Tab mostra anel em links, botões, ações de ícone, menu, inputs, selects e botões de senha; clique de mouse não cria anel; 200%/400% sem clipping.

### Item 2 — Labels e associações

**Login:**
- `email`: `id="login-email"`, label `for="login-email"`, `aria-invalid`, `aria-describedby="login-email-error"`; erro com `id`.
- `password`: `id="login-password"`, label `for`, `aria-invalid`, `aria-describedby="login-password-error"`; erro com `id`.
- `remember`: já tem `for`; manter.

**DoctorFormModal:**
- Cada campo de texto/select/data/checkbox/textarea recebe `id="doctor-<campo>"` e o label `for` correspondente. Incluir os campos das abas Pessoal, Médico, Contato e Acesso.
- Erros: `:id="'doctor-<campo>-error'"`, input com `aria-describedby` (somente quando há erro) e `aria-invalid`.
- `SearchSelect` de gênero, estado civil e UF: passar `:id="'doctor-<campo>'"`, `:invalid="!!form.errors.<campo>"`, `:aria-describedby="form.errors.<campo> ? 'doctor-<campo>-error' : undefined"`; label com `for` do mesmo id. O `focus()` do componente encontra o input interno.
- Campos opcionais sem label visível (se houver) recebem `aria-label` em `t_ui` ou label visual; não deixar controle sem nome.
- Não traduzir os labels existentes neste lote (item 22); só associar.

**Aceite:** clicar no label foca o controle; leitor de tela anuncia nome, tipo, valor, obrigatoriedade e erro; SearchSelect anuncia o label e o erro.

### Item 3 — Nomes acessíveis do cabeçalho

**AppLayout.vue:**
- `#mobile_btn`: `:aria-label="ui.open_menu"`, `aria-controls="sidebar"`, ícone `aria-hidden`.
- `#toggle_btn2` e `#toggle_btn`: `type="button"`, `:aria-label="ui.toggle_sidebar"`, `aria-controls="sidebar"`, ícone `aria-hidden`.
- `.sidebar-close`: `type="button"`, `:aria-label="ui.close_menu"`, `aria-controls="sidebar"`, ícone `aria-hidden`.
- Botão de idioma: `type="button"`, `:aria-label="ui.language"`, `aria-haspopup="true"`, `aria-expanded="false"` inicial (Bootstrap atualiza), ícone `aria-hidden`.
- Botão de tema: `type="button"`, `:aria-label="ui.theme_toggle"`, `:aria-pressed="isDark"`, `:title="isDark ? ui.theme_light : ui.theme_dark"`, ícone `aria-hidden`.
- `const ui = computed(() => page?.props?.t_ui ?? {})`; usar fallback pt.

**Aceite:** todos os controles têm nome único; tema anuncia “pressionado/não pressionado”; nenhum controle depende do ícone.

### Item 4 — Mostrar/ocultar senha

**Login:**
- Remover `tabindex="-1"`.
- `:aria-label="showPassword ? ui.password_hide : ui.password_show"`, `:aria-pressed="showPassword"`, `:title` iguais; ícone `aria-hidden`.
- Botão deve ser alcançável por Tab depois do input.

**Doctor:**
- Remover `tabindex="-1"` dos dois botões.
- Substituir textos fixos por `ui.password_show`/`ui.password_hide`; manter `aria-pressed`.
- Ícones `aria-hidden`.

**Aceite:** Tab alcança o botão; Enter/Espaço alterna; nome e estado são anunciados; senha não aparece em logs.

### Item 5 — Confirmação ao fechar modal sujo

1. Implementar o contrato de `OffcanvasPanel` (§3.1).
2. Doctor:
   - `confirmClose()`: se `!form.isDirty` retorna `true`; senão `window.confirm(ui.discard_changes)`.
   - `requestClose()`: se `confirmClose()` então `emit('close')`.
   - Passar `:confirm-close="confirmClose"`; footer “Cancelar” chama `requestClose`.
   - `resetForm` restaura defaults vazios; após load, `form.defaults(form.data())`.
3. Patient:
   - `confirmClose()` com `form.isDirty` e `ui.discard_changes`.
   - Passar `:confirm-close="confirmClose"`; footer “Cancelar” chama `requestClose`.
   - `usePatientForm` já mantém o baseline; não alterar sua lógica.
4. Salvar com sucesso emite `close` diretamente (não passa pelo guard), para não perguntar após salvar.
5. Não usar `localStorage`/`sessionStorage`; o estado sujo é o próprio formulário.

**Aceite:** editar e tentar X/backdrop/Esc/Cancelar mostra confirmação; “Cancelar” no diálogo mantém modal e valores; “OK” fecha; salvar não pergunta; reabrir começa limpo.

### Item 6 — Erros por aba e primeiro campo inválido

1. Doctor:
   - `formRef` no `<form>`; `data-tab` em cada painel (`personal`, `doctor`, `contact`, `auth`) ou seletor equivalente.
   - Reutilizar `tabErrors` existente para escolher a primeira aba com erro; `focusFirstError()` ativa essa aba e, em `nextTick`, foca `.is-invalid, [aria-invalid="true"]` (ou o `input` dentro do SearchSelect).
   - `submit()` passa `onError: focusFirstError`; `sendInvitation()` só trata `existing_doctor` (sem aba), sem focar campo.
   - `errorSummary` computed: entradas de `form.errors` exceto `existing_doctor`; renderizar `role="alert"` no topo do form se `length > 1`, com `ui.form_errors_summary` e lista de mensagens.
2. Patient:
   - `formRef` no `<form>`; `data-patient-section` nos wrappers de `PatientFormSections` ou `data-tab` no painel ativo.
   - Reutilizar `tabHasErrors` do `usePatientForm` para escolher a primeira aba; se o erro for só `confirm_homonym`, manter o alerta dedicado e **não** mover foco para campo.
   - `focusFirstError()`: ordem `personal → clinical → contact → address`; se `form.errors` tiver campo de outra aba, mudar `activeTab` e focar.
   - Chamar depois de `form.put/post` (`onError`) e depois de `savePatient()` quando `result.ok === false`.
   - Resumo `role="alert"` quando `Object.keys(form.errors).length > 1`, excluindo `confirm_homonym` (já tem alerta dedicado).
3. Associar cada erro ao campo (`aria-describedby`) e `aria-invalid`.
4. Não mover foco quando o erro é global (`existing_doctor`, homônimo) sem campo correspondente; o alerta existente já é `role=status/alert`.

**Aceite:** submit com erros em duas abas abre a primeira aba com erro, foca o primeiro campo inválido, anuncia o resumo e mantém os demais erros visíveis; teclado consegue corrigir sem caçar aba.

### Item 7 — Falha ao carregar edição de médico

1. `const editLoad = latestRequest(); const loadError = ref(null);`
2. `loadEditData(id)`:
   - `const req = editLoad.begin(); loading=true; loadError=null;`
   - `fetch(route(...), { signal: req.signal })`;
   - se `!res.ok`, lançar erro genérico;
   - `json()`; se `!req.current()` retornar;
   - aplicar dados, `form.clearErrors()`, `form.defaults(form.data())`;
   - catch: se `isAbortError` ou `!req.current()` ignorar; senão `loadError = ui.load_error`;
   - finally: se `req.current()` `loading=false`.
3. `watch(open)`: ao fechar, `editLoad.cancel()`; ao abrir, reset e load.
4. `onBeforeUnmount(() => editLoad.cancel())`.
5. Template: se `loadError`, mostrar alerta `role="alert"` + botão `ui.retry` e **não** renderizar o form; footer só “Fechar”/“Tentar novamente”. O retry chama `loadEditData(props.doctorId)`.
6. Não usar `ends_at`/dados clínicos em log.

**Aceite:** 500/404/rede lenta mostra erro + retry; formulário não fica editável com dados vazios; retry com sucesso carrega e `isDirty` fica falso; fechar cancela requisição pendente.

### Item 8 — Busca remota: última resposta e unmount

1. Importar `onBeforeUnmount` e `latestRequest`/`isAbortError`.
2. `const remoteReq = latestRequest(); let remoteTimer = null;`
3. Refatorar em `runRemoteSearch(term)`:
   - `const req = remoteReq.begin(); remoteState='loading'; remoteOptions=[];`
   - `axios.get(url, { signal: req.signal })`;
   - se `!req.current()` retornar; normalizar;
   - `remoteState = rows.length ? 'success' : 'empty'`;
   - catch: se abort ou `!req.current()` ignorar; senão `remoteState='error'`.
4. Watch do termo:
   - limpar timer;
   - termo abaixo de `remoteMinChars`: `remoteReq.cancel()`, `remoteOptions=[]`, `remoteState='idle'`;
   - senão agendar `runRemoteSearch`; antes de agendar, limpar `remoteOptions` e marcar `remoteState='loading'` para não exibir resultado antigo durante o debounce.
5. `onBeforeUnmount`: `clearTimeout(remoteTimer); remoteReq.cancel();`.
6. Retry: limpar `remoteTimer` e chamar `runRemoteSearch(searchTerm.value)` direto.

**Aceite:** “mar” responde depois de “joão” e não substitui; unmount não dispara request nem atualiza estado; termo limpo volta ao seed.

### Item 9 — Estados loading/vazio/erro/seed

1. Implementar a máquina de estados e o `effectiveOptions` do §3.2.
2. Em busca ativa, **não** usar `props.options` como fallback; seed só em `idle`.
3. UI:
   - `loading`: `aria-busy`, mensagem `ui.search_loading` (quando lista vazia);
   - `empty`: `noResultsText` (“Nada encontrado”) sem seed;
   - `error`: `role="alert"` com `ui.search_error` + botão `ui.search_retry`;
   - `success`: lista remota;
   - `idle`: seed + `noOptionsText` atual.
4. Se houver opção selecionada, mantê-la visível, mas o erro/loading deve aparecer em `#beforelist` para não ficar escondido.
5. Não limpar a opção selecionada ao falhar; o valor atual é contrato do formulário.

**Aceite:** falha não mostra seed como resultado; vazio não mostra “Nenhuma opção” genérico; retry funciona; opção selecionada continua com label.

### Item 10 — Timeline do prontuário

1. Implementar §3.5.
2. `isEmpty` inclui `!initialError`.
3. Template:
   - `v-if="loading"` spinner;
   - `v-else-if="initialError"` alerta `role="alert"` com `t.timeline_load_error` + `t.timeline_retry`;
   - `v-else-if="isEmpty"` empty atual;
   - `v-else` timeline; após a lista, `v-if="loadMoreError"` alerta `role="alert"` com `t.timeline_load_more_error` + `t.timeline_retry`; senão `v-if="hasMore && !initialError"` botão carregar mais; senão fim da lista.
4. Não limpar `records` em erro de página >1; não atualizar `nextPage`/`hasMore`/`total` antes de sucesso.
5. Não tocar em `clinical_revision`, assinatura, payload, endpoint ou botões de escrita.

**Aceite:** 500 inicial mostra erro (não “Nenhum prontuário”); 500 na página 2 mantém os 10 itens e permite retry; retry não duplica; sucesso continua renderizando a timeline.

### Item 11 — Contraste do calendário e cartões

1. Criar `resources/js/Support/eventPalette.js` com a tabela §2.2, mapa de hex legados e `resolveEventPalette`/`bestTextColor`.
2. `CalendarView`:
   - trocar `BADGE_HEX`/`textColor: '#fff'` por `resolveEventPalette({ badge: item.badge, color: item.doctor_color })`;
   - `backgroundColor` do par; `textColor` do par;
   - blocos: `#4b5563` + branco; compromissos: `resolveEventPalette({ color: item.color })`.
3. `EventCard` (não usa `CalendarView`): importar o helper e:
   - borda/ícone com a cor resolvida;
   - horário com `var(--bs-body-color)`;
   - badge com fundo resolvido + texto resolvido; sem `opacity: .85`.
4. CSS do calendário: `fc-ev-tag` transparente/currentColor; remover opacities de textos pequenos.
5. Não alterar `ScheduleCard`, `NoticesPanel`, `_background.scss` ou tokens globais do Bootstrap.

**Aceite:** todos os pares de status da tabela ≥4,5:1; horário e badge do `EventCard` legíveis em claro/escuro; status continuam distinguíveis por texto (label/selo), não só cor. Metadados secundários em `text-muted` ficam fora deste aceite.

### Item 12 — Duração do agendamento

1. `resolveScheduleEnd(item)` no `CalendarView` conforme §3.3.
2. `toCalendarEvents` usa o resultado; `extendedProps.duration_estimated` e `duration_minutes`.
3. `renderEventContent` adiciona tag de duração estimada em dia/semana (`ui.calendar_duration_estimated_short`, ex. “~20 min”); não no mês para não poluir.
4. `eventDidMount` inclui `duração`/`estimada` no `aria-label` e `title` (junto do que já existe).
5. Se `duration_minutes`/`ends_at` vierem do backend, não marcar estimado.
6. Lote 1b (§5) completa o contrato; até lá, o fallback de 20 min é explícito.

**Aceite:** 20/30/45 min quando o payload informar; sem payload, bloco de 20 min + “~20 min”/“duração estimada”; leitor de tela ouve a duração; nenhuma duração de procedimento é inventada.

### Item 13 — Faixa horária

1. Adicionar prop opcional `calendarBounds` e `computeSlotBounds(items)` (§3.3).
2. No `watch` de `scheduleItems`/`calendarBounds`, além de `options.events`, recalcular `options.slotMinTime`/`slotMaxTime`; se a API estiver montada, chamar `setOption` nos dois **apenas se `typeof api.setOption === 'function'`** (o stub de `AgendaViewsFase1` deve ganhar `setOption: vi.fn()`).
3. Fallback 07:00–20:30 quando não há eventos nem bounds.
4. Incluir eventos que começam antes de 07:00 ou terminam depois de 20:30; alinhar a 15 min; clamp 00:00–24:00.
5. Lote 1b pode fornecer `calendarBounds` do expediente; sem ele, o fallback permanece.

**Aceite:** evento 06:30 aparece (slotMin 06:30); evento 21:00/21:20 aparece (slotMax ≥21:30); semana/mês não cortam; dia sem eventos volta a 07:00–20:30.

---

## 5. Lote 1b (Forja) — somente após o commit da série

**Pré-condição:** commit da série “remarcar” autorizado e árvore limpa. Até lá, não editar os arquivos bloqueados.

### 5.1 Duração real/ocupação

- Leitura atual (já feita, sem edição): `Schedule` só tem `date_time`; `SchedulesController::toCompactRow`/`toScheduleRow` não enviam `duration_minutes`/`ends_at`; `AgendaQueryService` já tem `ends_at_iso` para blocos/eventos. Confirmar de novo no working tree após o commit da série.
- Verificar `Schedule` (só `date_time`), `AgendaQueryService` (blocos/eventos) e `SchedulesController::toCompactRow`/`toScheduleRow` (sem `duration_minutes`/`ends_at`).
- Adicionar aos payloads de `schedule`:
  - `duration_minutes`: `Doctor::effectiveInterval()` (duração de ocupação da vaga), com fallback explícito quando não houver médico/intervalo;
  - `ends_at`: `date_time + duration_minutes` em ISO.
- Não chamar isso de “duração do procedimento”. Documentar no plano e no código que é a duração da vaga.
- Evitar N+1: usar relações já carregadas; teste de query count se a rota já tiver esse padrão.
- Teste de contrato: schedule com intervalo 30 → `duration_minutes=30`, `ends_at` 30 min depois; sem intervalo → campo ausente/fallback declarado; `ends_at_iso` continua para blocos/eventos.
- O front já prefere `ends_at`/`duration_minutes`; não precisa mudar de novo.

### 5.2 Expediente/faixa

- Leitura atual (já feita): `CalendarView.vue` recebe apenas `scheduleItems`, `date`, `range` e `t`; não recebe expediente nem `calendar_bounds`. `DoctorWorkSchedule` tem `starts_at`/`ends_at`, mas o Index não os entrega ao calendário.
- Verificar se `DoctorWorkSchedule`/`Entity` já expõem expediente no Index.
- Se houver fonte confiável: calcular `calendar_bounds` (`{start,end}`) para o dia/range e passar `Schedules/Index.vue` → `CalendarView`.
- Se não houver fonte sem ambiguidade (múltiplos médicos, bloqueios, feriados), **não inventar**: manter fallback do front e registrar a pendência.
- Teste: bounds 06:00–22:00; evento 05:40 expande para 05:30; evento 22:10 expande para 22:15.

### 5.3 Testes PHP

- `tests/Feature/Schedules/...` somente após o commit; usar `easyeye_test`; sem `--parallel`; confirmar isolamento de envios externos.
- `tests/Unit/Lang/ActionsAndPortalTranslationParityTest.php` já cobre `actions`; nada novo em `schedules.php`.

---

## 6. Tarefas por agente

### Prisma (Vue/CSS/JS e Vitest pontual)

1. **P1 — foco (item 1):** `_reboot.scss`, `_buttons.scss`, `_forms.scss`, `auth.scss`, `ActionIconButton.vue`.
2. **P2 — Login (itens 2 e 4):** labels/ids/erros; botão de senha; `usePage`/`ui` para os textos.
3. **P3 — Doctor (itens 2, 4, 6, 7):** labels/ids; senha; baseline/defaults; `latestRequest`/erro/retry; `focusFirstError`/resumo; `confirmClose`; `usePage`/`ui`.
4. **P4 — AppLayout (item 3):** nomes/estado do tema.
5. **P5 — Offcanvas + Patient (item 5):** prop `confirmClose`; guard nos dois modais; Cancelar; `usePage`/`ui`.
6. **P6 — Patient erros (item 6):** `focusFirstError`, resumo, associações nos campos que recebem erro.
7. **P7 — SearchSelect (itens 8 e 9):** `latestRequest`, estado remoto, retry, id/aria/focus, slots de estado.
8. **P8 — MedicalRecords (item 10):** erros separados, retry, preservação e `res.ok`.
9. **P9 — calendário (itens 11–13):** `eventPalette.js`, `CalendarView.vue`, `EventCard.vue`, duração/bounds.
10. **P10 — testes Vitest** da seção 7 nos arquivos tocados; atualizar mocks de `useForm` (defaults/data/isDirty) e `usePage` quando necessário.
11. Rodar ESLint/Prettier **somente nos arquivos tocados** e reportar.

### Forja (PHP de texto e Lote 1b)

1. Adicionar chaves de `t_ui` em `lang/pt_BR/ui.php`/`lang/en/ui.php` e de `actions.medical_records` em `lang/pt_BR/actions.php`/`lang/en/actions.php` (sem regra de negócio).
2. Estender `tests/Unit/Lang/ActionsAndPortalTranslationParityTest.php` com `ui.php`.
3. **Não** editar `lang/*/schedules.php` no Lote 1a.
4. Lote 1b (§5), somente após o commit da série e autorização.

### Lupa/Sentinela

- Revisar diff e chamadores; checar regressão de foco/labels, `isDirty`, fechamento, aborts, estados de erro, contraste e ausência de vazamento clínico.
- Sentinela: confirmar que nenhuma mensagem/log contém paciente, prontuário, CPF, e-mail ou payload; que retry não duplica operação; que `MedicalRecords` continua read-only.

### Orquestrador

- Rodar suíte Vitest completa e build; comparar artefatos gerados; encaminhar achados; autorizar commit só após revisão e com a frase “commit autorizado”.

---

## 7. Testes

| # | Arquivo | O que prova |
|---|---|---|
| 1 | `tests/JavaScript/Auth/Login.test.js` (atualizar) | labels `for`/id, erro descrito, botão de senha tabbable, `aria-pressed`, nome mostrar/ocultar, sem `tabindex=-1`. |
| 1b | `tests/JavaScript/Support/FocusVisibility.test.js` (novo) | regressão estática: `_reboot.scss` sem `button,a { outline:none!important }`; tokens `--ee-focus-*`; `_buttons`/`_forms`/`auth` sem supressão no `focus-visible`; `ModalAccessibility.test.js` existente cobre trap/retorno. |
| 2 | `tests/JavaScript/Layouts/AppLayoutHeaderAccessibility.test.js` (novo) | nomes de menu/sidebar/idioma/tema; `aria-pressed` do tema; `aria-hidden` dos ícones. |
| 3 | `tests/JavaScript/Components/ModalCloseGuard.test.js` (novo) | `OffcanvasPanel` com `confirmClose=false` não emite em backdrop/X/Escape/wrapper; `true` emite; default não muda. |
| 4 | `tests/JavaScript/Panel/Doctors/DoctorFormModal.test.js` (atualizar) | baseline `isDirty` após load; erro 500 + retry; form oculto; labels; foco na primeira aba/campo; resumo; guard de fechamento. |
| 5 | `tests/JavaScript/Panel/Patients/PatientFormErrorFocus.test.js` (novo) | 422 em campo de outra aba muda aba, foca campo, `aria-describedby`; resumo com vários; `savePatient` embedded retornando `ok=false` também foca. |
| 6 | `tests/JavaScript/Components/SearchSelectRemote.test.js` (atualizar) | resposta fora de ordem; abort no unmount; erro + retry; vazio não volta ao seed; seed no estado idle; `id`/`aria`/`focus()`. |
| 7 | `tests/JavaScript/Panel/MedicalRecords/TimelineErrors.test.js` (novo) | 500 inicial ≠ empty; erro na página 2 preserva itens e `nextPage`; retry; `res.ok`; sem duplicar. |
| 8 | `tests/JavaScript/Support/ScheduleEventPalette.test.js` (novo) | todos os pares da tabela ≥4,5:1; hex legados resolvem; cor desconhecida escolhe texto legível. |
| 9 | `tests/JavaScript/Panel/Schedules/AgendaViewsFase1.test.js` (atualizar) | duração explícita 30/45; fallback 20 + estimado; bounds 06:30/21:20; fallback 07:00–20:30; `slotMinTime`/`slotMaxTime`; stub com `setOption` espião. |
| 10 | `tests/Unit/Lang/ActionsAndPortalTranslationParityTest.php` (atualizar) | `ui.php` com mesmas chaves pt/en; `actions.php` continua coberto. |
| 11 | Checklist manual (seção 10) | CSS de foco, contraste final, zoom, leitor de tela, temas, viewports. |

**Mocks a ajustar:**
- Doctor/Patient: `useForm` mock precisa de `defaults`, `data()`, `isDirty` e `clearErrors`; o mock atual de `DoctorFormModal.test.js` só tem `reset`/`clearErrors`.
- `usePage` mock precisa de `t_ui` com as chaves novas; fallback pt continua para testes antigos.
- `SearchSelect` em testes sem `t_ui` deve continuar funcionando com fallback.

**Comandos de teste (Prisma):**

```bash
rtk npm test -- tests/JavaScript/Auth/Login.test.js \
  tests/JavaScript/Support/FocusVisibility.test.js \
  tests/JavaScript/Layouts/AppLayoutHeaderAccessibility.test.js \
  tests/JavaScript/Components/ModalCloseGuard.test.js \
  tests/JavaScript/Panel/Doctors/DoctorFormModal.test.js \
  tests/JavaScript/Panel/Patients/PatientFormErrorFocus.test.js \
  tests/JavaScript/Components/SearchSelectRemote.test.js \
  tests/JavaScript/Panel/MedicalRecords/TimelineErrors.test.js \
  tests/JavaScript/Support/ScheduleEventPalette.test.js \
  tests/JavaScript/Panel/Schedules/AgendaViewsFase1.test.js
```

**Comandos de estilo (somente arquivos tocados):**

```bash
rtk npx eslint <arquivos .js/.vue>
rtk npx prettier --check <arquivos .js/.vue/.scss>
```

**Forja (1a e 1b):**

```bash
rtk proxy php vendor/bin/pint --test lang/pt_BR/ui.php lang/en/ui.php lang/pt_BR/actions.php lang/en/actions.php tests/Unit/Lang/ActionsAndPortalTranslationParityTest.php
rtk proxy php artisan test tests/Unit/Lang/ActionsAndPortalTranslationParityTest.php
# 1b, somente após confirmar easyeye_test e autorização:
rtk proxy php artisan test tests/Feature/Schedules/<teste>.php
```

**Orquestrador:** `rtk npm test` e `rtk npm run build` (o build mexe em `public/vendor`, `public/build` e `bootstrap/ssr`; conferir artefatos e não commitar gerados indevidos). Não rodar `npm run lint` completo como gate: há dívida conhecida; usar ESLint direcionado.

---

## 8. Documentação

- Este plano em `docs/internal/planos/frontend-uiux-lote1-plano.md`.
- Após a implementação, acrescentar ao final deste arquivo uma seção **“Execução”** com comandos, resultados, testes manuais e resíduos; não alterar as evidências da auditoria.
- Não alterar `docs/internal/engineering/domain-invariants.md`; a timeline é read-only e não muda invariantes.
- Se o Lote 1b expuser duração/expediente, registrar o contrato na seção 5 e no teste correspondente; não criar documentação paralela.

---

## 9. Riscos e mitigação

| Risco | Mitigação |
|---|---|
| Remover o reset global de outline expõe anéis em muitos componentes e pode “poluir” a tela. | Só `:focus-visible`; token 2 px; testar mouse vs teclado; rollback restrito aos 4 arquivos CSS. |
| `outline` em `.form-control` dentro de input-group pode ficar estranho/clipado. | `outline-offset` 2 px; validar login e cadastros em 200%/400%; se necessário, anel no wrapper com `:focus-within` e fallback no input. |
| Guard de fechamento não cobre o “Cancelar” do footer (slot do consumidor). | Doctor/Patient chamam o mesmo `requestClose` local; teste com botão Cancelar, X, backdrop, Escape. |
| `form.isDirty` falso positivo/negativo. | Baseline explícito após load/reset; teste Doctor; Patient já tem baseline no `usePatientForm`. |
| `window.confirm` é nativo, não estilizável e pode ser bloqueado em alguns contextos. | É o padrão existente do projeto; não criar abstração nova. Se o dono recusar, reavaliar `ConfirmationWithReasonModal` (fora do Lote 1). |
| `SearchSelect` com `usePage` pode quebrar testes/SSR sem Inertia. | Importar namespace + guard `typeof Inertia.usePage === 'function'` dentro de `try/catch`; fallback pt; testar componente isolado. |
| `latestRequest` + axios: signal pode ser ignorado por mock/versão. | Sempre checar `req.current()` além do abort; teste com promises fora de ordem. |
| Erro de paginação pode duplicar itens se o retry reexecutar página já aplicada. | Não atualizar `nextPage` antes do sucesso; retry usa `nextPage` atual; teste de contagem. |
| Paleta do calendário divergir dos badges globais. | Decisão D11: alteração isolada; badges do Bootstrap intactos; documentar no plano. |
| Texto pequeno com `opacity` continua abaixo de 4,5:1. | Remover opacity dos textos do calendário; checklist com DevTools; se sobrar, registrar resíduo do item 19. |
| Duração de 20 min ainda parece real para usuário. | Tag “~20 min”/“duração estimada”, aria/title; Lote 1b remove o fallback quando houver contrato. |
| Bounds derivados de eventos de mês podem abrir 00:00–24:00. | É intencional para não ocultar eventos; testar dia/semana/mês; se incomodar, limitar expansão ao range visível em iteração futura. |
| Editar arquivo da série por engano. | Lista de proibidos na seção 0; `git status` antes/depois; nenhum arquivo de `Schedules/Index.vue`/serviços/rotas/testes de série. |
| Chaves de lang divergirem. | `ActionsAndPortalTranslationParityTest` com `ui`; `actions` já coberto; `schedules.php` intocado no 1a. |

---

## 10. Checklist manual (fechamento dos critérios)

### Teclado e foco (itens 1–4, 6)

- [ ] Tab/Shift+Tab no Login: e-mail → senha → mostrar senha → lembrar → entrar; anel visível em todos.
- [ ] Tab no header: menu móvel, recolher, idioma, tema, usuário; tema anuncia estado.
- [ ] Doctor: percorrer todas as abas; labels clicáveis; SearchSelect recebe foco e abre por teclado.
- [ ] Submit com erro em duas abas: aba correta, primeiro campo focado, resumo anunciado.
- [ ] Zoom 200%/400% e texto ampliado: nenhum anel/controls cortado.

### Modal e dados (item 5)

- [ ] Editar nome e tentar X, backdrop, Escape e Cancelar: confirmação; “Cancelar” mantém valores.
- [ ] Confirmar descarte fecha; reabrir limpo.
- [ ] Salvar com sucesso fecha sem confirmação.
- [ ] Nenhum dado clínico em `localStorage`/`sessionStorage` (DevTools).

### Rede e estados (itens 7–10)

- [ ] Doctor: simular 500/offline no load; erro + retry; form não editável; retry carrega.
- [ ] SearchSelect: digitar rápido “mar”/“joão”; resposta antiga não vence; 500 mostra erro + retry; vazio não volta ao seed; limpar volta ao seed.
- [ ] Fechar modal/desmontar durante busca não gera request/erro no console.
- [ ] Prontuário: 500 inicial mostra erro (não “Nenhum”); 500 na página 2 preserva os itens; retry não duplica; empty real continua correto.

### Agenda (itens 11–13)

- [ ] 375/768/1024/1440 e landscape: calendário, filtros e cards sem clipping.
- [ ] Duração 20/30/45 quando o payload tiver; sem payload “~20 min”/estimada.
- [ ] Evento 06:30 e 21:20 aparecem; dia sem eventos volta a 07:00–20:30.
- [ ] Contraste no DevTools/calculadora para texto pequeno em todos os status; claro e escuro.
- [ ] Leitor de tela ouve nome do evento, horário, duração/estimada, encaixe/série.
- [ ] Lista alternativa continua com labels completos e indicação textual de encaixe/série.

### Contexto e privacidade

- [ ] Voltar/avançar preserva filtros, paginação e scroll; nenhuma troca silenciosa de clínica/paciente (regressão, não alterar).
- [ ] Nenhum nome/CPF/e-mail/conteúdo clínico em logs, alertas, `console` ou capturas externas.
- [ ] Nenhum envio externo/Sentry; nenhuma leitura de `.env`.

---

## 11. Critérios de aceite finais (auditoria, linha 84)

- Tarefa concluída por teclado.
- Edição não perdida por saída acidental.
- Falha não apresentada como vazio/sucesso.
- Resultado antigo não substitui busca atual.
- Agenda representa horários corretos (duração e faixa).
- Texto pequeno ≥4,5:1 (calendário/cartões; resíduos fora do lote documentados).
- Rota de retorno e contexto preservados.

**Pendências que não bloqueiam o Lote 1a:** alvos de 44 px (item 18), tipografia global (19), min-height do paciente (20), documentação visual do painel (21), i18n completa de cadastros (22), gráficos (23–24), movimento reduzido (25), flash de erro (26), sidebar semântica/skip link (14–15), links comuns (16), `safeRoute` (17). Itens 1–13 não devem “puxar” esses escopos.

---

## 12. Fora de escopo / bloqueios

- **Zona proibida até o commit da série:** `resources/js/Pages/Panel/Schedules/Index.vue`, `RescheduleModal.vue`, `ScheduleDetailDrawer.vue`, `app/Http/Controllers/SchedulesController.php`, `app/Services/Schedules/ScheduleService.php`, `ScheduleSeriesService.php`, `app/Services/Schedules/*`, `routes/web.php`, `lang/*/schedules.php`, `tests/Feature/Schedules/*`, `tests/JavaScript/Panel/Schedules/ScheduleSeriesAndFitInForms.test.js`.
- **F0/F1:** `config/queue.php`, `app/Jobs/*`, `routes/console.php`, `.docker/*`, runbook de filas, `.claude-flow/*`.
- **Banco:** sem migrations/seeds, sem `--parallel`, sem banco além de `easyeye_test`; PHP só com autorização.
- **Commit/push:** nenhum sem “commit autorizado”. Push só com pedido específico.


## Execução — Prisma, Lote 1a (23/10/2026)

P1–P11 implementados nos arquivos frontend/Vitest previstos, incluindo associações no componente compartilhado `PatientFormSections.vue`. O mock de `useForm` em `DoctorInvitation.test.js` também foi atualizado para acompanhar defaults/data/isDirty; nenhum caso de teste antigo foi removido. Chave adicional `ui.cep_lookup` autorizada pelo orquestrador e implementada nos textos pela Forja. Sem edições em lang, backend, série ou F0/F1 pela Prisma.

### Comandos e resultados

Cada linha abaixo foi executada isoladamente, sem suíte completa. Total: **71/71**.

| Comando | Resultado |
|---|---|
| `rtk proxy npx vitest run tests/JavaScript/Auth/Login.test.js` | 3/3 |
| `rtk proxy npx vitest run tests/JavaScript/Layouts/AppLayoutHeaderAccessibility.test.js` | 1/1 |
| `rtk proxy npx vitest run tests/JavaScript/Components/ModalCloseGuard.test.js` | 5/5 |
| `rtk proxy npx vitest run tests/JavaScript/Panel/Doctors/DoctorFormModal.test.js` | 12/12 |
| `rtk proxy npx vitest run tests/JavaScript/Panel/Doctors/DoctorInvitation.test.js` | 6/6 |
| `rtk proxy npx vitest run tests/JavaScript/Panel/Patients/PatientFormErrorFocus.test.js` | 4/4 |
| `rtk proxy npx vitest run tests/JavaScript/Components/SearchSelectRemote.test.js` | 10/10 |
| `rtk proxy npx vitest run tests/JavaScript/Panel/MedicalRecords/TimelineErrors.test.js` | 3/3 |
| `rtk proxy npx vitest run tests/JavaScript/Support/ScheduleEventPalette.test.js` | 11/11 |
| `rtk proxy npx vitest run tests/JavaScript/Panel/Schedules/AgendaViewsFase1.test.js` | 16/16 |

Comandos de estilo, limitados aos arquivos tocados:

```bash
rtk proxy npx eslint resources/js/Components/Panel/ActionIconButton.vue resources/js/Components/Panel/OffcanvasPanel.vue resources/js/Components/Panel/SearchSelect.vue resources/js/Pages/Auth/Login.vue resources/js/Pages/Panel/Doctors/DoctorFormModal.vue resources/js/Pages/Panel/Patients/PatientFormModal.vue resources/js/Pages/Panel/Patients/PatientFormSections.vue resources/js/Layouts/AppLayout.vue resources/js/Pages/Panel/MedicalRecords/Index.vue resources/js/Pages/Panel/Schedules/CalendarView.vue resources/js/Pages/Panel/Schedules/EventCard.vue tests/JavaScript/Auth/Login.test.js tests/JavaScript/Panel/Doctors/DoctorFormModal.test.js tests/JavaScript/Components/SearchSelectRemote.test.js tests/JavaScript/Panel/Schedules/AgendaViewsFase1.test.js resources/js/Support/eventPalette.js tests/JavaScript/Components/ModalCloseGuard.test.js tests/JavaScript/Layouts/AppLayoutHeaderAccessibility.test.js tests/JavaScript/Support/ScheduleEventPalette.test.js tests/JavaScript/Panel/MedicalRecords/TimelineErrors.test.js tests/JavaScript/Panel/Patients/PatientFormErrorFocus.test.js tests/JavaScript/Panel/Doctors/DoctorInvitation.test.js
rtk proxy npx prettier --check resources/js/Components/Panel/ActionIconButton.vue resources/js/Components/Panel/OffcanvasPanel.vue resources/js/Components/Panel/SearchSelect.vue resources/js/Pages/Auth/Login.vue resources/js/Pages/Panel/Doctors/DoctorFormModal.vue resources/js/Pages/Panel/Patients/PatientFormModal.vue resources/js/Pages/Panel/Patients/PatientFormSections.vue resources/js/Layouts/AppLayout.vue resources/js/Pages/Panel/MedicalRecords/Index.vue resources/js/Pages/Panel/Schedules/CalendarView.vue resources/js/Pages/Panel/Schedules/EventCard.vue tests/JavaScript/Auth/Login.test.js tests/JavaScript/Panel/Doctors/DoctorFormModal.test.js tests/JavaScript/Components/SearchSelectRemote.test.js tests/JavaScript/Panel/Schedules/AgendaViewsFase1.test.js resources/js/Support/eventPalette.js tests/JavaScript/Components/ModalCloseGuard.test.js tests/JavaScript/Layouts/AppLayoutHeaderAccessibility.test.js tests/JavaScript/Support/ScheduleEventPalette.test.js tests/JavaScript/Panel/MedicalRecords/TimelineErrors.test.js tests/JavaScript/Panel/Patients/PatientFormErrorFocus.test.js tests/JavaScript/Panel/Doctors/DoctorInvitation.test.js
rtk proxy git diff --check
```

Prettier e diff-check passaram. ESLint direcionado preserva **37 erros preexistentes**, sem avisos novos: 36 `vue/no-mutating-props` em `PatientFormSections.vue` e 1 `router` não usado em `MedicalRecords/Index.vue`. A mesma dívida foi conferida no conteúdo HEAD com `git show HEAD:<arquivo> | rtk proxy npx eslint --stdin --stdin-filename <arquivo>`; nenhuma regra foi suprimida/rebaixada. Os demais 20 arquivos Vue/JS/testes passaram sem erros/avisos. O `.prettierignore` do projeto cobre JS/Vue e exclui SCSS; os quatro SCSS receberam somente mudanças locais de foco, conferidas no diff. Não foi executado build.

### Prova dos testes novos

Foram removidos temporariamente os hunks de cada correção, substituindo somente os componentes-alvo pelo conteúdo HEAD (sem checkout, stash ou alteração do índice). Cada arquivo foi restaurado byte a byte em `finally`. A tabela lista as linhas finais correspondentes aos hunks retirados. Para o helper novo da paleta, a mutação substituiu o retorno final por um par branco/branco. **43/43 testes novos falharam na ausência da correção.**

| Grupo | Arquivos/linhas revertidos temporariamente | Testes novos detectaram |
|---|---|---|
| login | `resources/js/Pages/Auth/Login.vue` hunks finais `2; 22,2; 86; 92; 96; 98,2; 111; 115; 117,2; 129,11; 142; 145,5` (início,quantidade de linhas) | 2/2 |
| header | `resources/js/Layouts/AppLayout.vue` hunks finais `18; 344,9; 354,8; 391,4; 399; 418,11; 515,9; 525,7` (início,quantidade de linhas) | 1/1 |
| close | `resources/js/Components/Panel/OffcanvasPanel.vue` hunks finais `34; 51,10; 67; 78; 91` (início,quantidade de linhas) | 5/5 |
| doctor | `resources/js/Pages/Panel/Doctors/DoctorFormModal.vue` hunks finais `2,2; 6,3; 26,5; 35; 68,2; 72,4; 86; 88; 90,2; 93,9; 103; 105,0; 107,4; 115,23; 145,6; 154,2; 157; 159; 197; 199; 217; 298,18; 317; 319,3; 323; 325,3; 332,3; 338,3; 342; 344,3; 351,3; 358,17; 377; 379; 381,3; 389,7; 401,3; 405; 408,5; 419,5; 428; 430; 432,5; 442,7; 453; 455; 457,3; 464,3; 471,12; 489; 492; 494; 496,3; 503,3; 508,3; 512; 514,5; 523,5; 532; 534; 536,2; 544,3; 555; 564; 566,3; 574,3; 580; 582; 584,3; 592,7; 602,12; 619; 622; 624; 627,3; 635,7; 644; 646; 649,3; 657,7; 668,12; 688; 691; 694,3; 703,12; 719,13; 736,17; 755,17; 776,17; 797,13; 812; 814; 816,3; 824,3; 832; 839; 842; 844,3; 855,3; 861; 864,7; 877,3; 882; 884,7; 899,5; 907; 910,5; 923,13` (início,quantidade de linhas) | 6/6 |
| patient | `resources/js/Pages/Panel/Patients/PatientFormModal.vue` hunks finais `2,3; 32,3; 55,23; 92; 97,7; 105,7; 124; 161,12; 192` (início,quantidade de linhas); `resources/js/Pages/Panel/Patients/PatientFormSections.vue` hunks finais `2; 6; 86,3; 197; 199,14; 242,13; 259,3; 263; 265,3; 272,3; 277; 279; 281,3; 291,3; 299,3; 303; 305,3; 315,5; 324,3; 328; 331,5; 342,5; 353; 355; 357,3; 364,3; 371,17; 390,17; 415,2; 422,0; 424; 431,12; 449; 451; 453; 455,3; 463,7; 473; 475; 477,2; 492,5; 516; 518; 520,3; 528,7; 540,13; 555,13; 614,19; 635,19; 656; 658; 660,7; 672,7; 681,19; 706; 708; 710; 713,3; 722,3; 729,12; 756,7; 771,6; 782; 784; 787,3; 795,3; 802; 804; 807; 810,3; 819,4; 827,13; 844,13; 859,17; 880,13; 897,13; 912; 914; 916,3; 924,3; 931,13` (início,quantidade de linhas) | 4/4 |
| search | `resources/js/Components/Panel/SearchSelect.vue` hunks finais `2,3; 16,3; 62,8; 77; 90; 108,2; 111; 113,13; 131,18; 150; 152,10; 163,21; 213,2; 216,7; 244,30` (início,quantidade de linhas) | 5/5 |
| timeline | `resources/js/Pages/Panel/MedicalRecords/Index.vue` hunks finais `2; 13; 38,3; 42,2; 46; 48,2; 52; 53,0; 57; 59; 61; 63,0; 67,5; 73,4; 78,0; 80,5; 139; 189,11; 342,16` (início,quantidade de linhas) | 3/3 |
| palette | `resources/js/Support/eventPalette.js:62` (retorno branco/branco) | 11/11 |
| calendar | `resources/js/Pages/Panel/Schedules/CalendarView.vue` hunks finais `20,3; 31; 41,2; 52,28; 89,2; 95; 98; 100; 105; 115; 125; 130,4; 165; 190,2; 202; 206,35; 250; 269; 289,2; 292,5; 364,0; 392,2; 411,0; 415,0` (início,quantidade de linhas); `resources/js/Pages/Panel/Schedules/EventCard.vue` hunks finais `2,3; 9,4; 26; 33; 44; 55,5` (início,quantidade de linhas) | 6/6 |

Comando de cada prova: o mesmo `rtk proxy npx vitest run <arquivo>` acima, acrescentando `--reporter=json --outputFile=/tmp/uiux-mutation-<grupo>.json`. O consolidado local `/tmp/uiux-proof.json` contém o nome de cada um dos 43 testes, os arquivos revertidos e os comandos exatos; `/tmp/uiux-mutations.py` contém o procedimento de reversão/restauração. Os testes positivos foram executados com a implementação restaurada; ajustes posteriores de foco/seleção foram validados novamente nos arquivos afetados.

### Desvios, itens após o commit e limites

- Cores arbitrárias: escolher entre branco e `#111827` nem sempre garante 4,5:1 em um fundo intermediário. Se nenhum dos dois textos atinge o mínimo, o helper usa o fallback aprovado `#4b5563`/branco. A tabela semântica e os hex legados seguem a decisão 2.2.

- Invalidação de buscas ocorre antes do debounce; isso impede uma resposta antiga de validar ou reapresentar opções enquanto a consulta nova aguarda o timer. A opção selecionada inicialmente por props também é preservada, incluindo seleções múltiplas.

- `calendarBounds` opcional está implementado no componente livre `CalendarView.vue`. O repasse pelo host `Schedules/Index.vue` e a origem de duração/expediente no backend ficam **após o commit da série**, no Lote 1b; esses arquivos proibidos não foram editados. Sem contrato de duração, o fallback de 20 min é explicitamente estimado.

- Checklist visual da seção 10 (CSS final, claro/escuro, zoom 200%/400%, tamanhos de tela e leitor de tela real) **não executado**. Testes de DOM não comprovam esses critérios; são verificação pendente de revisão.

- Suíte Vitest completa, build e revisão independente ficam com o orquestrador. Paridade PHP e Pint de lang ficam com a Forja. Sem novo serviço, dependência, storage clínico, logs de payload ou dados reais em fixture. Sem commit, push ou stash.
