# Changelog — Assistente de IA

Linha do tempo das 4 ondas de melhoria do módulo de IA no EasyEye. Cada onda foi
planejada, executada, testada e revisada de forma independente — todas mantêm
compatibilidade retroativa com as anteriores.

Formato: [Keep a Changelog](https://keepachangelog.com/), `## [Onda N] — categoria`.

---

## [2026-10-03] — Finanças: análise por IA e "Converse com os dados"

### Corrigido
- Análise e chat ignoravam o período selecionado (o POST não mandava preset/datas e o
  servidor caía em "este mês").
- Análise com JSON entre cercas (```json) dava erro: agora decodificada no servidor
  (`AiJsonOutput`) e entregue pronta em `result`.
- Finanças não tinha tradução em inglês (com `en` a página recebia a chave crua).
- Última análise restaurada podia ser a mais antiga quando duas do mesmo período caíam no
  mesmo segundo (created_at sem fração): desempate pelo id (UUIDv7).

### Adicionado
- Análise: período e data da geração, aviso quando a tela mudou de período, última
  análise do mesmo período restaurada ao abrir (sem nova chamada paga), prévia/skeleton
  com etapas, copiar e "Perguntar sobre isto" (leva a conclusão ao chat).
- Chat: balões com markdown seguro (`Support/safeMarkdown.js` — escapa todo HTML),
  "digitando", copiar/tentar de novo por mensagem, sugestão com um clique, textarea com
  Enter/Shift+Enter, aviso de troca de período e conversa preservada na aba
  (sessionStorage). Lado a lado em telas largas; polling para quando a tela sai.

---

## [2026-10-03] — Minimização e auditoria do envio; correção da Anthropic

### Alterado
- **Sem iniciais**: o contexto enviado à IA não tem mais `patient_initials` (só idade e
  sexo); nome/apelido digitados viram `<PATIENT_NAME_REDACTED>` (nome composto vira um
  marcador só). Regra (5) no preâmbulo: não deduzir nem reproduzir marcadores. O texto
  anterior do preâmbulo fica em `ai.security_preamble_previous` para runs antigos não
  receberem as regras em dobro.

### Adicionado
- **Auditoria do envio** (`ai_runs.dispatch_audit`, migration): SHA-256 das instruções
  enviadas, categorias de dados, chaves do contexto e nº de imagens — gravado antes da
  chamada, sem conteúdo (a trilha `audit_logs` só registra essa coluna).

### Corrigido
- **Anthropic**: o payload mandava `metadata.expects_json`, fora do contrato da API (só
  `metadata.user_id`) — chamadas com JSON podiam ser recusadas. O pedido de JSON já vai no
  texto (PromptComposer).

---

## [2026-10-03] — Tarja dos dados do paciente nas imagens de exame

### Adicionado
- **Tarja antes da IA** (`ExamImageDeidentifier` + `ExamImageLayouts`): os equipamentos
  exportam o relatório com nome, nascimento e ID do paciente no próprio pixel. O layout é
  reconhecido pela impressão digital de regiões fixas da tela (título, rótulos, logotipo)
  e os campos do paciente são pintados de preto só na cópia enviada. Layouts: Pentacam
  (PT; ficha à esquerda e Belin), Keratograph (Overview, 4-Maps), Pachycam, Tomey EM-3000.
- **Layout não reconhecido: a imagem não sai** (decisão de produto). Aviso nas
  verificações de segurança do resultado; sem nenhuma imagem, a análise falha antes do
  provedor, com o motivo para o médico, sem cobrança e sem nova tentativa
  (`AiImageNotDeidentifiedException`).
- Auditoria por imagem em `ai_run_patient_exam.image_deidentification` (migration).
- Comando `ai:exam-image-layout` para conferir amostras e cadastrar layouts novos.

### Corrigido
- `context.selected_exams` passa a listar só as imagens enviadas (antes, imagem
  ignorada — arquivo ausente ou acima do limite — deslocava "imagem N = exame N").

---

## [2026-10-03] — LGPD dos provedores de IA, Azure OpenAI e Maritaca

### Adicionado
- **Azure OpenAI** (API v1, header `api-key`, deployment como modelo; região declarada
  em `AI_AZURE_OPENAI_DATA_REGION`) e **Maritaca** (Sabiá; `-br-sp` processa 100% no
  Brasil; preço oficial em R$ convertido pela cotação da última recarga). Sem listagem
  de deployments no Azure: modelos cadastrados à mão.
- **Política LGPD por provedor** (`ProviderDataPolicy`): onde processa, base da
  transferência internacional e bloqueio para pacientes. Gemini API fora dos papéis do
  assistente (validação no painel, filtro em execução real e trava no orquestrador para
  provedor fixado sem `patient_data: false`). DeepSeek (dados na China) e OpenRouter
  (repassa a terceiros) avaliados e não incluídos.
- **Registro do mecanismo de transferência** (LGPD art. 33) por provedor
  (`PATCH/DELETE ai-providers/{provider}/transfer`, auditado): exigido antes de pôr num
  papel quem leva dado de paciente para fora do Brasil sem adequação; quem já estava em
  uso segue com aviso. Coluna LGPD, seção "Proteção de dados" no drawer e avisos na tela.
- Documento `docs/legal/ai-providers-lgpd.md` (suboperadores, fontes, checklist).

### Alterado
- Contexto clínico enviado à IA sem código do paciente/prontuário; nome e apelido do
  paciente digitados no pedido viram iniciais.
- Driver compatível recusa imagem em provedor sem visão (Maritaca) antes de enviar e
  exige endereço configurado (Azure).

---

## [2026-10-03] — Provedores de IA: lista pronta e catálogo sincronizado

### Adicionado
- **Novos provedores** (driver `OpenAiCompatibleProvider`, POST `/chat/completions`):
  Mistral, Groq e xAI (Grok). Chave só no `.env`
  (`<PROVEDOR>_API_KEY`); a tela mostra origem, variável e os 4 últimos caracteres.
- **Sincronização do catálogo de modelos/preços** (Manager → Provedores de IA →
  "Sincronizar agora" ou `ai:sync-model-catalog` diário com `AI_CATALOG_SYNC_ENABLED`):
  modelos pela API de cada provedor + preços do catálogo LiteLLM. Novos entram
  inativos; preço manual fica travado; variação > 10x vai para revisão; modelo não
  oferecido é marcado. Progresso em tempo real (Reverb), histórico e "o que mudou".
- Tela reorganizada no padrão do manager (Empresas/Medicamentos): números no topo,
  abas Provedores | Modelos e preços | Sincronização, ações em ícone + menu, detalhes
  em drawer (provedor: chave, modelo, endereço, como configurar, teste) e cadastro de
  preço em modal. Catálogo paginado, filtrado (provedor, status, origem, situação,
  busca) e ordenado no servidor; modelo de cada provedor salvo pelo drawer
  (`PATCH ai-providers/{provider}/model`).

### Corrigido
- **Raciocínio da OpenAI cobrado duas vezes**: `output_tokens` da OpenAI já inclui o
  raciocínio e o custo somava `reasoning_tokens` de novo. Agora a saída registrada é só
  o texto visível; raciocínio sem preço próprio é cobrado como saída.
- Snapshot datado da Anthropic (`claude-…-AAAAMMDD`) cai no preço do modelo-base.
- Sanitizador de erros reconhece chaves Groq (`gsk_`) e xAI (`xai-`); o driver
  compatível remove a chave exata ecoada pelo provedor.
- Cards de custo/consumo (Manager → Créditos IA) mostram provedores além dos três
  originais quando há movimento.

### Schema
- `ai_model_prices`: `source`, `price_locked`, `synced_at`, `unlisted_at` (linhas já
  editadas pelo painel entram travadas).
- Nova tabela `ai_catalog_syncs` (histórico das sincronizações).

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
