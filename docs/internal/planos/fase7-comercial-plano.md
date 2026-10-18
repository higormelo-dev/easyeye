# Fase 7 — Comercial: plano executável

> Etapa PLANO (Bússola). Pedido do orquestrador: 4 itens na MENOR versão completa de cada um.
> Verificado no checkout em 18/10/2026 (branch `desenv`, árvore limpa).

## 0. Estado do working tree e arquivos compartilhados

- O lote NFS-e **já está commitado** (`5e1f6ddd` + merge PR #5 `1237b949` em `desenv`); `git status` limpo. O aviso do orquestrador sobre lote pendente está desatualizado.
- Arquivos que a Fase 7 divide com o lote NFS-e (todos já em HEAD, sem conflito):
  - `routes/web.php` — item 4 adiciona 2 rotas; NFS-e adicionou downloads em `my-subscription`.
  - `lang/pt_BR/actions.php` e `lang/en/actions.php` — itens 1 e 4 adicionam chaves; NFS-e adicionou `sidemenu.nfse`.
  - `app/Support/PanelNavigation.php` — item 4 adiciona o item de menu; NFS-e adicionou o item `manager.nfse`.
- Arquivos que a Fase 7 **não** toca (embora o lote NFS-e os tenha tocado): `resources/js/Pages/Panel/MySubscription/Index.vue`, drawers do Manager, `app/Services/Billing/CheckoutService.php`.
- `resources/js/Layouts/AppLayout.vue` é tocado pelos itens 3 e 4 (sequenciar commits 4→5).
- Se a implementação começar em branch anterior ao merge do NFS-e, rebase antes.

## 1. Decisões fechadas nesta etapa (vetáveis pelo orquestrador)

1. **Bandeira do cartão é opcional**, mesmo com cartão (não bloqueia salvar); aceita somente quando a forma usa cartão (`credit`, `credit_cash`, `debit_cash` — `PaymentMethod::usesCard()`). Enviar bandeira com forma sem cartão → 422 explícito (front limpa ao trocar a forma).
2. **Badge de Novidades**: coluna `users.changelog_seen_version` (string, nullable). `localStorage` não funciona entre dispositivos; a coluna é a opção mais barata que funciona. Marca como visto ao abrir a página (POST).
3. **Conteúdo de Novidades**: `resources/changelog/pt_BR.md` + `en.md`, renderizado no servidor com `Str::markdown()` — `league/commonmark` 2.10.3 já é dependência de produção do `laravel/framework` (sem dependência nova). O `resources/js/Support/safeMarkdown.js` existe, mas é para texto de IA e não gera títulos reais (`### x` vira parágrafo em negrito) — não serve.
4. **Botão de suporte: ícone no topbar do AppLayout** (não flutuante). Motivo: o FAB da IA ocupa `right:20px; bottom:24px; z-index:1200` e o prontuário reserva o corredor inferior direito; um segundo flutuante colidiria. "Fixo" = presente em todas as telas do painel da clínica. Se o dono preferir flutuante, é só mover o componente (o contrato de props não muda).
5. **Item 1 não bloqueia atendimento/TISS** nesta versão (só avisa) e **não ganha filtro dedicado** na lista de convênios (os campos entram no formulário e no aviso do Dashboard). Bloqueio e filtro/ordenação = extensão futura.
6. **Aviso no Dashboard é card fixo** (padrão do aviso `doctorMissing`), não seção reordenável do "Personalizar" — é alerta, não conteúdo.
7. **CSV do fluxo de caixa ganha a coluna "Bandeira" no fim**. Observação: o CSV hoje não tem coluna "Forma" (a tela tem); incluir "Forma" não foi pedido — fica registrado como opção.
8. **PDF do fluxo de caixa não muda** nesta versão (o blade tem colunas próprias; as linhas CSV/XLSX saem de `cashFlowExportRows`).

## 2. Item 1 — Convênio com vigência + aviso no Dashboard

### Backend (Forja)

1. **Migration** `database/migrations/2026_10_18_120000_add_contract_dates_to_covenants_table.php`
   - `Schema::table('covenants', ...)`: `$table->date('contract_starts_at')->nullable()->after('active')` e `$table->date('contract_ends_at')->nullable()->after('contract_starts_at')`.
   - `down()`: `dropColumn(['contract_starts_at', 'contract_ends_at'])`. Sem índice nesta versão (tabela pequena por clínica).
2. **`app/Models/Covenant.php`**
   - `$fillable` += `contract_starts_at`, `contract_ends_at`.
   - `casts()` += `'contract_starts_at' => 'date:Y-m-d'`, `'contract_ends_at' => 'date:Y-m-d'` (formato ISO puro no JSON, sem hora — alimenta `<input type="date">` e evita deslocamento de fuso; padrão já usado por `ans_registered_at`/`ans_cancelled_at`).
3. **`app/Http/Requests/CovenantRequest.php`**
   - Regras novas (montar a de `contract_ends_at` fora do literal, porque é condicional):
     - `'contract_starts_at' => ['nullable', 'date']`
     - `'contract_ends_at' => array_values(array_filter(['nullable', 'date', $this->filled('contract_starts_at') ? 'after_or_equal:contract_starts_at' : null]))`
     - Motivo do `filled`: `after_or_equal` com o campo de comparação vazio/nulo falha mesmo quando o fim é válido.
   - Strings vazias viram `null` (global `ConvertEmptyStringsToNull`), então limpar as datas funciona.
4. **`app/Services/CovenantService.php`**
   - `getCreateData()` += `'contract_starts_at' => $request->input('contract_starts_at')`, idem `contract_ends_at`.
   - `getUpdateData()` += os dois campos sob `if ($request->has(...))` (mesmo padrão de `color`/`table`) — permite limpar.
5. **`app/Http/Controllers/Setting/CovenantsController.php`**
   - `$crudFields` += `'contract_starts_at' => null, 'contract_ends_at' => null` (whitelist de mass-assignment + defaults do modal).
   - `getFormFields()` += dois campos `['key' => ..., 'label' => __('actions.contract_starts_at'|'contract_ends_at'), 'type' => 'date', 'required' => false]`. `getColumns()` intacto (sem coluna na tabela — decisão §1.5).
6. **`app/Http/Controllers/PanelDashboardController.php`**
   - Import: `Covenant` na linha de `App\Models\{...}`.
   - Prop nova (lazy, sem polling): `'covenantContracts' => fn () => in_array($profile, [ClientRule::Admin, ClientRule::Financial], true) ? $this->buildCovenantContracts($entityId) : null`.
   - Método privado `buildCovenantContracts(string $entityId): ?array`:
     - Query: `Covenant::query()->where('entity_id', $entityId)->where('active', true)->whereNotNull('contract_ends_at')->whereDate('contract_ends_at', '<=', now()->addDays(30))->orderBy('contract_ends_at')->orderBy('name')`, `get(['id','name','contract_ends_at'])`.
     - Só convênios da clínica (globais/ANS, `entity_id NULL`, fora); inativos fora; já vencidos incluídos (data no passado).
     - Retorno (ou `null` quando vazio — o card some):
       ```php
       [
           'window_days' => 30,
           'can_manage'  => $canManageSettings, // hasPermissionInEntity(Permission::SettingsManage) — admin faz bypass
           'url'         => $canManageSettings ? route('panel.setting.covenants.index') : null,
           'items'       => [['id','name','ends_at' => 'Y-m-d','ends_at_label','days_until' => int (negativo = vencido),'expired' => bool], ...], // máx. 10
           'overflow'    => int, // itens além do corte de 10
       ]
       ```
     - `ends_at_label`: formatar com o locale da request (`->locale(app()->getLocale())->isoFormat('LL')`).
     - `can_manage`: financeiro sem `settings.manage` não pode abrir Configurações (rota tem `permission:settings.manage`) — sem link nesse caso.

### Frontend (Prisma)

7. **`resources/js/Pages/Panel/Settings/Catalog/CatalogFormModal.vue`** — tipo de campo `date` (aditivo; os outros 11 catálogos não usam):
   - Comentário de tipos no topo += `date`.
   - Template: novo `v-else-if="field.type === 'date'"` → `<input type="date" class="form-control" v-model="form[field.key]" ...>` (mesma estrutura do input de texto, com erro/hint).
   - `loadEditData()`: normalizar antes de atribuir — montar `Set` das keys com `type === 'date'` a partir de `props.fields` e, para elas, `value = String(value).slice(0, 10)`.
8. **`resources/js/Pages/Panel/Dashboard/CovenantContractAlerts.vue`** (novo)
   - Props: `data` (objeto do item 6), `t` (objeto `dashboard`).
   - Card `alert alert-warning` (padrão do `doctorMissing`), `role="alert"`; lista com badge "Vencido" quando `expired`; texto por item: `expired ? t.covenant_contracts_expired : (days_until === 0 ? t.covenant_contracts_today : t.covenant_contracts_expiring)` com `:date`/`:days`; `+N` quando `overflow`; link "Ver convênios" só quando `url`.
   - `data-tour="dashboard-covenant-contracts"`.
9. **`resources/js/Pages/Panel/Dashboard.vue`**
   - Prop `covenantContracts: { type: Object, default: null }`; render do componente logo abaixo do alerta de médico ausente (`v-if="covenantContracts"`).

### Lang (pt_BR + en)

- `lang/*/actions.php`: `'contract_starts_at' => 'Início do contrato'/'Contract start'`, `'contract_ends_at' => 'Fim do contrato'/'Contract end'`.
- `lang/*/dashboard.php`: `covenant_contracts_title`, `covenant_contracts_expired` (`Venceu em :date`), `covenant_contracts_today` (`Vence hoje (:date)`), `covenant_contracts_expiring` (`Vence em :days dias (:date)`), `covenant_contracts_more` (`+ :count convênios`), `covenant_contracts_open` (`Ver convênios`), `covenant_contracts_expired_badge` (`Vencido`).
- `lang/pt_BR/validation.php` (e `en`): `attributes.contract_starts_at` / `attributes.contract_ends_at`.

### Testes

- `tests/Feature/Setting/CovenantContractDatesTest.php` (helpers `createEntityUser`/`panelSession`):
  - store com datas persiste (`assertDatabaseHas` com `entity_id` da sessão);
  - update troca e limpa (envia vazio → `null`);
  - fim antes do início → 422 em `contract_ends_at`; fim sem início → ok;
  - `show`/`edit` devolvem `Y-m-d` (contrato do input date).
- `tests/Feature/DashboardCovenantContractsTest.php`:
  - admin vê vencido (ontem), vencendo (hoje+10) e `window_days=30`; hoje+45 fora; inativo fora; global (`entity_id NULL`) fora; de outra clínica fora;
  - financial vê; doctor/secretary/user → prop `null`;
  - sem contratos → `null`; financial sem `settings.manage` → `url` `null`.
- Vitest: `tests/JavaScript/Panel/Settings/CatalogFormModalDate.test.js` (renderiza `<input type="date">`, carrega valor cortado, envia `Y-m-d`, vazio vira `''`) e `tests/JavaScript/Panel/Dashboard/CovenantContractAlerts.test.js` (vencido com badge, link só com url, `+N`).

### Riscos / critérios de aceite

- Riscos: cast `date:Y-m-d` afeta só as colunas novas; `CatalogFormModal` é compartilhado — mudança aditiva, rodar `tests/Feature/Setting/*` e `tests/JavaScript/Panel/Settings/CatalogTable.test.js`; precisão de data em fuso (date-only, sem hora).
- Aceite: admin cria/edita convênio com as duas datas (modal, pt/en); fim < início é recusado; admin/financeiro veem o aviso com vencidos e ≤30 dias, com link quando têm acesso a Configurações; demais perfis não recebem o prop; nada muda em atendimento/TISS.

## 3. Item 2 — Bandeira do cartão no recebimento

### Backend (Forja)

1. **Migration** `database/migrations/2026_10_18_120100_add_card_brand_to_financial_cash_entries_table.php`
   - `$table->string('card_brand', 20)->nullable()->after('payment_method')`; `down()` dropColumn. Sem índice.
2. **`app/Enums/CardBrand.php`** (novo): `Visa='visa'`, `Mastercard='mastercard'`, `Elo='elo'`, `Amex='amex'`, `Hipercard='hipercard'`, `Diners='diners'`, `Others='others'`; `label(): string` via `__("financial_cash_flow.card_brands.{$value}")`.
3. **`app/Enums/PaymentMethod.php`**: `usesCard(): bool` → `in_array($this, [Credit, CreditCash, DebitCash], true)`.
4. **`app/Models/FinancialCashEntry.php`**: `$fillable` += `card_brand`; `casts()` += `'card_brand' => CardBrand::class` (coluna nova, sem dados legados; o cast tolerante fica só para `payment_method`).
5. **`app/Http/Requests/Financial/CashEntryRequest.php`**
   - Regra: `'card_brand' => ['nullable', Rule::enum(CardBrand::class)]`.
   - `after()` (junto das regras do split): se `card_brand` preenchido e a forma não usa cartão → `errors()->add('card_brand', __('financial_cash_flow.card_brand_only_card'))`.
   - `prepareForValidation()`: `card_brand` entra no loop de `mb_strtolower(trim(...))` junto de `type/status/payment_method`.
   - `attributes()` já usa `financial_cash_flow.attributes` — só adicionar a chave.
6. **`app/Http/Controllers/Financial/CashFlowController.php`**
   - Import `CardBrand`.
   - `payment_methods` (payload): cada item ganha `'uses_card' => $m->usesCard()`.
   - Prop nova: `'card_brands' => fn () => collect(CardBrand::cases())->map(fn (CardBrand $b) => ['value' => $b->value, 'label' => $b->label()])->values()->all()`.
   - `row()`: += `'card_brand' => $e->card_brand?->value`, `'card_brand_label' => $e->card_brand?->label()`.
7. **`app/Http/Controllers/Financial/FinancialReportsController.php`**
   - `cashFlowEntryRow()`: += `'card_brand_label' => $entry->card_brand?->label()`.
   - `cashFlowExportRows()`: última coluna do cabeçalho `__('financial_reports.cashflow.col_card_brand')`; valor `$entry->card_brand?->label() ?? ''` (CSV/XLSX; PDF fora — §1.8).

### Frontend (Prisma)

8. **`resources/js/Pages/Panel/Financial/CashFlow/CashEntryFormModal.vue`**
   - Props: `cardBrands: { type: Array, default: () => [] }`.
   - `blankForm(keep)`/`formFromEntry(entry)`: `card_brand: keep.card_brand ?? ''` / `entry.card_brand ?? ''`.
   - `const usesCard = computed(() => props.paymentMethods.find(m => m.value === form.value.payment_method)?.uses_card === true)`.
   - `watch` de `form.payment_method`: saindo de cartão → `form.card_brand = ''` (evita bandeira presa; servidor também recusaria).
   - Template: novo campo `v-if="usesCard"` depois do bloco "Forma de pagamento" — label `t.form_card_brand`, select com opção vazia (`t.form_card_brand_none`) + `cardBrands`, erro em `card_brand`, `data-test="card-brand-input"`.
   - Payload: incluir `card_brand` (`form.card_brand || null`); no split da agenda só `payment_method` continua sendo removido (a bandeira pode ser editada).
9. **`resources/js/Pages/Panel/Financial/CashFlow/Index.vue`**: `:card-brands="card_brands"` no modal.
10. **`resources/js/Pages/Panel/Financial/CashFlow/CashFlowTable.vue`**: célula "Forma" += `· {{ entry.card_brand_label }}` (span `text-muted`) quando houver.
11. **`resources/js/Pages/Panel/Financial/Reports/CashFlowEntriesTable.vue`**: mesmo acréscimo na célula da forma (linha do `entry.payment_method_label`).

### Lang (pt_BR + en)

- `financial_cash_flow.php`: `card_brands.*` (Visa, Mastercard, Elo, American Express, Hipercard, Diners Club, Outros/Other), `form_card_brand`, `form_card_brand_none`, `card_brand_only_card`, `attributes.card_brand`.

### Testes

- `tests/Feature/Financial/CashFlowCardBrandTest.php`:
  - store crédito + `visa` persiste; marca inválida → 422; bandeira com `cash` → 422 (`card_brand`);
  - update troca bandeira; update para forma sem cartão com bandeira → 422; split da agenda mantém bandeira editável;
  - index expõe `card_brand_label`; CSV contém cabeçalho "Bandeira" e o rótulo; linha de outra clínica não aparece.
- Vitest: estender `tests/JavaScript/Panel/Financial/CashFlow/CashEntryFormModal.test.js` (campo só com cartão; limpa ao trocar; payload) e `tests/JavaScript/Panel/Financial/CashFlow/CashFlowIndex.test.js` + `tests/JavaScript/Panel/Financial/Reports/CashFlowEntries.test.js` (exibição).

### Riscos / critérios de aceite

- Riscos: coluna aditiva no caixa (não muda `CashFlowService` — `card_brand` não está em `SYSTEM_MANAGED_ATTRIBUTES` nem nas travas de período fechado/claim/repasse); CSV ganha coluna no fim (consumidores de posição fixa); bandeira da assinatura do SaaS não é tocada.
- Aceite: campo aparece só para crédito/débito (e combinados), some/limpa com outra forma; persiste e edita; aparece na tabela do caixa, no relatório e no CSV/XLSX; entradas antigas seguem válidas (nulo).

## 4. Item 3 — Botão de suporte (WhatsApp oficial)

### Backend (Forja)

1. **`config/saas.php`**: chave nova `'support' => ['whatsapp' => env('SUPPORT_WHATSAPP')]`. E-mail continua em `config('mail.support_address')` (`MAIL_SUPPORT_ADDRESS`) — sem duplicar.
2. **`.env.example`**: `SUPPORT_WHATSAPP=` (comentário: DDI+DDD+número, ex. `5561999999999`), perto de `MAIL_SUPPORT_ADDRESS`.
3. **`app/Support/SupportContact.php`** (novo): `props(): ?array`
   - sanitiza `preg_replace('/\D+/', '', config('saas.support.whatsapp'))`; se ≥ 10 dígitos → `['kind' => 'whatsapp', 'url' => "https://wa.me/{$digits}?text=".rawurlencode(__('support.whatsapp_message', ['app' => config('app.name')])), 'label' => __('support.button_label')]`;
   - senão, se `mail.support_address` não vazio → `['kind' => 'email', 'url' => 'mailto:'.$email.'?subject='.rawurlencode(__('support.email_subject')), ...]`;
   - senão `null` (botão some).
4. **`app/Http/Middleware/HandleInertiaRequests.php`**: prop compartilhada `'support' => fn () => $request->routeIs('panel.*') && $request->user() ? SupportContact::props() : null` (mesmo padrão de `aiAssistant`; `manager.*` fica fora — o layout é compartilhado com o Manager).
5. **Lang** `lang/pt_BR/support.php` + `lang/en/support.php` (novos): `button_label`, `whatsapp_message` (`Olá! Preciso de ajuda com o :app.`), `email_subject`.

### Frontend (Prisma)

6. **`resources/js/Components/Panel/SupportButton.vue`** (novo)
   - Props: `support` (`{ kind, url, label }`); `<a :href="support.url" target="_blank" rel="noopener noreferrer" class="topbar-link btn btn-icon" :title="support.label" :aria-label="support.label">` com `ti-brand-whatsapp`/`ti-mail` (mesma classe visual dos botões do topbar).
   - Sem script/inline novo — link puro (nada de CSP).
7. **`resources/js/Layouts/AppLayout.vue`**: `const support = computed(() => page.props.support ?? null)`; renderizar `<SupportButton v-if="support" :support="support" />` no topbar antes do botão do tour (ao lado dos ícones existentes), visível no mobile (sem `d-none`).

### Testes

- `tests/Feature/SupportButtonTest.php`:
  - painel com `config(['saas.support.whatsapp' => '55 61 99999-0000'])` → prop `support.kind = whatsapp`, `url` com dígitos só e mensagem URL-encoded;
  - sem número (e com e-mail) → `kind = email` com `mailto:`;
  - sem número e sem e-mail → `null`;
  - rota do Manager (`manager.dashboard`) → `null`; visitante/guest → `null`.
- Vitest `tests/JavaScript/Components/SupportButton.test.js`: renderiza link com `aria-label`/`title`, `target=_blank` e `rel` correto; ícone por `kind`.

### Riscos / critérios de aceite

- Riscos: número mal cadastrado cai no fallback de e-mail (comportamento definido); AppLayout é compartilhado com o Manager → gating pelo prop (`null` lá); mensagem pré-preenchida é traduzida pelo locale da sessão.
- Aceite: botão aparece em todas as telas do painel da clínica, some no Manager/site; abre `wa.me` com mensagem; sem número, abre e-mail para o suporte; acessível (aria-label/title) e visível no mobile.

## 5. Item 4 — Página Novidades + badge no menu

### Backend (Forja)

1. **`resources/changelog/pt_BR.md`** e **`resources/changelog/en.md`** (novos)
   - Formato fixo (documentar em comentário no topo do primeiro arquivo): primeira ocorrência de `## <semver>` (com `v` opcional) é a versão atual; entradas mais novas no topo. `pt_BR` é a fonte da verdade da versão; `en` é a tradução.
2. **`app/Support/Changelog.php`** (novo)
   - `versionFromMarkdown(string $md): ?string` (regex `/^##\s+v?(\d+\.\d+\.\d+)\b/m`, primeiro match; público para teste);
   - `latestVersion(): ?string` (lê `resource_path('changelog/pt_BR.md')`, fallback `en.md`);
   - `markdown(?string $locale): ?string` (fallback `pt_BR`; `null` se arquivo ausente);
   - `html(?string $locale): ?string` via `Str::markdown()` (defaults seguros do Laravel — conteúdo é do repo, sem input de usuário);
   - `hasUnseen(?User $user): bool` (`latestVersion() !== null && $user?->changelog_seen_version !== $latest`).
3. **Migration** `database/migrations/2026_10_18_120200_add_changelog_seen_version_to_users_table.php`: `$table->string('changelog_seen_version', 20)->nullable()->after('locale')`; down dropColumn.
4. **`app/Models/User.php`**: `$fillable` += `changelog_seen_version`.
5. **`app/Http/Controllers/ChangelogController.php`** (novo)
   - `index(Request)`: `Inertia::render('Panel/News', ['breadcrumbs', 'version' => Changelog::latestVersion(), 'html' => Changelog::html(app()->getLocale()), 'seenUrl' => route('panel.news.seen'), 't' => trans('news')])`.
   - `markSeen(Request)`: grava `changelog_seen_version = Changelog::latestVersion()` (quando houver versão e usuário) e `return back();` — GET nunca escreve.
6. **`routes/web.php`** (dentro do grupo `panel.`, sem middleware extra — herda `auth/verified/phone.verified/entity.selected/tenant.bind/2fa/check.subscription`):
   ```php
   Route::get('/novidades', [ChangelogController::class, 'index'])->name('news.index');
   Route::post('/novidades/visto', [ChangelogController::class, 'markSeen'])->name('news.seen');
   ```
7. **`app/Support/PanelNavigation.php`** — item novo no menu da clínica, inserido **antes do bloco `if ($isAdmin) {` que abre a seção Configurações** (visível para todos os perfis):
   ```php
   $nav[] = [
       'key'   => 'news',
       'route' => 'panel.news.index',
       'icon'  => 'ti ti-megaphone',
       'label' => __('actions.sidemenu.news'),
       'match' => ['panel.news.*'],
       'badge' => Changelog::hasUnseen(auth()->user()) ? __('actions.sidemenu.news_badge') : null,
   ];
   ```
   `managerNav()` intocado.

### Frontend (Prisma)

8. **`resources/js/Layouts/AppLayout.vue`**: no item simples da sidebar, após o `<span>{{ item.label }}</span>`: `<span v-if="item.badge" class="badge text-bg-danger rounded-pill ms-auto">{{ item.badge }}</span>`.
9. **`resources/js/Pages/Panel/News.vue`** (novo)
   - Props: `version`, `html`, `seenUrl`, `t`, `breadcrumbs`.
   - `onMounted`: se `seenUrl`, `router.post(seenUrl, {}, { preserveScroll: true, onSuccess: () => router.reload({ only: ['nav'] }) })` com guarda `ref` (uma vez).
   - Template: `<AppLayout :title="t.title" :breadcrumbs="breadcrumbs">`; chip da versão; `<div class="news-content" v-html="html">` ou estado vazio (`t.empty`).

### Lang (pt_BR + en)

- `actions.php`: `sidemenu.news` ('Novidades'/'What's new') e `sidemenu.news_badge` ('novo'/'new').
- `news.php` (novo): `title`, `subtitle`, `empty`, `breadcrumb`.

### Testes

- `tests/Feature/ChangelogNewsTest.php`:
  - GET `/panel/novidades` → componente `Panel/News`, `html` com `<h2` renderizado e `version` esperada;
  - nav: item `news` com `badge` quando usuário não viu; `badge` `null` quando `changelog_seen_version` = versão atual;
  - POST `panel.news.seen` grava a coluna e o nav seguinte sai sem badge;
  - locale `en` entrega conteúdo em inglês; guest é barrado (login).
- `tests/Unit/ChangelogTest.php`: `versionFromMarkdown` (com/sem `v`, várias seções → primeira, sem heading → `null`).
- Vitest `tests/JavaScript/Panel/News.test.js`: renderiza o HTML e posta `seen` uma única vez.

### Riscos / critérios de aceite

- Riscos: formato do arquivo é contrato (validado por teste unitário); `v-html` com conteúdo do repo (sem input de usuário; defaults seguros do `Str::markdown`); `router.reload({ only: ['nav'] })` renova o badge sem recarregar a página; sem cache do arquivo (leitura de poucos KB por request).
- Aceite: `/panel/novidades` abre para qualquer usuário autenticado do painel; conteúdo pt/en; badge "novo" no menu até abrir a página; depois não volta; Manager não vê item nem botão de suporte.

## 6. Contrato de props/payloads (Forja → Prisma)

- **Dashboard** (`Panel/Dashboard`): `covenantContracts: null | { window_days, can_manage, url, items: [{ name, ends_at, ends_at_label, days_until, expired }], overflow }`; `covenantContracts` = null para perfis fora de admin/financeiro.
- **Catálogo Convênios**: `fields` inclui `{ key: 'contract_starts_at'|'contract_ends_at', label, type: 'date' }`; `crudFields` inclui as duas chaves com default `null`; `show`/`edit` devolvem `Y-m-d` (ou `null`).
- **Fluxo de caixa** (`Panel/Financial/CashFlow/Index`): `payment_methods: [{ value, label, uses_card }]`; `card_brands: [{ value, label }]`; linhas com `card_brand` e `card_brand_label`.
- **Relatório** (`Panel/Financial/Reports/CashFlow`): linhas com `card_brand_label`; CSV/XLSX com a coluna "Bandeira" no fim.
- **Layout (todas as telas `panel.*`)**: `support: null | { kind: 'whatsapp'|'email', url, label }`.
- **Novidades** (`Panel/News`): `version: string|null`, `html: string|null`, `seenUrl: string`, `t`, `breadcrumbs`; nav com `badge: string|null` no item `news`.

## 7. Fatiamento em commits (só com "commit autorizado"; sem push)

1. `docs: plano da Fase 7 (comercial)` — `docs/internal/planos/fase7-comercial-plano.md`.
2. `feat(covenants): vigência do contrato e aviso no painel` — item 1 (§2), incluindo langs e testes.
3. `feat(financial): bandeira do cartão no lançamento de caixa` — item 2 (§3).
4. `feat(panel): botão de suporte no topo do painel` — item 3 (§4). Toque em `AppLayout` deve vir antes do commit 5.
5. `feat(panel): página Novidades com badge no menu` — item 4 (§5).

## 8. Verificação (por commit)

- `rtk npm run lint` e `rtk npm run lint:quality` (Vue), `rtk npm test` (Vitest), `rtk npm run format:check` nos arquivos tocados.
- Pint só nos tocados: `rtk proxy php vendor/bin/pint --test <arquivos>`.
- Pest (PostgreSQL `easyeye_test`, sem `--parallel`), exemplos: `rtk proxy php artisan test tests/Feature/Setting/CovenantContractDatesTest.php tests/Feature/DashboardCovenantContractsTest.php`; depois do item 2: `... tests/Feature/Financial/CashFlowCardBrandTest.php`; itens 3/4: `... tests/Feature/SupportButtonTest.php tests/Feature/ChangelogNewsTest.php tests/Unit/ChangelogTest.php`.
- `rtk npm run build` só antes do commit do frontend (preserva artefatos locais, conforme AGENTS).

## 9. Riscos globais

- `AppLayout.vue` e `CatalogFormModal.vue` são compartilhados — mudanças aditivas; rodar as suítes vizinhas (`tests/JavaScript/Panel/Settings/CatalogTable.test.js`, `tests/Feature/DashboardPerProfileTest.php`).
- Ordem de commits 4→5 obrigatória (mesmo arquivo `AppLayout.vue`).
- Migrations aditivas/anuláveis (sem backfill); `.env.example` é documentação.
- Item 2 preserva as travas do caixa (período fechado via `CashFlowService::assertDateNotClosed`, vínculo de guia/repasse) e o isolamento por `entity_id`; nada de prontuário/assinatura/TISS/billing SaaS é alterado.

## 10. Extensões futuras registradas

- Bloquear atendimento/TISS com contrato vencido; filtro/ordenação por vigência na lista de convênios; aviso em 60 dias configurável.
- Bandeira obrigatória em lançamentos manuais com cartão; capturar bandeira no recebimento da agenda (modal do `SchedulesController`).
- Coluna "Forma" no CSV do fluxo de caixa e bandeira no PDF (`resources/views/pdf/financial_cashflow.blade.php`).
- Versão do produto `latestVersion()` virar `config`/tag de release em vez do arquivo markdown.

## Decisões do orquestrador (18/10/2026) — fecham a §1

1. `card_brand` opcional; bandeira com forma sem cartão → 422. 2. Badge por `users.changelog_seen_version`, marcado ao abrir a página. 3. `resources/changelog/{pt_BR,en}.md` com `Str::markdown` **em modo seguro** (`html_input => strip`, `allow_unsafe_links => false`) e o HTML resultante passa pelo purificador já usado no projeto antes do `v-html`. 4. Botão de suporte no topbar. 5. Item 1 só avisa (sem bloquear). 6. CSV do fluxo de caixa ganha **Forma** e **Bandeira** no fim. 7. PDF fora. 8. `SUPPORT_WHATSAPP` no `.env.example` (sem valor). Lote NFS-e já está em `desenv` (PR #5); a Fase 7 vai na branch `feature/fase7-comercial`.
