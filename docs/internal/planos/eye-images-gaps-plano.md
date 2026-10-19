# Eye Images — gaps vs referência: plano executável

> Etapa PLANO (Bússola). Pedido do orquestrador: itens 1–6 do briefing na MENOR versão completa de cada um; itens 7–9 fora (registrados no fim).
> Verificado no checkout em 18/10/2026 (branch `desenv`, HEAD `81e5cfef`, Fase 7 já mergeada no PR #6). Nenhum dado de paciente foi lido; todas as âncoras abaixo foram conferidas no código.

## 0. Estado do working tree e arquivos compartilhados

- `git status`: árvore limpa exceto `?? docs/internal/planos/eye-images-gaps-briefing.md` (arquivo do orquestrador). Sugestão: commitá-lo junto do commit 1 deste plano — decisão do orquestrador.
- Arquivos compartilhados entre itens (por isso os commits são sequenciais):
  - `resources/js/Pages/Panel/EyeImages/Index.vue` — itens 1, 3, 4, 5 e 6.
  - `app/DTOs/EyeImageFilters.php` — itens 3, 4 e 5.
  - `app/Http/Controllers/EyeImagesController.php` — itens 3, 4 e 6.
  - `routes/web.php` — itens 2, 3, 4 e 6 (item 5 não cria rota).
  - `lang/pt_BR/eye_images.php` + `lang/en/eye_images.php` — itens 1, 3, 4, 5 e 6.
  - `tests/Feature/EyeImages/EyeImagesFiltersTest.php` — itens 3, 4 e 5.
  - Módulo Pacientes (`PatientsController`, `PatientTable.vue`, `Index.vue`, `ui.php`, `actions.php`, `validation.php`) — só item 2.
- Migrations novas na série `2026_11_01_*` (depois da última do checkout, `2026_10_31_000000_add_return_to_eye_image_report_drafts.php`); cada uma toca uma tabela diferente, sem dependência entre si.

## 1. Decisões fechadas nesta etapa (vetáveis pelo orquestrador)

1. **Item 1**: `SHEET_SIZES = [1, 2, 4, 6, 8, 9, 12, 15, 16]`; grade fixa por orientação (§2); pareamento OD/OE vale **só em tamanho par** — 1/9/15 continuam na ordem atual, com aviso curto na UI (não bloqueia e nunca troca olhos de coluna entre folhas).
2. **Item 2 — quem vence**: o cadastro aberto pelo usuário é o **destino** (mantido); o outro é a **origem** (soft-delete + `merged_into_id`). **Sem desfazer** nesta versão (undo fica como extensão).
3. **Item 2 — People/Portal**: `People` **não** é tocado (identidade global compartilhada entre clínicas); contas do Portal do Paciente (por `person_id`) e vínculos por pessoa **não** são unificados. Registros financeiros/TISS seguem o `patient_id` (só muda o dono) — validação humana registrada em §11.
4. **Item 2 — conflitos**: índices únicos clínicos → **422 fail-closed** com motivo legível (5 pré-checagens, §3.2); `recall_dirty_patients` (marcador derivado) é auto-resolvido; `patient_exams.code` (`EXM-`) colidido é **renumerado**.
5. **Item 2 — permissão**: só `admin` da clínica mescla (`entity.role:admin` na rota + check de tenant explícito nos dois pacientes). `can_merge` só vem `true` para admin.
6. **Item 3 — visto**: marca **só** o visualizador do Eye Images (ao exibir no painel, em `setPanelExam`) por UPDATE atômico `whereNull` — idempotente, sem evento de model (não gera linha de auditoria nem mexe `updated_at`). Guarda **quando**, não **quem viu** (`viewed_by` = extensão).
7. **Item 4 — urgente**: marcar/desmarcar é ação do médico (mesmo `entity.role:admin,doctor` + Gate `EntityGate::IssueReport` das ações rápidas irmãs; item só no bloco `isDoctor`). Triagem pela recepção, se pedida, é exceção nova. `urgent` **é** auditado (aparece no Histórico do item 6).
8. **Item 5 — solicitante**: casa apenas laudo salvo (`medical_record_documentations.requesting_doctor_name`, sem deletados); rascunhos (`eye_image_report_drafts`) ficam fora nesta versão; parcial, sem acento e sem caixa; máximo 80 caracteres.
9. **Item 6 — histórico**: leitura pura; **nunca** devolve valor de campo clínico (allowlist §6.2) nem IP/user-agent (LGPD); ACL espelha o grupo Pacientes (`permission:patients.manage,admin,financial,doctor,secretary`).

## 2. Item 1 — Impressão por folha: tamanhos 8/9/12/15/16 (só front, sem migration)

**Estado atual**: `resources/js/Support/printSheets.js` tem `SHEET_SIZES = [1, 2, 4, 6]` (linha 14) e `gridFor`/`sheetGrid` com o caso de pares; a toolbar do modal (`Index.vue`, `data-testid="print-per-sheet"`, ~linha 3710) já renderiza `SHEET_SIZES` em `v-for` (toolbar é `flex-wrap` — cabe sem ajuste).

1. `resources/js/Support/printSheets.js`
   - `SHEET_SIZES = [1, 2, 4, 6, 8, 9, 12, 15, 16]`.
   - `sheetGrid` — mapas novos:
     - retrato: 1→1×1, 2→1×2, 4→2×2, 6→2×3, **8→2×4, 9→3×3, 12→3×4, 15→3×5, 16→4×4**;
     - paisagem: 1→1×1, 2→2×1, 4→2×2, 6→3×2, **8→4×2, 9→3×3, 12→4×3, 15→5×3, 16→4×4** (paisagem = retrato transposto).
   - Novo helper exportado `canPairOdOe(perSheet)` = `perSheet > 1 && perSheet % 2 === 0` (pares precisam de 2 colunas × linhas completas; 9/15 desalinhariam os olhos entre folhas).
   - `sheetGrid`: pares só quando `pairOdOe && canPairOdOe(perSheet)` → `{cols: 2, rows: perSheet / 2}`; senão cai nos mapas (9/15 com `pairOdOe=true` devolvem a grade sem par).
   - `buildSheets`: `const paired = pairOdOe && canPairOdOe(perSheet);` (mesma regra, sem duplicar lógica).
   - Atualizar o comentário do topo (novos tamanhos + regra do ímpar).
2. `resources/js/Pages/Panel/EyeImages/Index.vue`
   - Nenhuma mudança estrutural nos botões (`v-for="n in SHEET_SIZES"`).
   - Aviso quando `printGroupOdOe && printPerSheet > 1 && printPerSheet % 2 === 1`: `<small class="text-muted">` com `tt('print_pair_odd_hint', …)` ao lado do grupo `print-per-sheet`.
   - Comentário do `printPerSheet` (perto da linha 183) atualizado para `1..16 (SHEET_SIZES)`.
3. Lang (pt_BR + en, `eye_images.php`): `print_pair_odd_hint` — "Pareamento OD/OE não se aplica a folhas com :count imagens (ímpar) — as imagens saem na ordem atual."
4. Testes (Vitest)
   - `tests/JavaScript/Support/printSheets.test.js`: `SHEET_SIZES` novo; grade retrato/paisagem de 8/9/12/15/16; pareado 8→2×4, 12→2×6, 16→2×8; 9 e 15 com `pairOdOe=true` → grade sem par e `buildSheets` mantém a ordem original; `paginate` completa a última folha (9, 15) com `null`; casos antigos (1, 2, 4, 6) preservados.
   - `tests/JavaScript/Panel/EyeImages/PrintModalReorder.test.js`: lista dos 9 botões; caso novo com 9 + "Agrupar OD/OE" (hint presente, ordem preservada).
5. **Aceite**: 9/15 sem pareamento não embaralha OD/OE entre folhas; os 9 botões aparecem; modo "colunas" intocado; testes acima verdes.
6. **Risco**: folha 16 retrato (4×4) fica pequena — escolha explícita; 15 paisagem (5×3) aperta em A4 — aceito no modo "N por folha".

## 3. Item 2 — Mesclar cadastro de paciente (dedup)

**Estado atual**: `PatientDuplicateFinder` só sugere códigos (restauração/criação); soft-delete + `ActionPolicy` por linha; `patient_id` em **38 tabelas** (levantadas por parser nas migrations — o briefing dizia 35, o número atual é 38); não existe merge.

### 3.1 Migration

`database/migrations/2026_11_01_000000_add_merged_into_id_to_patients_table.php`

```php
$table->foreignUuid('merged_into_id')->nullable()->after('active')
      ->constrained('patients')->nullOnDelete();
```

- `down`: `dropConstrainedForeignId('merged_into_id')`.
- `Patient` (modelo): relação `mergedInto(): BelongsTo` (`self::class, 'merged_into_id'`); **não** entra em `$fillable` (o service grava com `forceFill`).

### 3.2 `app/Services/PatientMergeService.php` (novo)

- `public const MOVABLE_TABLES = [...]` — as 38 tabelas, exatamente:
  `schedules, patient_exams, medical_records, waiting_list, patient_consents, data_access_logs, lgpd_requests, medical_record_documentations, medical_record_files, billing_claims, tiss_guides, ai_runs, financial_cash_entries, medical_record_evolutions, patient_document_shares, medical_record_procedures, doctor_payout_items, urgent_care_entries, medical_record_drafts, patient_allergies, patient_medications, eye_image_report_drafts, patient_conditions, patient_recalls, patient_recall_events, patient_recall_blocks, recall_dirty_patients, recall_invites, whatsapp_messages, intravitreal_courses, intravitreal_applications, care_guidance_issues, surgical_cases, surgery_document_issues, surgical_implants, surgical_descriptions, surgical_description_addenda, surgical_postop_evaluations`.
- `candidates(Patient $source): array` — reaproveita `PatientDuplicateFinder::find()` (mesma clínica, ignora excluídos; devolve códigos) e carrega os `Patient` com `person`; devolve `[{id, code, full_name, birth_date, age, kind: 'duplicate'|'homonym'}]`, duplicados primeiro, ordenado por `code`; exclui o próprio.
- `merge(Patient $source, Patient $target): array` — tudo em `DB::transaction`:
  1. **Locks**: `Patient::whereIn('id', [$source->id, $target->id])->orderBy('id')->lockForUpdate()->get()` — ordem estável por `id` (evita deadlock entre dois merges; `PatientExam::performInsert` trava 1 paciente por vez, compatível).
  2. **Pré-checagens fail-closed** (lança `ValidationException::withMessages(['target_id' => __('validation.custom.patient.merge_conflict', ['items' => implode('; ', $motivos)])])`), reusando o `WHERE` de cada índice parcial:
     - `patient_conditions`: aberta (`status IN ('suggested','active')`) com mesmo `protocol_key` + `eye` (`patient_conditions_one_open`);
     - `patient_recalls`: `source = 'doctor_order'` nos dois (`patient_recalls_doctor_order_unique`);
     - `patient_recall_blocks`: `released_at IS NULL` com mesmo `reason` (`patient_recall_blocks_one_active`);
     - `intravitreal_courses`: `status IN ('active','paused') AND deleted_at IS NULL` no mesmo `eye` (`intravitreal_courses_one_open`);
     - `medical_record_drafts`: mesmo `user_id` + `context_key` (`medical_record_drafts_owner_context_unique`).
  3. `recall_dirty_patients` (PK `entity_id+patient_id`, marcador derivado): apaga a linha da origem **se** o destino já tem linha do mesmo `entity_id` (senão ela migra junto na lista do passo 5).
  4. **Renumerar** exames da origem com código colidido: `$destCodes = DB::table('patient_exams')->where('patient_id', $target->id)->pluck('code')->flip()`; `$next` = maior `EXM-%010d` do destino + 1; para cada exame da origem (ordem de `code`), se o código existe no destino → novo `sprintf('EXM-%010d', $next++)` e `update` por id **antes** de mover (índice `patient_exams_patient_code_unique` é por paciente). Conta `renumbered`.
  5. **Mover**: para cada `MOVABLE_TABLES` → `DB::table($t)->where('patient_id', $source->id)->update(['patient_id' => $target->id])`, somando `moved` (nenhuma outra tabela tem unicidade dependente de `patient_id` — conferido; `medical_records.code` é `PMR-` por entidade).
  6. **Origem**: `$source->forceFill(['merged_into_id' => $target->id])->save()` (auditoria `updated`) + `$source->delete()` (auditoria `deleted` com `deleted_by`); `People` intocado.
  7. **Auditoria explícita** no destino: `AuditService::log('merged', $target, null, ['source_id' => …, 'source_code' => …, 'moved' => …, 'renumbered' => …])`.
  8. Retorna `['moved' => int, 'renumbered' => int]`.

### 3.3 Backend HTTP (`PatientsController`)

- `mergeCandidates(Patient $patient, PatientMergeService $merge): JsonResponse` — `abort_unless((string) $patient->entity_id === (string) session('selected_entity_id'), 404)` (padrão `editData`); `['data' => $merge->candidates($patient)]`.
- `merge(Request $request, Patient $patient, PatientMergeService $merge): JsonResponse` — valida `target_id: ['required','uuid']` e `confirm: ['accepted']`; carrega o destino e aplica o **mesmo** check de tenant (404); chama o service; responde `['message','source'=>{id,code},'target'=>{id,code},'moved','renumbered']`.
- `restore()`: antes de restaurar, se `$record->merged_into_id` → 422 `validation.custom.patient.merged` com o `code` do destino (`Patient::withTrashed()->whereKey(...)`); impede ressuscitar duplicado.
- `show()`: `+= 'merged_into_code'` (via relação).
- `toTableRow()`: `+= 'can_merge' => $isAdmin && ! $p->trashed()`; `$isAdmin` calculado 1× no `index()` com `$request->user()?->hasAnyRoleInEntity($entity, [ClientRule::Admin])` (`$entity = Entity::find($entityId)`); assinatura ganha `bool $isAdmin = false`.
- Rotas (grupo Pacientes, antes do `Route::resource`):
  ```php
  Route::get('patients/{patient}/merge-candidates', [PatientsController::class, 'mergeCandidates'])
      ->middleware('entity.role:admin')->name('patients.merge-candidates');
  Route::post('patients/{patient}/merge', [PatientsController::class, 'merge'])
      ->middleware('entity.role:admin')->name('patients.merge');
  ```
  (o `permission:patients.manage,admin,financial,doctor,secretary` do grupo continua valendo — admin passa pelos dois; nomes finais ficam `panel.patients.merge*`).

### 3.4 Frontend (Prisma)

- `PatientTable.vue`: item **"Mesclar cadastro"** no dropdown de ações FULL quando `p.can_merge` → `$emit('merge', { id, code, full_name })`; documentar `can_merge` na interface da linha.
- `Patients/Index.vue`: estado do modal (`mergePatient`), handler que abre; `@merged` → `router.reload({ preserveScroll: true })`.
- **`Patients/PatientMergeModal.vue` (novo)**:
  - props `open`, `patient` ({id, code, full_name}), `labels` (de `t_ui.patient_merge`);
  - GET `merge-candidates` e POST `merge` via `requestJson` (`@/Support/clinicalSafety.js` — mesmo helper do drawer, CSRF incluso);
  - lista agrupada ("Duplicados prováveis" primeiro) com radio `code — nome — nascimento`; aviso "não tem desfazer"; confirmar desabilitado sem seleção;
  - 422 → alerta com a `message` do servidor; sucesso → `window.Swal.fire({icon:'success', text: data.message})` + `emit('merged')` + fechar;
  - `data-testid`: `patient-merge-modal`, `patient-merge-candidates`, `patient-merge-confirm`.
- `PatientDetailDrawer.vue`: quando `merged_into_code`, aviso "Mesclado em :code" (somente leitura).

### 3.5 Lang (paridade pt_BR/en)

- `ui.php`, bloco novo `patient_merge` (mesmo mecanismo do `patient_form`, lido por `t_ui`): `title`, `hint`, `duplicates`, `homonyms`, `none`, `confirm`, `cancel`, `error`.
- `actions.php`: `patient_merge` (ação no dropdown), `patient_merge_success` (":count registro(s) transferido(s)"), `patient_merged_into`.
- `validation.php`, `custom.patient`: `merged` (restaurar bloqueado, `:code`), `merge_conflict` (`:items`), `merge_conflict_conditions`, `merge_conflict_recalls`, `merge_conflict_blocks`, `merge_conflict_intravitreal`, `merge_conflict_drafts`.

### 3.6 Testes (Pest) — `tests/Feature/Patients/PatientMergeTest.php`

- **Drift da lista**: `information_schema.columns` (`column_name = 'patient_id'`, schema atual) vs `PatientMergeService::MOVABLE_TABLES` → igual (pega tabela nova esquecida).
- **Happy path**: dois pacientes da mesma clínica; origem com exames `EXM-…0001/0002`, destino com `EXM-…0001` → merge 200; destino com códigos únicos; `moved/renumbered` corretos; origem `trashed` + `merged_into_id`; auditoria: `updated` + `deleted` na origem, `merged` no destino; tabelas representativas movidas (`schedules`, `medical_records`, `financial_cash_entries`, `medical_record_documentations`).
- **Conflitos** (cada um → 422 e **nada movido**): condições abertas iguais; retorno por ordem médica; bloqueio ativo; intravítreo no mesmo olho; rascunho mesmo `user_id`+`context_key`.
- **Auto-resolução**: `recall_dirty_patients` nos dois → sobra 1 linha (do destino).
- **Guardas**: secretária/médico → 403; destino de outra clínica → 404; origem/target excluído ou mesmo id → 422; `confirm` ausente → 422.
- **Restore**: restaurar origem mesclada → 422 com o código do destino; restauração normal continua funcionando.
- **Candidatos**: devolve duplicado (mesmo nome+nascimento) e homônimo (mesmo nome, nascimento ≠) com id/code/nome/nascimento; ignora excluídos e o próprio; não vaza outra clínica.

### 3.7 Vitest — `tests/JavaScript/Panel/Patients/PatientMergeModal.test.js`

- Carrega candidatos ao abrir (fetch stubado); confirmar desabilitado sem seleção; POST `{target_id, confirm:true}`; 422 exibe `message`; sucesso emite `merged`. Item "Mesclar cadastro" só com `can_merge` (pode entrar no mesmo arquivo).

## 4. Item 3 — Visto/não visto por exame

### 4.1 Migration e modelo

- `2026_11_01_000100_add_viewed_at_to_patient_exams_table.php`: `$table->timestamp('viewed_at')->nullable()->after('active');` (sem índice nesta versão — avaliar índice parcial `WHERE viewed_at IS NULL` se o filtro pesar; configuração hoje é pequena).
- `app/Models/PatientExam.php`: cast `'viewed_at' => 'datetime'` (os campos novos vão em `casts()`); **não** entra em `$fillable` (gravação é por query update — §4.2).

### 4.2 Backend

- `routes/web.php`: `Route::put('/eye-images/exams/{exam}/viewed', [EyeImagesController::class, 'markExamViewed'])->name('eye-images.exams.viewed');` (sem middleware extra — quem vê a página pode marcar; binding + check de tenant no controller).
- `EyeImagesController::markExamViewed(PatientExam $exam): JsonResponse`:
  ```php
  // mesmo check de tenant de imageUrl()
  abort_unless((string) $exam->patient?->entity_id === (string) session('selected_entity_id'), 404);
  PatientExam::query()->whereKey($exam->id)->whereNull('viewed_at')->update(['viewed_at' => now()]);
  return response()->json(['id' => (string) $exam->id, 'viewed_at' => $exam->fresh()->viewed_at?->toIso8601String()]);
  ```
  Update por query = atômico, idempotente, sem evento de model → sem linha de auditoria e sem mexer `updated_at`.
- `serializePatients`: exame ganha `'viewed_at' => $exam->viewed_at?->toIso8601String()`.
- `EyeImageFilters`: constante `VIEWED = ['vistos', 'nao_vistos']`; `public ?string $viewed = null` (allowlist, senão `null`); `toArray()` inclui `viewed`.
- `examFilterClosure`: `vistos` → `whereNotNull('patient_exams.viewed_at')`; `nao_vistos` → `whereNull(...)`.

### 4.3 Frontend (`Index.vue`)

- Estado `examViewed = ref(props.filters?.viewed ?? '')`; entrada na barra de filtros (select Todos/Vistas/Não vistas); incluir `viewed` em `showFilters`, `clearFilters()` e no `watch` dos filtros; query `viewed`.
- `urls.exam_viewed_update` (rota com `__ID__`).
- `markExamViewed(exam)`: fire-and-forget com `Set` de ids já enviados (remover do Set em erro para tentar de novo); no sucesso, `applyViewedAt(exam.id, viewed_at)` atualiza **todas** as cópias locais (lista de pacientes + `viewerExams`) para a bolinha sumir sem reload.
- Chamada dentro de `setPanelExam(pi, exam)` — único ponto por onde todo o visualizador passa (abrir, avançar, pedal, atalhos).
- Miniatura: bolinha `data-testid="exam-unviewed-dot"` (`position-absolute end-0`, abaixo do círculo de lateralidade) quando `!exam.viewed_at`, `title` = `tt('unviewed_dot_title', 'Não visualizada')`.

### 4.4 Lang (pt_BR + en)

`all_viewed` ("Todas"), `viewed_yes` ("Vistas"), `viewed_no` ("Não vistas"), `filter_viewed` ("Visualização"), `unviewed_dot_title` ("Não visualizada").

### 4.5 Testes

- Pest `tests/Feature/EyeImages/EyeImageMarkViewedTest.php`: médico/secretária/admin marcam (200), `viewed_at` preenchido; segunda chamada mantém o timestamp original (idempotente); exame de outra clínica → 404; `AuditLog` de `PatientExam` não ganha linha nova (sem `updated`); `updated_at` do exame não muda.
- Pest `EyeImagesFiltersTest.php` (acrescentar): `viewed=nao_vistos` / `viewed=vistos` via `search`; allowlist (valor inválido → sem filtro).
- Vitest `tests/JavaScript/Panel/EyeImages/EyeImageViewedState.test.js` (helper de `PedalViewerAdvance.test.js` — `shallowMount` + fetch stubado): abrir exame dispara 1 PUT e seta `viewed_at` local; segunda exibição não repete; bolinha aparece/some; filtro envia `viewed=nao_vistos`.

## 5. Item 4 — Marcação urgente

### 5.1 Migration e modelo

- `2026_11_01_000200_add_urgent_to_patient_exams_table.php`: `$table->boolean('urgent')->default(false)->after('viewed_at');` (sem índice nesta versão; avaliar `(patient_id, urgent)` se a ordenação pesar).
- `PatientExam`: `urgent` em `$fillable` (é atualizado via model, auditado) e cast `'urgent' => 'boolean'`.

### 5.2 Backend

- `EyeImageExamActionsController::updateUrgent(Request $request, PatientExam $exam): JsonResponse`:
  ```php
  $this->authorizeExam($exam);
  $validated = $request->validate(['urgent' => ['required', 'boolean']]);
  $exam->update(['urgent' => (bool) $validated['urgent']]);
  return response()->json(['id' => (string) $exam->id, 'urgent' => (bool) $exam->urgent]);
  ```
- Rota: `PUT /eye-images/exams/{exam}/urgent` → `->middleware('entity.role:admin,doctor')->name('eye-images.exams.urgent.update')` (mesmo bloco das ações rápidas).
- `serializePatients`: `'urgent' => (bool) $exam->urgent`.
- `EyeImageFilters`: `public bool $urgent = false` (`$request->boolean('urgent')`); `toArray()` inclui `urgent`; `examFilterClosure`: `where('patient_exams.urgent', true)`.
- Ordenação (`queryPatients`): pacientes `withCount(['exams as urgent_exams_count' => fn ($q) => $q->where('urgent', true)])->orderByDesc('urgent_exams_count')->orderByDesc('updated_at')`; exames do paciente `orderByDesc('urgent')->orderByDesc('created_at')` (depois do `$examFilter`). `countFilteredExams` não muda.

### 5.3 Frontend

- `EyeImageContextMenu.vue` (bloco `isDoctor`, depois da qualidade): botão alterna `exam.urgent` — `context_menu_urgent_mark` / `context_menu_urgent_clear`, `busy` igual às outras ações; `emit('urgent', { exam, urgent })` ou callback via `urls.exam_urgent_update` (seguir o mecanismo de `quality_rating` já existente no componente; atualizar a cópia local no sucesso).
- `Index.vue`: badge vermelho **primeiro** na pilha topo-esquerda da miniatura (`data-testid="exam-urgent-badge"`, `badge bg-danger`, texto `tt('urgent_badge', 'Urgente')`), antes do diagnóstico/importado/desabilitada/qualidade.
- Filtro: checkbox "Só urgentes" (`onlyUrgent = ref(false)`), incluído em `showFilters`, `clearFilters()` e `watch`; query `urgent=1`.
- `urls.exam_urgent_update` (rota com `__ID__`).

### 5.4 Lang (pt_BR + en)

`filter_urgent_only` ("Só urgentes"), `urgent_badge` ("Urgente"), `context_menu_urgent_mark` ("Marcar como urgente"), `context_menu_urgent_clear` ("Remover urgência"), `urgent_badge_title` ("Exame marcado como urgente").

### 5.5 Testes

- Pest `tests/Feature/EyeImages/EyeImageExamActionsTest.php` (acrescentar `describe('updateUrgent')`): médico marca/desmarca (200 + `urgent` no JSON); admin → 403 (Gate doctor-only); secretária → 403 (middleware); exame de outra clínica → 403; payload inválido → 422; `AuditLog` registra a mudança (garante o item 6).
- Pest `EyeImagesFiltersTest.php`: `urgent=1` traz só exames urgentes; paciente com exame urgente aparece antes na lista.
- Vitest `tests/JavaScript/Panel/EyeImages/EyeImageUrgent.test.js`: monta `EyeImageContextMenu` com `isDoctor=true` e confere o botão/estado; monta `Index` e confere badge quando `urgent` e o param `urgent=1` no fetch.

## 6. Item 5 — Filtro por médico solicitante

### 6.1 Backend

- `EyeImageFilters`: `public ?string $requestingDoctor = null`; `fromRequest`: `trim((string) $request->input('requesting_doctor'))`, vazio → `null`, senão `mb_substr(…, 0, 80)`; `toArray()` inclui `requesting_doctor`.
- `examFilterClosure` (após os outros filtros):
  ```php
  if ($f->requestingDoctor !== null) {
      $q->whereExists(function ($sub) use ($f, $entityId): void {
          $sub->selectRaw('1')
              ->from('medical_record_documentation_exams as mrde')
              ->join('medical_record_documentations as mrd', 'mrd.id', '=', 'mrde.medical_record_documentation_id')
              ->whereColumn('mrde.patient_exam_id', 'patient_exams.id')
              ->where('mrd.entity_id', $entityId)
              ->whereNull('mrd.deleted_at')
              ->whereLikeUnaccent('mrd.requesting_doctor_name', $f->requestingDoctor);
      });
  }
  ```
  (pivot tem `entity_id` próprio; a documentação é a fonte do nome; macro `whereLikeUnaccent` já registrada em QueryBuilder/Eloquent — `app/Support/Database/AccentInsensitiveSearch.php`.)
- Sem rota nova; vale para `index` e `search` (mesmo DTO/closures).

### 6.2 Frontend

- `requestingDoctor = ref(props.filters?.requesting_doctor ?? '')`; input texto na barra de filtros (`maxlength="80"`, `placeholder` traduzido); incluir em `showFilters`, `clearFilters()` e `watch`; query `requesting_doctor` (só quando não vazio).

### 6.3 Lang (pt_BR + en)

`filter_requesting_doctor` ("Médico solicitante"), `filter_requesting_doctor_placeholder` ("Nome do médico que pediu o laudo...").

### 6.4 Testes

- Pest `EyeImagesFiltersTest.php`: exame cujo laudo tem `requesting_doctor_name = 'Dr. Carlos Souza'` casa com `requesting_doctor=carlos` (parcial, caixa e acento); exame sem laudo não casa; documentação soft-deleted não casa; outra clínica não vaza; combinado com `eye`/`period` mantém o `AND`.
- Vitest `tests/JavaScript/Panel/EyeImages/EyeImageRequesterFilter.test.js`: digitar e submeter envia `requesting_doctor` no fetch; limpar remove o param.

## 7. Item 6 — Histórico (auditoria) do paciente no Eye Images

### 7.1 Backend

- **Rota** (junto das rotas `eye-images.*`):
  ```php
  Route::get('/eye-images/patients/{patient}/audit', EyeImagePatientAuditController::class)
      ->middleware('permission:patients.manage,admin,financial,doctor,secretary')
      ->name('eye-images.patients.audit');
  ```
- **`app/Http/Controllers/EyeImagePatientAuditController.php` (novo, `__invoke`)**:
  - tenant: `abort_unless((string) $patient->entity_id === (string) session('selected_entity_id'), 404)`;
  - `patient_exam_ids` do paciente via subquery `PatientExam::query()->where('patient_id', $patient->id)->select('id')`;
  - `AuditLog::query()->where(fn ($q) => $q->where(fn ($p) => $p->where('auditable_type', Patient::class)->where('auditable_id', $patient->id))->orWhere(fn ($e) => $e->where('auditable_type', PatientExam::class)->whereIn('auditable_id', $examIds)))->where(fn ($q) => $q->where('entity_id', $entityId)->orWhereNull('entity_id'))->orderByDesc('created_at')->orderByDesc('id')->paginate(20)`;
    (`orWhereNull(entity_id)` cobre ação de integrador; `auditable_id` é global, não vaza outra clínica.)
  - carrega em lote os nomes de `User` da página e os `code` dos exames da página (sem N+1);
  - monta entradas (§8) com **allowlists de valor** (constantes na classe):
    - `Patient`: `active, priority_rating, covenant_id, covenant_plan_id, skin_id, iris_id, allergies_denied_at, deleted_at, merged_into_id` + campos sintéticos do evento `merged`: `source_id, source_code, moved, renumbered`;
    - `PatientExam`: `active, laterality, quality_rating, urgent, exam_session_id, exam_id, doctor_id, schedule_id, source, exam_performed_at`;
  - `changes`: só para `updated` (diff `old_values`×`new_values`) e `merged` (`new_values`); demais eventos (`created`/`deleted`/`restored`) devolvem `changes: []` (o evento é a informação; não devolve o retrato do cadastro). Campo fora da allowlist → só o `field` + `redacted: true` (`old`/`new` nulos). `ip_address`/`user_agent` nunca saem.
- **`EyeImagesController::index`**: props `can_view_patient_audit` (espelho do middleware: `hasPermissionInEntity($entity, Permission::PatientsManage)` **ou** `hasAnyRoleInEntity($entity, [Admin, Financial, Doctor, Secretary])`) e `urls.patient_audit` (rota com `__ID__`).

### 7.2 Frontend

- **`EyeImagePatientAuditModal.vue` (novo)**, props `open`, `patient` ({id, code, full_name}), `url`, `labels` (via `:t="t"` como os demais filhos):
  - busca `url` ao abrir; lista por entrada: data/hora, evento (badge), alvo (Cadastro/Exame + código), quem (`actor_name` ou "Sistema"/"Integração"), detalhes (`field` traduzido via `tt('audit_field_'+field, field)`: `old → new`, ou "valor não exibido" quando `redacted`);
  - "Carregar mais" (`meta.current_page < meta.last_page`, append);
  - estados de vazio/erro com `data-testid`s (`patient-audit-modal`, `patient-audit-entry`, `patient-audit-more`).
- **`Index.vue`**: botão **"Histórico"** no cabeçalho do card do paciente, ao lado do link "Prontuário" (~linha 2619), visível só com `can_view_patient_audit`; abre o modal com `selectedPatient`.

### 7.3 Lang (pt_BR + en)

`audit_button` ("Histórico"), `audit_title` ("Histórico do cadastro"), `audit_empty`, `audit_load_more`, `audit_target_patient` ("Cadastro"), `audit_target_exam` ("Exame"), `audit_actor_system`, `audit_actor_integrator`, `audit_value_hidden` ("valor não exibido"), `audit_when`/`audit_who`/`audit_target`, `audit_event_created/updated/deleted/restored/merged`, e `audit_field_*` para os campos das allowlists (fallback: nome técnico do campo).

### 7.4 Testes

- Pest `tests/Feature/EyeImages/EyeImagePatientAuditTest.php`:
  - `updated` de exame (`laterality 1→2`) aparece com valores; `observation` alterada aparece só com nome do campo + `redacted=true` (sem texto clínico);
  - `updated` de paciente (`priority_rating`) com valor; `created/deleted/restored` com `changes: []`;
  - `merged` (evento explícito do item 2) com `source_code/moved`;
  - paginação (25 logs → `meta.last_page = 2`);
  - ACL: médico com acesso → 200; papel sem `patients.manage` e fora da allowlist → 403; outra clínica → 404; logs de **outro** paciente da mesma clínica não aparecem; log com `entity_id = null` do exame aparece;
  - contrato: nenhum `old`/`new` de campo clínico em nenhuma entrada; sem `ip_address`/`user_agent`.
- Vitest `tests/JavaScript/Panel/EyeImages/EyeImagePatientAuditModal.test.js`: carrega e renderiza entradas, redação, "Carregar mais", vazio.

## 8. Contrato de props/payloads (Forja → Prisma)

### Eye Images (Inertia `index`)

| Prop | Tipo | Item |
|---|---|---|
| `t` | objeto (`trans('eye_images')`) — já existe | — |
| `urls.exam_viewed_update` | string com `__ID__` | 3 |
| `urls.exam_urgent_update` | string com `__ID__` | 4 |
| `urls.patient_audit` | string com `__ID__` | 6 |
| `can_view_patient_audit` | bool | 6 |
| `filters.viewed` | `''\|'vistos'\|'nao_vistos'` | 3 |
| `filters.urgent` | bool | 4 |
| `filters.requesting_doctor` | string\|null | 5 |

Exame em `patients[].exams[]` (acréscimos): `viewed_at: string|null` (ISO 8601, item 3), `urgent: bool` (item 4). Paciente: sem mudanças.

### Endpoints Eye Images

- `PUT /panel/eye-images/exams/{exam}/viewed` → `200 { "id": "uuid", "viewed_at": "ISO" | null }`; `404` fora da clínica.
- `PUT /panel/eye-images/exams/{exam}/urgent` body `{ "urgent": true|false }` → `200 { "id": "uuid", "urgent": true|false }`; `403`/`422`/`404` conforme ACL/validação.
- `GET /panel/eye-images/patients/{patient}/audit?page=N` → `200`:
  ```json
  {
    "entries": [{
      "id": "uuid",
      "at": "2026-10-18T14:03:11-03:00",
      "event": "updated" | "created" | "deleted" | "restored" | "merged",
      "target": "patient" | "exam",
      "target_code": "PAC-0000000123" | "EXM-0000000042",
      "actor_name": "FULANA DE TAL" | null,
      "actor_kind": "user" | "integrator" | "system",
      "changes": [
        { "field": "laterality", "old": 1, "new": 2, "redacted": false },
        { "field": "observation", "old": null, "new": null, "redacted": true }
      ]
    }],
    "meta": { "current_page": 1, "last_page": 3, "per_page": 20, "total": 47 }
  }
  ```

### Pacientes (item 2)

- Linha da tabela: `+= can_merge: bool`.
- `GET /panel/patients/{patient}/merge-candidates` → `{ "data": [{ "id", "code", "full_name", "birth_date": "Y-m-d"|null, "age": int|null, "kind": "duplicate"|"homonym" }] }`.
- `POST /panel/patients/{patient}/merge` body `{ "target_id": "uuid", "confirm": true }` → `200 { "message", "source": {"id","code"}, "target": {"id","code"}, "moved": int, "renumbered": int }`; `422 { "message", "errors": { "target_id": ["..."] } }`; `404` fora da clínica; `403` não-admin.
- `GET` de paciente (`show`): `+= merged_into_code: string|null`.

## 9. Fatiamento em commits (só com "commit autorizado")

1. `docs: plano Eye Images (gaps vs referência)` — este arquivo (+ briefing untracked, se o orquestrador autorizar).
2. `feat(eye-images): tamanhos 8/9/12/15/16 na impressão por folha` — `printSheets.js`, `Index.vue`, `lang/*/eye_images.php`, 2 testes Vitest.
3. `feat(patients): mesclar cadastro com renumeração e auditoria` — migration, `Patient`, `PatientMergeService`, `PatientsController`, `routes/web.php`, `PatientTable.vue`, `Index.vue`, `PatientMergeModal.vue`, `PatientDetailDrawer.vue`, `lang/*/{ui,actions,validation}.php`, Pest + Vitest.
4. `feat(eye-images): marcar exame como visto ao abrir` — migration `viewed_at`, `PatientExam`, `EyeImagesController`, `EyeImageFilters`, `routes/web.php`, `Index.vue`, lang, Pest (`EyeImageMarkViewedTest` + filtros), Vitest.
5. `feat(eye-images): marcar exame como urgente` — migration `urgent`, `PatientExam`, `EyeImageExamActionsController`, `EyeImagesController` (ordem/payload), `EyeImageFilters`, `routes/web.php`, `Index.vue`, `EyeImageContextMenu.vue`, lang, Pest + Vitest.
6. `feat(eye-images): filtrar por médico solicitante` — `EyeImageFilters`, `Index.vue`, lang, Pest (filtros) + Vitest.
7. `feat(eye-images): histórico de alterações do paciente` — `EyeImagePatientAuditController`, `EyeImagesController`, `routes/web.php`, `EyeImagePatientAuditModal.vue`, `Index.vue`, lang, Pest + Vitest.

## 10. Verificação por commit

- Comandos reais do repositório (sem `--parallel`; regras de `easyeye_test` em §11):
  - Vitest do item: `rtk npm test -- <arquivos do item>`.
  - Pest do item (somente com autorização para tocar o banco): `rtk proxy php vendor/bin/pest --filter=<Filtro>`.
  - Estilo/fmt só nos tocados: `rtk proxy php vendor/bin/pint --test <arquivos PHP>`; `rtk npx prettier --check <arquivos JS/Vue>` (ou `rtk npm run format:check`, que é repo-wide e tem dívida conhecida — reportar como está, sem "consertar" arquivo de terceiro).
- Cada commit só fecha com o teste do item verde **e** sem regressão nos testes vizinhos (`EyeImagesFiltersTest`, `EyeImageExamActionsTest`).

## 11. Riscos globais (ordem decrescente)

1. **Item 2 é o de maior risco**: 38 tabelas, locks e unicidade. Mitigações: transação única, ordem de locks por `id`, pré-checagens fail-closed, drift test por `information_schema`, auditoria nos dois cadastros e teste de conflito garantindo "nada movido".
2. **Financeiro/TISS**: `billing_claims`/`tiss_guides` passam a apontar para o cadastro mantido (mesma pessoa, código `PAC-` novo). Guias já enviadas ao convênio continuam com o código antigo nos arquivos externos — conferência humana antes de mesclar pacientes com faturamento aberto.
3. **Rascunhos de prontuário**: conflito é fail-closed (não apaga digitação); a mensagem precisa dizer qual área (`context_key`) está presa — senão vira suporte.
4. **Itens 3–5 compartilham `examFilterClosure`/`Index.vue`**: commits sequenciais e testes acumulativos no mesmo arquivo de filtros evitam conflito com outras sessões.
5. **`viewed_at` sem índice**: se o filtro "Não vistas" ficar pesado em clínicas grandes, medir antes de criar índice parcial (não entra nesta versão).
6. **Banco de testes**: Pest só em `easyeye_test`, sem `--parallel`, com autorização e pré-requisitos do `AGENTS.md`; `RefreshDatabase` recria schema — efeito precisa estar autorizado.

## 12. Fora de escopo (itens 7–9 do briefing) e extensões registradas

- **Item 7** — "Parecer" rápido distinto do laudo (o próprio relatório marca como possível escopo demais).
- **Item 8** — IOL/LIO embutido na tela de exames (vetado pelo dono).
- **Item 9** — validação de lateralidade no Comparar (OD+OD / OE+OE) — `EyeImageCompareModal.vue` não valida hoje.
- Extensões (não implementar sem pedido novo): desfazer merge; unificar `People`/Portal do Paciente; `viewed_by` (quem viu); triagem de `urgent` pela recepção; rascunhos no filtro de médico solicitante; índice parcial de `viewed_at`/`urgent`; conflito de identidade (CPF/nome divergente) no merge.

## 13. Decisões do orquestrador (fecham a §1)

1. Todas as nove decisões da §1 ficam como estão (1/9/15 sem pareamento com aviso; destino = cadastro aberto; sem desfazer; People/Portal intocados; conflitos fail-closed 422; renumeração `EXM-`; merge só admin; visto sem "quem viu" e sem auditoria; urgente só médico e auditado; solicitante só laudo salvo; histórico só Patient + PatientExam, sem valor clínico, sem IP/UA).
2. Briefing `eye-images-gaps-briefing.md` entra no commit 1 junto com este plano.
3. Ordem de implementação no backend: 3 → 4 → 5 → 6 → 2 (itens pequenos que compartilham `EyeImageFilters`/`EyeImagesController` primeiro, merge de paciente por último); front: 1 → 3 → 4 → 5 → 6 → 2. Commits seguem a §9.
4. Mensagem de conflito do merge (§3.2) diz a área presa: para `medical_record_drafts`, inclui o `context_key`; para os demais, o olho/motivo/origem do registro.
5. Item 2: `candidates()` devolve só pacientes da mesma clínica, não excluídos e não mesclados (`merged_into_id` nulo); o `target` do POST tem de estar na lista de candidatos da origem (impede mesclar dois cadastros sem relação por id adivinhado).
6. Item 6: `per_page` fixo em 20 (sem parâmetro do cliente).
7. **Item 2 — registros assinados (bloqueio achado na implementação)**: `signature_hash` do prontuário assinado (`Signable::clinicalContent` hasheia `getAttributes()`, incluindo `patient_id`) e `content_hash` do módulo cirúrgico (descrição, adendo, pós-op, implante e documento emitido guardam `'patient' => patient_id`) quebrariam ao mover. Política fail-closed: o merge recusa (422) se a **origem** tiver prontuário assinado/travado (`signed_at`/`is_locked`) **ou** qualquer caso cirúrgico (qualquer status); a mensagem orienta a mesclar a partir do outro cadastro (o que guarda os assinados vira destino e nada dele é movido). Se os dois lados têm assinados/cirurgia: não mescla nesta versão (extensão: retificação assinada). Guard num método só, coberto por teste nos dois sentidos.
8. **Item 3 — backfill**: a migration de `viewed_at` preenche os exames já existentes com `viewed_at = created_at` (a bolinha é para triagem do que chega a partir de agora; sem isso todo histórico aparece como "não visto"). Riscos registrados pela Forja e aceitos: merge só entre cadastros de mesmo nome normalizado e bloqueado com assinado/cirurgia na origem; escrita em voo na origem que commita após o merge (janela de milissegundos) não tratada.
