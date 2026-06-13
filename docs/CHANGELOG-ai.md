# Changelog — Assistente de IA

Linha do tempo das 4 ondas de melhoria do módulo de IA no EasyEye. Cada onda foi
planejada, executada, testada e revisada de forma independente — todas mantêm
compatibilidade retroativa com as anteriores.

Formato: [Keep a Changelog](https://keepachangelog.com/), `## [Onda N] — categoria`.

---

## [Onda 4] — Consolidação de dívidas · 2026-06-12

### Adicionado
- **Soft-delete em `ai_doctor_prompts`** — médico recupera template apagado por
  acidente via suporte/tinker.
- **Linhagem parent → escalation visível** — badge "↩ Reanálise" na tabela do
  dashboard e linha "Esta análise é uma reanálise do run anterior" no painel.
- **Bloqueio do botão "Analisar"** quando cota mensal ≥ 95% — mensagem
  pedagógica + tooltip de cota exaurida.
- **Notificação por email 24h** para runs em `WaitingApproval` esquecidos pelo
  médico (`ai:notify-waiting-approval`, agendado diariamente 07:00).
- **Purge LGPD semanal** de `ai_run_feedbacks` > 90 dias
  (`ai:purge-feedbacks`, agendado domingos 03:00).
- **`SearchSelect.vue` com modo remoto** (props `remoteSearchUrl` + `remoteMinChars`)
  — base para autocomplete de qualquer endpoint REST que retorne `{data: [...]}`.
- **ADR 0001** documentando as 8 decisões arquiteturais não-óbvias.

### Corrigido
- `byMode` no dashboard `/panel/usage` falhava com `(string) $row->mode` quando
  havia runs no período (cast enum vs. concat string). Agora usa `?->value`.

### Schema
- `ai_doctor_prompts` ganhou `deleted_at`.
- `ai_runs` ganhou `notified_pending_at` + índice composto
  `(status, notified_pending_at)`.

### Testes
- Pest: `SoftDeleteDoctorPromptsTest`, `ParentRunExposureTest`,
  `NotifyWaitingApprovalTest`, `PurgeFeedbacksTest` (16 testes novos).
- Vitest: `AiAssistantPanelQuotaBlock`, `SearchSelectRemote` (10 testes novos).

---

## [Onda 3.5] — Polimento operacional · 2026-06-12

### Adicionado
- Link no sidebar para `/panel/setting/ai-prompts` (médico → submenu IA com
  "Consumo & dashboard" + "Meus prompts").
- Cache de 5min em `AiQuotaService::currentMonthSnapshot()` e em `AiAnalyticsService`
  (`byDoctor`, `averageApproveSeconds`, `averageCostPerRecord`).
- Invalidação automática de cache via observer em `AiRun::booted()`.

---

## [Onda 3] — Produtividade médica · 2026-06-12

### Adicionado
- **P1 — Templates pessoais de prompt** (`ai_doctor_prompts`, limite 5/médico).
  CRUD em `/panel/setting/ai-prompts` + chips inline no painel "Meus prompts".
- **P2 — Reanalisar com modo superior** (Economy → Validated → Consensus).
  Endpoint `POST /panel/ai-runs/{id}/escalate` cria run filho preservando
  `input_summary` e `parent_run_id`.
- **P3 — Autocomplete remoto** (backend): endpoints `searchPatients` e
  `searchMedicalRecords` no `AiRunsController`. Frontend plugado na Onda 4.
- **P4 — Métricas dashboard**: cards "Médicos mais ativos" (top 10), "Tempo médio
  para aprovar", "Custo médio por consulta" via `AiAnalyticsService`.
- **P5 — Feedback loop**: quando edit ratio > 30% no aprove, inline modal com 5
  tags + nota livre opcional. Persistido em `ai_run_feedbacks`.

### Schema
- `ai_doctor_prompts` (id, doctor_id, entity_id, label, prompt, position).
- `ai_runs.parent_run_id` nullable FK para auditoria de reanálises.
- `ai_run_feedbacks` (ai_run_id unique, edit_ratio_percent, tags json, note).

### Refactor
- `AiQuotaService` extraído do controller para uso compartilhado.
- `AiAnalyticsService` novo, centralizando queries do dashboard.

### Testes
- Pest: `AiDoctorPromptsTest`, `EscalateRunTest`, `AiSearchTest`,
  `AiAnalyticsServiceTest`, `AiFeedbackTest` (34 testes novos).
- Vitest: `AiAssistantPanelOnda3` (12 testes novos).

---

## [Onda 2] — UX clínica · 2026-06-12

### Adicionado
- **F1 — Split view de imagens** (eye_image): preview lateral 560px + painel
  expandido para 1200px no fluxo de imagem ocular.
- **F2 — Quick picks categorizados** por patologia (Geral, Glaucoma, Retinopatia
  diabética, Catarata, Retina). i18n hierárquico aceita formato legado
  `list<string>` para backward compat.
- **F3 — Diff visual** rascunho IA vs. edição médica via `diff@5` (jsdiff).
  Botão "Ver edições" toggle com `<ins>`/`<del>` coloridos. `original_draft`
  vai junto no approve (auditoria CFM).
- **F4 — Histórico do paciente** no painel: `<details>` colapsável com os
  últimos 5 runs aprovados. Click reabre `view` mode com laudo antigo.
- **F5 — Safety flags visíveis** no review — alerta amarelo/vermelho com a lista
  de `AiSafetyService` detectada pelo backend.
- **F6 — Progress ring de cota mensal** (SVG 32×32) — verde < 70%, amarelo
  70–89%, vermelho ≥ 90%.
- **F7 — Atalho `Ctrl/Cmd+Enter`** aprova no review + autofocus no textarea.

### Schema
Nenhuma migration nova.

### Testes
- Vitest: `AiAssistantPanelQuickPicks`, `AiAssistantPanelDiff` (10 testes novos).

---

## [Onda 1] — Confiabilidade · 2026-06-12

### Adicionado
- **B1 — Cancel real** com estorno automático. Endpoint
  `POST /panel/ai-runs/{id}/cancel` + cancel cooperativo no `AiOrchestrator`
  via `cancelled_at`. UI mostra "Cancelando…" durante settlement.
- **B2 — Polling com backoff exponencial** (1500ms → 8000ms, fator 1.5,
  deadline 5min). ETA por modo (econ 10s, val 25s, cons 45s).
- **B3 — Step tracking visível**: `current_role` + `current_provider`
  expostos no `show()`. Painel mostra "Revisando com Claude…" / "Consolidando
  com Gemini…" durante o spinner.
- **B4 — Parser robusto** (`parseStructured`): `stripFence()` +
  `extractFirstJsonObject()` com contagem balanceada de chaves (tolera escape,
  nesting, texto extra).
- **B5 — Refactor**: `validatedPayload` (174 linhas no controller) virou
  `StoreAiRunRequest` + `EstimateAiRunRequest` + `AiPayloadEnricher`.
- **`AiRunCancelledException`** para sinalização cooperativa.

### Schema
- `ai_runs` ganhou `cancelled_at`, `cancelled_by`, `current_role`,
  `current_provider`, `started_at`.

### Testes
- Pest: `CancelRunTest`, `StepTrackingTest`, `AiPayloadEnricherTest` (16 testes
  novos).
- Vitest: `AiAssistantPanel` (8 testes novos cobrindo cancel + parser).

---

## Compatibilidade

Nenhuma onda quebra contrato anterior. Quem clona o repo hoje no estado pós-Onda
4 tem todo o pipeline funcionando sem necessidade de migrações manuais —
`php artisan migrate` aplica tudo na ordem.

Suite de regressão (Pest + Vitest) é cumulativa: cada onda mantém os testes das
anteriores passando.

## Operação

Comandos artisan novos:

- `ai:notify-waiting-approval [--dry-run]` — schedule diário 07:00.
- `ai:purge-feedbacks [--days=90] [--dry-run]` — schedule semanal domingo 03:00.

Ambos seguros para rodar manualmente.
