# ADR 0001 — Decisões arquiteturais do Assistente de IA

Data: 2026-06-12 · Status: aceito

Este documento registra as decisões de design feitas durante o desenvolvimento do
Assistente de IA (Ondas 1–4) que **não são óbvias da leitura do código**.

---

## 1. Cancel cooperativo (vs. interrupção forçada de HTTP)

### Status
Aceito (Onda 1).

### Contexto
O `RunAiWorkflowJob` orquestra 1–3 chamadas HTTP sequenciais para providers de
LLM (Gen → Rev → Adj). Uma chamada já em voo não pode ser cancelada de forma
limpa: cancelar o stream HTTP não impede o provider de cobrar e o conteúdo
parcial seria inutilizável para o médico.

### Decisão
Cancel é **cooperativo entre etapas** via coluna `ai_runs.cancelled_at`:

- O endpoint `POST /panel/ai-runs/{aiRun}/cancel` marca `cancelled_at = now()` e
  retorna `will_settle_async: true` quando o status é `Running`.
- O `AiOrchestrator` faz `$run->refresh()` no início de cada role e lança
  `AiRunCancelledException` se detectar a marca.
- O `AiRunExecutionService::compensateCancelledRun()` libera apenas o saldo
  **não consumido** (chamadas que sucederam antes do cancel já são pagas).

### Consequências
- ✓ Sem perda de auditoria — o que rodou está nos `ai_run_provider_calls`.
- ✓ Sem créditos perdidos para o que ainda não rodou.
- ✗ Chamada em voo no momento do cancel ainda é cobrada pelo provider.
- A UI mostra "Cancelando…" enquanto orchestrator chega no checkpoint.

---

## 2. `parent_run_id` em vez de `retry_count`

### Status
Aceito (Onda 3).

### Contexto
A funcionalidade "Reanalisar com modo superior" precisa preservar a auditoria do
run original (CFM Resolução 2.227/2018). Soluções consideradas:

- (a) Coluna `retry_count` no mesmo run + atualizar `input_summary`.
- (b) Soft-delete do antigo + criar novo.
- (c) Novo run independente com referência ao parent.

### Decisão
Opção (c) — `ai_runs.parent_run_id` nullable, FK para `ai_runs.id` com
`nullOnDelete`. Cada reanálise é um run completamente novo (créditos, calls,
final_output próprios). Backend e UI mostram a linhagem via `is_escalation`.

### Consequências
- ✓ Auditoria preserva original intacto + reanálise.
- ✓ Possibilita cadeias multi-nível (Economy → Validated → Consensus).
- ✓ `nullOnDelete` mantém filhos acessíveis se parent for purgado por GDPR.
- ✗ Custo: cada reanálise é cobrada do zero.

---

## 3. Allowlist fixa de tags de feedback

### Status
Aceito (Onda 3).

### Contexto
O feedback do médico (quando edit ratio > 30%) precisa ser estruturado para
virar dataset utilizável. Opções:

- (a) Texto livre apenas.
- (b) Taxonomia editável por entity (CRUD admin).
- (c) Allowlist fixa de tags + nota livre opcional.

### Decisão
Opção (c). Tags válidas codificadas em `AiFeedbackService::ALLOWED_TAGS`:
`diagnosis_wrong`, `language`, `missing_context`, `excess`, `other`.

### Consequências
- ✓ Dataset uniforme entre todas as clínicas (comparável agregadamente).
- ✓ Schema simples sem CRUD admin.
- ✗ Mudar taxonomia exige migration + deploy (compromisso aceitável para essa fase).

---

## 4. Cache 5 min em quota e analytics (vs. tempo real)

### Status
Aceito (Onda 3.5).

### Contexto
`AiQuotaService::currentMonthSnapshot()` é chamado em todo abertura de painel
(EyeImages + MedicalRecords) e do dashboard `/panel/usage`. `AiAnalyticsService`
roda 3 queries pesadas no `/panel/usage`. Sem cache, isso é 5+ queries por
request para uma entity com 100k+ runs.

### Decisão
- `AiQuotaService::currentMonthSnapshot()` → `Cache::remember()` 300s, chave
  `ai:quota:{entityId}:YYYY-MM` (renova-se ao virar mês).
