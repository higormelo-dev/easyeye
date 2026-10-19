# Briefing — Eye Images: gaps vs gerenciador de exames de referência (18/10/2026)

Origem: comparação (sessão separada) do admin de um gerenciador de exames de terceiros com o módulo Eye Images. Só UI/JS estático foi lido lá; nenhum dado de paciente. Tudo abaixo foi conferido no código atual de `desenv` (HEAD 81e5cfef) pelo orquestrador.

## Já existe (não refazer)

- IA (de-identificação + análise), ditado, rascunho técnico→médico com devolução, kits de exame, `exam_types` com chave canônica.
- Menu de contexto (`EyeImageContextMenu.vue`: laudo, comparar, compartilhar, baixar), `quality_rating` por exame, lateralidade/ativo editáveis, mesclar/dividir/desagrupar **exame** (`eye-images.exams.merge|split|ungroup`), montagem (`MontageService`), importação externa.
- Comparar já faz overlay real com opacidade (`EyeImageCompareModal.vue` modo `overlay|side`) — sem validação de lateralidade OD+OD / OE+OE.
- Calculadora de lente de contato existe no prontuário (`ContactLensCalculatorModal.vue`); IOL/LIO foi vetada pelo dono (fora de propósito).
- Trilha de auditoria: `Patient` e `PatientExam` usam `Auditable` → `AuditService` → `AuditLog` (`auditable_type/id`, old/new). Só exportação de compliance (`reports.compliance.audit`); nada por paciente na tela.
- `PatientDuplicateFinder::find(entityId, name, birthDate, ignorePersonId)` só avisa duplicata na restauração; não há merge de paciente.

## Gaps confirmados (candidatos ao lote)

| # | Prioridade | Gap | Âncora atual |
|---|---|---|---|
| 1 | Alta | Impressão por folha só `[1, 2, 4, 6]`; referência tem 1/2/4/6/8/9/12/15/16 | `resources/js/Support/printSheets.js:14` `SHEET_SIZES`; `gridFor()` assume `n/2` linhas quando pareado OD/OE |
| 2 | Alta | Merge de **paciente** (dedup cadastro) | não existe; `patient_id` aparece em 35 migrations / 73 colunas; `Patient` tem 14 relações; `People` separado de `Patient` |
| 3 | Média | Indicador visto/não visto por imagem/exame | sem coluna em `patient_exams` (tem `quality_rating`, `active`, `observation`) |
| 4 | Média | Marcação "urgente" na solicitação de laudo | não há enum de status de laudo; filtro `status` do `EyeImageFilters` é derivado (`laudado|pendente`) |
| 5 | Média | Filtro médico interno (responsável) separado de médico externo/solicitante | `app/DTOs/EyeImageFilters.php` só `doctorId`; laudo tem `requesting_doctor_name/email` |
| 6 | Média | Log de alteração do paciente visível na tela (somente leitura) | `AuditLog` já grava; falta endpoint read-only + painel |
| 7 | Baixa | "Parecer" rápido distinto do laudo | não existe; o próprio relatório marca "pode ser escopo demais" |
| 8 | Baixa | IOL embutido na tela de exames | vetado anteriormente pelo dono — fora |
| 9 | Baixa | Comparar com validação OD+OD / OE+OE | `EyeImageCompareModal.vue` não valida lateralidade |

## Restrições (as de sempre)

Isolamento por `entity_id`; auditoria preservada; sem polling HTTP novo (dono proibiu; Reverb existe); pt_BR/en em paridade; Pest sem paralelismo só em `easyeye_test`; Pint/ESLint só nos tocados; sem commit sem "commit autorizado"; nenhum dado de paciente em log/JSON de teste; LGPD: log de alteração por paciente só para perfis com acesso ao paciente e sem expor valores sensíveis sem necessidade.