- `AiAnalyticsService::{byDoctor, averageApproveSeconds, averageCostPerRecord}`
  → cada um em `Cache::remember()` 300s, chave por kind+entity+intervalo.
- Invalidação via observer em `AiRun::booted()` quando muda
  `status`/`consumed_credits`/`approved_at`.

### Consequências
- ✓ Primeira request paga, próximas 5min usam cache.
- ✓ Aprovação/cancel/falha invalida em tempo real (observer).
- ✗ Edge case: cache pode ficar stale por até 5 min se a invalidação falhar
  silenciosamente. Aceitável para um indicador de cota.

---

## 5. Quick picks em i18n hierárquico (vs. tabela DB)

### Status
Aceito (Onda 2).

### Contexto
Quick picks categorizados por patologia (Glaucoma, Catarata, Retinopatia, …).
Cada categoria com 2–4 prompts. Médicos clinical-consultor querem ajustar
periodicamente.

### Decisão
i18n hierárquico em `lang/pt_BR/ai.php` (chave `assistant.quick_picks`) como
dict `categoria → list<prompt>`. Front aceita também o formato legado
`list<string>` para backward compat.

### Consequências
- ✓ Sem migration nem UI admin para editar.
- ✓ Engineering edita e faz deploy.
- ✓ Auditoria fica no git.
- ✗ Não é por-tenant — toda clínica vê os mesmos prompts.
- ✗ Engineering tem que ser envolvido em mudanças cosméticas de prompt.

Se virar problema, o caminho de saída é tabela `ai_quick_picks` (entity_id
nullable + global default). Por ora, o atrito é aceitável.

---

## 6. Backend permite "burlar" cota mensal (créditos avulsos)

### Status
Aceito (Onda 4).

### Contexto
O botão "Analisar" no painel da IA é bloqueado quando `quota.usage_percent >= 95`.
Decisão: bloquear também no backend?

### Decisão
**Não bloquear no backend.** A validação hard de "tem ou não tem crédito" é o
`InsufficientAiCreditsException` (saldo absoluto). Cota mensal é um indicador
de produtividade, não uma feature gate.

### Consequências
- ✓ Quem comprou créditos avulsos não fica bloqueado pelo medidor.
- ✓ API direta (integrações) não vê falha 422 inesperada.
- ✗ Médico tecnicamente pode chamar via API mesmo com cota cheia, desde que
  tenha saldo. Aceitável — não é dado um botão para fazer isso pela UI.

---

## 7. Soft-delete em `ai_doctor_prompts`

### Status
Aceito (Onda 4).

### Contexto
Médico apaga acidentalmente um template de prompt e perdia. Foi sugerido
adicionar "lixeira" na UI, mas isso polui a tela para um caso raro.

### Decisão
Soft delete (`deleted_at`) + sem UI de lixeira. Suporte resgata via tinker se
necessário (ou via comando futuro `ai:restore-prompt`).

### Consequências
- ✓ Resgate possível por suporte.
- ✓ UI continua simples — Eloquent filtra deleted_at automaticamente.
- ✗ Nenhuma — o trade-off é favorável.

---

## 8. Hard-delete em `ai_run_feedbacks` (>90 dias)

### Status
Aceito (Onda 4).

### Contexto
`ai_run_feedbacks.note` pode conter PHI (nota livre do médico). LGPD Art. 16:
dados não devem ser conservados além do necessário.

### Decisão
Comando `ai:purge-feedbacks --days=90` agendado para domingo 03:00.
**Hard delete** (não soft) — feedbacks com mais de 90 dias somem definitivamente.

### Consequências
- ✓ Conformidade LGPD por padrão.
- ✓ 90 dias é janela suficiente para análise agregada (futura Onda manager).
- ✗ Tag estatísticas perdidas após 90 dias. Aceitável se exportarmos para BI
  externo antes — não é responsabilidade desse domínio.

---

## Referências

- CLAUDE.md (raiz do projeto) — convenções gerais.
- `docs/CHANGELOG-ai.md` — sumário cronológico das 4 ondas.
- Migrations em `database/migrations/2026_05_17_*ai*` em diante.
