# Plano — Importação de pacientes: de-para de convênios e reprocessamento

**Data:** 2026-10-23 · **Autor:** Bussola (planejador) · **Status:** proposta; nenhum código alterado
**Referências:** `AGENTS.md`, `CLAUDE.md`, `docs/internal/engineering/domain-invariants.md`, `app/Services/PatientImportService.php`, `app/Http/Controllers/PatientImportsController.php`, `app/Jobs/ProcessPatientImportJob.php`, `resources/js/Pages/Panel/Patients/Import.vue`.
**Objetivo:** resolver em lote os convênios que não existem no catálogo, persistir o de-para por clínica e reprocessar somente as linhas que falharam por convênio, sem duplicar pacientes e sem piorar o custo atual da importação.

## 0. Diagnóstico que o plano assume

- O relatório real tem **1.604 linhas de erro**, quase todas `Convênio "X" não encontrado`, com **19 nomes únicos**. Exemplos conhecidos: `PAS 606` (606 linhas), `SULAMERICA 537` (537), `CORTESIA 179` (179), `ASPEB 78` (78), `POSTAL CORREIOS 44` (44), `CARTAO DEBITO 33` (33). O relatório deve ler os nomes/contagens do CSV de erros/planilha; **não hardcodar** os 19.
- `generatePreview()` percorre o arquivo inteiro para contar linhas, mas guarda só 5 amostras. Os convênios únicos podem ser coletados **na mesma passagem**.
- `doProcess()` já carrega o mapa de convênios **uma vez** (`loadCovenantsMap`) e resolve o plano sob demanda por convênio. A solução não pode introduzir consulta por linha.
- Não existe tabela de de-para de importação, nem colunas de linhas reprocessáveis, nem expiração/limpeza dos arquivos de importação.
- O CSV de erros (`imports/patients/{entity_id}/errors_{id}.csv`) contém PHI (nome, CPF, telefone, carteirinha), fica no disco privado e é baixado por rota autenticada, mas hoje **não expira**.
- Importações antigas não têm as colunas novas. Para o import real, o relatório deve ser reconstruído a partir do CSV de erros existente, sem exigir reimportação.

## 1. Decisões recomendadas

| # | Decisão | Justificativa |
|---|---|---|
| D1 | **Persistir o de-para por clínica** em `patient_import_covenant_mappings`, uma linha por `(entity_id, source_name_normalized)`, com `covenant_id` e `covenant_plan_id` opcional. | Reaproveita o de-para em importações futuras e permite mapear nomes diferentes para o mesmo convênio. Não criar de-para global: catálogo e operação são da clínica. |
| D2 | **Normalizar com a mesma regra da importação atual**: extrair `normalizeKey()` para `App\Support\ImportNameNormalizer::key()` e reutilizar em cabeçalhos, convênios, planos e chave do de-para. | Evita divergência entre “SulAmérica”, “SULAMERICA” e “Sul América”. A comparação fica case-insensitive, sem acento, com espaço/hífen tratados como `_`. |
| D3 | **Plano do de-para é opcional e prevalece sobre a coluna da planilha.** Se `covenant_plan_id` estiver preenchido, ele é aplicado a todas as linhas daquele convênio. Se estiver vazio, mantém a regra atual: resolve `plano`/`nome_plano`/registro ANS dentro do convênio; não encontrado/ambíguo gera aviso e o paciente entra sem plano. | Atende o caso em que a planilha só traz o nome do convênio, sem inventar plano. A UI deve deixar a precedência explícita. |
| D4 | **Reprocessar em um novo `PatientImport` filho**, com cópia do CSV original, `reprocess_of_id` e `reprocess_lines` contendo apenas as linhas que falharam por convênio. O import original fica imutável. | Preserva histórico, permite cancelar/retentar sem alterar o relatório original e evita “reprocessar tudo”. |
| D5 | **Idempotência por deduplicação existente + filtro de linhas.** O job só lê as linhas de `reprocess_lines`; CPF, nome+telefone e `import_code` continuam sendo as chaves de não duplicação. | O serviço já é idempotente para pacientes; o risco atual é reprocessar linhas já concluídas. O filtro elimina esse risco. |
| D6 | **Criar convênio na hora exige `settings.manage`.** Mapear para convênio/plano existente e importar como Particular não exigem. | Preserva o RBAC: quem só importa pacientes não ganha poder de criar catálogo. A criação é auditada e idempotente. |
| D7 | **Retenção LGPD de 30 dias** para o CSV original e o CSV de erros, com expiração explícita e limpeza agendada. O relatório de convênios não contém PHI. | O arquivo original também é dado de paciente e é necessário para reprocessar; 30 dias é o padrão inicial, configurável. |

### 1.1 O que fica fora de escopo

- Não altera prontuário, assinatura, locks de agenda, TISS, billing, recall, repasse médico ou contratos financeiros. O plano só resolve `covenant_id`/`covenant_plan_id` como o cadastro manual já faz.
- Não cria planos de convênio na hora; planos continuam em Configurações › Convênios › Planos. Criar convênio não cria plano.
- Não muda o layout do CSV de entrada nem o cabeçalho do CSV de erros.
- Não corrige a dívida F4 de `COUNT`/`fresh` por linha; apenas garante que o de-para **não acrescenta** consultas por linha.

## 2. Modelo de dados

### 2.1 Nova tabela `patient_import_covenant_mappings`

Migration: `database/migrations/2026_10_23_120000_create_patient_import_covenant_mappings_table.php`

| Coluna | Tipo | Regra |
|---|---|---|
| `id` | uuid PK | `HasUuids` |
| `entity_id` | uuid FK `entities` | `cascadeOnDelete`; sempre da sessão/import, nunca do request |
| `source_name` | string(255) | nome como apareceu no arquivo, com `trim`; usado só para exibição |
| `source_name_normalized` | string(255) | `ImportNameNormalizer::key($source_name)` |
| `covenant_id` | uuid FK `covenants` | `cascadeOnDelete` (convênios usam soft delete; o cascade só vale em hard delete) |
| `covenant_plan_id` | uuid FK `covenant_plans` nullable | `nullOnDelete`; plano deve pertencer ao convênio e ser escolhível |
| `created_by` / `updated_by` | uuid FK `users` nullable | `nullOnDelete`; `HasAuditColumns` |
| `timestamps` | timestamps | — |

Índices/constraints:

- `unique(['entity_id', 'source_name_normalized'])`.
- `index(['entity_id', 'covenant_id'])`.
- `index('covenant_plan_id')`.

Model: `app/Models/PatientImportCovenantMapping.php`

- Traits: `HasUuids`, `Auditable`, `HasAuditColumns`, `BelongsToEntity`.
- `$fillable`: `entity_id`, `source_name`, `source_name_normalized`, `covenant_id`, `covenant_plan_id`.
- Relações: `entity()`, `covenant()`, `plan()`.
- **Não** usar `SoftDeletes`: “remover de-para” apaga a linha; o `Auditable` registra a exclusão. O histórico do que foi aplicado fica no snapshot do import.

### 2.2 Novas colunas em `patient_imports`

Migration: `database/migrations/2026_10_23_121000_add_covenant_mapping_fields_to_patient_imports.php`

| Coluna | Tipo | Uso |
|---|---|---|
| `reprocess_of_id` | uuid FK `patient_imports` nullable | `nullOnDelete`; aponta para o import original |
| `reprocess_lines` | json nullable | lista de números de linha do arquivo original a processar (sem PHI) |
| `covenant_error_lines` | json nullable | linhas que falharam por convênio neste import; `[]` = processado sem erro de convênio; `null` = import antigo |
| `covenant_mapping_snapshot` | json nullable | de-para aplicado no confirm/reprocess; o processamento usa este snapshot (fallback para a tabela em imports antigos) |
| `files_expire_at` | timestamp nullable, index | expiração do CSV original e do CSV de erros |
| `files_purged_at` | timestamp nullable | quando a limpeza apagou os arquivos |

Índices adicionais:

- `index('reprocess_of_id')`.
- Índice único parcial para impedir dois reprocessamentos ativos do mesmo pai:
  ```sql
  CREATE UNIQUE INDEX patient_imports_one_active_reprocess
  ON patient_imports (reprocess_of_id)
  WHERE reprocess_of_id IS NOT NULL
    AND status IN ('pending', 'processing');
  ```
- Backfill de `files_expire_at` para registros existentes: `now() + 30 dias` (não usar `created_at`, para não apagar o relatório real antes de o usuário resolver). `files_purged_at` fica `null`.

Model `PatientImport`:

- `$fillable`: novas colunas.
- `casts`: `reprocess_lines`/`covenant_error_lines`/`covenant_mapping_snapshot` => `array`; `files_expire_at`/`files_purged_at` => `datetime`.
- Relações `reprocessOf(): BelongsTo` e `reprocesses(): HasMany`.
- Helpers `isReprocess(): bool` e `hasCovenantErrors(): bool`.
- `progressPayload()` e `serializeImport()` passam a expor `reprocess_of_id`, `is_reprocess`, `has_covenant_errors`, `files_expire_at` (ISO) e `files_expired` (bool). **Não** expor `reprocess_lines` nem `covenant_error_lines` no Inertia: são listas grandes e não são necessárias na tela.

## 3. Contratos HTTP

### 3.1 Rotas

Adicionar ao grupo `permission:patients.manage,admin,financial,doctor,secretary` em `routes/web.php`, junto das rotas de importação:

| Método | URI | Nome | Ação |
|---|---|---|---|
| GET | `patients/import/{patientImport}/covenants` | `panel.patients.import.covenants.show` | relatório de convênios do import |
| PUT | `patients/import/{patientImport}/covenants` | `panel.patients.import.covenants.update` | salvar de-para em lote |
| POST | `patients/import/{patientImport}/covenants/reprocess` | `panel.patients.import.covenants.reprocess` | criar import filho e enfileirar |
| GET | `covenants/search` | `panel.covenants.search` | busca de convênios ativos (próprios + globais) |

A rota de busca fica ao lado de `covenant-plans/search`; usa a mesma sessão `selected_entity_id` e o `EntityScope` (próprios + globais). As rotas de importação continuam no grupo de pacientes; a criação de convênio é autorizada **dentro** do endpoint, com `settings.manage`.

### 3.2 `GET /patients/import/{patientImport}/covenants`

Resposta `200`:

```json
{
  "data": {
    "import": {
      "id": "uuid",
      "original_name": "pacientes.csv",
      "status": "done",
      "is_reprocess": false,
      "reprocess_of_id": null,
      "files_expire_at": "2026-11-22T12:00:00-03:00",
      "files_expired": false,
      "report_available": true
    },
    "permissions": {
      "can_create_covenant": true,
      "can_reprocess": true
    },
    "totals": {
      "file_rows": 1604,
      "failed_rows": 1604,
      "failed_rows_exact": true,
      "unmatched_names": 19,
      "mapped_names": 0
    },
    "items": [
      {
        "source_name": "PAS 606",
        "source_name_normalized": "pas_606",
        "rows": 606,
        "failed_rows": 606,
        "status": "unmatched",
        "covenant": null,
        "plan": null,
        "mapping": null,
        "retryable": true
      }
    ]
  }
}
```

Status de item:

Ordenação: pendentes (`unmatched`, `inactive`, `invalid_mapping`) primeiro, depois `rows` desc, depois nome. Isso põe os 19 casos reais no topo.\n\n- `found`: nome do arquivo casa com convênio ativo; `covenant` preenchido.
- `unmatched`: não existe convênio com aquele nome normalizado.
- `inactive`: casa com convênio inativo; `covenant.active = false`.
- `mapped`: existe de-para para convênio ativo; `mapping` preenchido.
- `particular`: linha sem convênio ou de-para para o Particular; plano/carteirinha serão ignorados.
- `invalid_mapping`: o de-para aponta para convênio/plano removido, inativo ou de outra clínica.

`rows` é a contagem de linhas do arquivo com aquele nome; `failed_rows` é a contagem exata de linhas que falharam por convênio (para import novo) ou a contagem reconstruída do CSV de erros (import antigo). Na prévia antes do processamento, `failed_rows` é estimado como `rows` para status não resolvidos e `0` para resolvidos; a UI deve rotular como “linhas no arquivo” e “com erro de convênio” sem prometer exatidão antes do job. `report_available = false` quando não há nem `preview.covenant_summary`, nem CSV de erros, nem original; nesse caso `items` pode vir vazio. `can_reprocess = false` quando falta o original, as linhas de erro ou o import não está `done|failed`; a UI desabilita a ação mesmo que o relatório esteja disponível. `failed_rows_exact = false` quando a contagem por nome é estimada (CSV de erros indisponível); a UI mostra “aprox.” nesse caso.

O endpoint **não retorna** nome, CPF, telefone, carteirinha ou qualquer dado de paciente. Retorna apenas nomes de convênio, contagens e alvos do catálogo.

### 3.3 `PUT /patients/import/{patientImport}/covenants`

Request:

```json
{
  "mappings": [
    { "source_name": "PAS 606", "action": "map", "covenant_id": "uuid", "covenant_plan_id": null },
    { "source_name": "SULAMERICA 537", "action": "create" },
    { "source_name": "CORTESIA 179", "action": "particular" },
    { "source_name": "OUTRO", "action": "clear" }
  ]
}
```

Regras:

- `mappings`: array obrigatório, 1..1000; `source_name` obrigatório, string, `max:255`, `distinct`, e `ImportNameNormalizer::key($source_name) !== ''`; `action` em `map|create|particular|clear`.
- O `source_name` precisa existir no relatório atual do import (comparado por `source_name_normalized`). Isso impede criar de-para arbitrário para um nome que não está no arquivo.
- `map`: `covenant_id` obrigatório, uuid, convênio ativo e visível (próprio ou global); `covenant_plan_id` opcional, uuid, `CovenantPlan::selectableFor($covenantId, $entityId)`.
- `create`: exige `settings.manage`; cria/reutiliza convênio ativo com o nome do arquivo; não aceita `covenant_id`/`covenant_plan_id`.
- `particular`: grava o de-para para o convênio Particular resolvido da clínica (o mesmo usado quando a coluna vem vazia); não aceita plano.
- `clear`: apaga o de-para existente; não aceita convênio/plano.
- Duplicidade de `source_name_normalized` no request => `422`.
- Tudo em uma transação: se uma linha falhar, nada é gravado.
- Resposta `200`: o mesmo payload do `GET`, mais:
  ```json
  "meta": { "saved": 3, "created_covenants": 1, "reused_covenants": 0, "cleared": 1 }
  ```
- Erros de validação por linha: `mappings.{index}.covenant_id`, `mappings.{index}.covenant_plan_id`, `mappings.{index}.source_name`, `mappings.{index}.action`.

Criação idempotente de convênio:

- Normalizar o nome e procurar nesta ordem: próprio ativo → global ativo → próprio inativo (reativar) → próprio soft-deleted (restaurar) → criar. A ordem ativo próprio/global é a mesma do `loadCovenantsMap` (o próprio vence o global; o ativo vence o inativo).\n- Se existir um convênio próprio inativo com o mesmo nome e nenhum ativo, reativar e reutilizar (auditado).\n- Se existir um convênio próprio soft-deleted e nenhum ativo, restaurar e reutilizar.
- Se não existir, criar `Covenant` com `entity_id` da clínica, `name` (o model uppercasa), `active = true`, `table = false`, `source = manual`, cor determinística da paleta da clínica (verificar colisão; sem unique no banco, mas sem repetir cor da clínica).
- A criação não cria plano. A UI avisa que planos devem ser cadastrados em Configurações.
- O convênio criado e o de-para **permanecem** se a importação for cancelada; isso é configuração da clínica, não efeito transitório. A UI deve informar.

### 3.4 `POST /patients/import/{patientImport}/covenants/reprocess`

Sem body obrigatório (CSRF normal). Resposta `202`:

```json
{
  "data": {
    "import_id": "uuid",
    "status": "pending",
    "total_rows": 606,
    "channel": "imports.patients.uuid"
  }
}
```

Pré-condições e erros:

- `404`: import de outra clínica.
- `409`: já existe import `pending`/`processing` na clínica, ou já existe reprocessamento ativo para este pai.
- `422`: import não está `done`/`failed`; não há linhas de convênio para reprocessar; arquivo original ausente ou expirado; relatório indisponível.
- O endpoint usa as linhas de `covenant_error_lines`; se for import antigo (`null`), reconstrói via `PatientImportCovenantReportService`.
- Cria o filho com cópia do arquivo (`Storage::copy`), `reprocess_of_id`, `reprocess_lines`, `total_rows = count(linhas)`, `confirmed_at = now()`, `files_expire_at = now() + retenção`, `preview` mínimo com `reprocess` e `covenant_summary`, e `covenant_mapping_snapshot` com o de-para atual dos nomes presentes.
- Despacha `ProcessPatientImportJob` **depois** do commit. Se o dispatch falhar, marca o filho como `failed` com motivo genérico e mantém o arquivo para nova tentativa.
- O import pai não é alterado. A UI mostra “Reprocessamento de #id”.

### 3.5 `GET /covenants/search?q=`

- `q`: opcional, string, `max:100`; sem termo, primeiros 30 por nome.
- Retorna apenas convênios ativos, não deletados, próprios da clínica + globais.
- Busca `whereLikeUnaccent('covenants.name', addcslashes($term, '\\%_'))`; se o termo tiver 3+ dígitos, também `code`/`ans_registry`.
- Limite 30; próprios primeiro, depois nome.
- Resposta:
  ```json
  { "data": [ { "id": "uuid", "label": "SULAMERICA", "sub_label": "CV-0000000123", "name": "SULAMERICA", "code": "CV-0000000123", "is_own": true, "active": true } ] }
  ```
- Reutilizar `SearchSelect` com `remoteSearchUrl` e `__Q__`; o seed inicial (sem `q`) evita lista vazia.

## 4. Backend: fluxo e arquivos

### 4.1 Normalizador compartilhado

Criar `app/Support/ImportNameNormalizer.php`:

```php
final class ImportNameNormalizer
{
    public static function key(string $value): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');
        $value = str_replace([' ', '-'], '_', $value);
        $value = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value;

        return preg_replace('/[^a-z0-9_]/', '', $value) ?? $value;
    }
}
```

Substituir o corpo de `PatientImportService::normalizeKey()` por chamada ao normalizador (ou remover o método privado e usar o support). **Não alterar** a semântica: `headerFields`, `resolvePlanId` e `loadCovenantsMap` devem continuar com o mesmo resultado. Teste de regressão com “SulAmérica”/“SULAMERICA” e com cabeçalhos `convênio`/`convenio`.

**Ajuste (24/10, decisão do orquestrador):** `key()` mantém `_` e continua só em cabeçalhos e planos; nomes de **convênio** (catálogo/`loadCovenantsMap`, `source_name_normalized` do de-para e do relatório) usam `ImportNameNormalizer::covenantKey()` = `key()` sem `_` (“SulAmérica”, “SULAMERICA” e “Sul América” → `sulamerica`; “PAS 606” → `pas606`, não `pas_606` como no exemplo da seção 3.2). O casamento com o catálogo fica mais permissivo de propósito.

### 4.2 Resolvedor de convênios do import

Criar `app/Services/Covenants/PatientImportCovenantResolver.php` para centralizar a leitura do catálogo e do de-para e evitar que prévia, processamento e relatório divirjam.

Responsabilidades:

- `load(string $entityId, ?array $snapshot = null): void` — uma vez por import. Se `$snapshot` vier preenchido, usa o de-para congelado do import; senão lê a tabela:
  - Carrega convênios visíveis (próprios + globais), não deletados, com `id`, `name`, `active`, `code`, ordenados como `loadCovenantsMap` (inativos primeiro, globais antes dos próprios; o último vence).
  - Monta `byName` (chave normalizada => covenant), `byId`, `inactiveIds` e `particularId` (o `PARTICULAR` resolvido pelo mapa, com prioridade da clínica).
  - Carrega `patient_import_covenant_mappings` da `entity_id` com `covenant` e `plan` (uma query).
  - Carrega os planos mapeados em uma query (`CovenantPlan::selectableFor` por convênio não é adequado para lote; validar em memória que `plan.covenant_id === mapping.covenant_id`, `active`, não deletado, e `entity_id` nulo ou da clínica).
- `forSource(string $sourceName): array` — status para prévia/relatório:
  - `source_name`, `source_name_normalized`, `status`, `covenant`, `plan`, `mapping`, `retryable`.
- `forRow(?string $sourceName, ?string &$warning): array` — resolução para processamento:
  - Retorna `covenant_id`, `mapped_plan_id` (nullable), `is_particular`.
  - Se o de-para existir e o convênio/plano alvo estiver indisponível, lança `CovenantResolutionException` com motivo `mapping_covenant_unavailable`/`mapping_plan_unavailable`. Exceção: o alvo Particular é aceito como hoje (linha vazia resolve para `particularId` sem checar `active`); não mudar essa regra.
  - Se não houver de-para, replica a regra atual: vazio => Particular; nome encontrado => ativo/inativo; não encontrado => `CovenantResolutionException` `not_found`.
- `snapshot(array $sourceNames): array` — somente os de-para dos nomes presentes no import, no formato `{ normalized: { source_name, covenant_id, covenant_plan_id } }`.

`CovenantResolutionException extends RuntimeException` (em `app/Services/Covenants/`) deve carregar:

```php
public function __construct(
    string $message,
    public readonly string $sourceName,
    public readonly string $sourceNameNormalized,
    public readonly string $reason, // not_found|inactive|mapping_covenant_unavailable|mapping_plan_unavailable|particular_missing
    public readonly ?string $covenantId = null,
    public readonly ?string $planId = null,
) {}
```

Não mover a resolução de plano da planilha para o resolvedor: `PatientImportService::resolvePlanId()` continua dona do parsing de `plano`/ANS e do aviso. O resolvedor só devolve o plano **mapeado**; se ele for `null`, o serviço chama `resolvePlanId` como hoje.

### 4.3 Prévia

Em `PatientImportService::generatePreview()`:

- Manter a leitura única do arquivo.
- Antes do loop, localizar os índices de `_covenant` e `_plan` no `$indexFieldMap`.
- No loop, além de `totalRows` e das 5 amostras, acumular:
  - `covenantCounts[normalized] = ['source_name' => primeiro nome trimado, 'rows' => n]`.
  - `planCounts` não é necessário para o contrato; o plano da planilha é resolvido no processamento.
- Depois do loop, chamar o resolvedor uma vez (`load($import->entity_id)`) e montar `covenant_summary` com `forSource` para cada chave.
- Incluir no retorno:
  ```php
  'covenant_summary' => [
      'items' => [...],
      'totals' => ['file_rows' => $totalRows, 'unmatched_names' => n, 'mapped_names' => n],
      'truncated' => false,
  ],
  ```
- Incluir um item especial para linhas sem convênio (`source_name: ''`, `status: 'particular'`, `rows: n`), para a UI mostrar “Sem convênio — entra como Particular”.
- Limite de segurança: no máximo 500 itens no JSON de prévia; se exceder, `truncated = true` e a UI avisa que o relatório completo fica no CSV de erros. O caso real (19) não é afetado.
- Se o resolvedor falhar por catálogo ausente, a prévia não deve quebrar: retornar os itens como `unmatched` e um aviso no `preview` (o processamento continua exigindo Particular).

### 4.4 Processamento

Em `PatientImportService`:

- Injetar `PatientImportCovenantResolver` no construtor.
- Em `doProcess()`:
  - Ler `$reprocessLines = $import->reprocess_lines` (array de ints) uma vez.
  - Se `reprocessLines` não for vazio:
    - **Não** fazer a passagem de contagem completa; `$totalRows = count($reprocessLines)`.
    - `$retrySet = array_fill_keys(array_map('intval', $reprocessLines), true)`.
  - Se for import normal, manter a contagem atual (uma passagem) e `$retrySet = null`.
  - Chamar `$resolver->load($import->entity_id, $import->covenant_mapping_snapshot)` uma vez (o resolvedor guarda o contexto internamente); manter `$plansCache` para planos da planilha. O snapshot congela o de-para do confirm/reprocess; imports antigos sem snapshot usam a tabela.
  - No loop, controlar `$sourceLine` (linha física do arquivo, começando em 2 após o cabeçalho) separado de `$processed`:
    - Se `$retrySet !== null` e `!isset($retrySet[$sourceLine])`, `continue` **sem** incrementar `$processed`.
    - `$rowNum = $sourceLine` (para o CSV de erros apontar para a linha original).
    - `$processed++` só para linhas efetivamente processadas.
  - Passar o resolvedor para `importRow`/`covenantData`; não passar arrays gigantes por valor sem necessidade. Preferir um objeto de contexto ou propriedades carregadas no serviço.
  - Em `covenantData()`:
    - `$resolved = $resolver->forRow($data['_covenant'] ?? null, $warning)`.
    - `covenant_id = $resolved['covenant_id']`.
    - `isParticular = $resolved['is_particular']`.
    - `covenant_plan_id = $isParticular ? null : ($resolved['mapped_plan_id'] ?? $this->resolvePlanId(...))`.
    - `card_number = $isParticular ? null : ($data['_card_number'] ?? null)`.
  - `CovenantResolutionException` deve ser capturada **antes** do catch genérico:
    - `$errors[] = errorRow($rowNum, $e->getMessage(), $data)`.
    - `$errorCount++`.
    - `$covenantErrorLines[] = $rowNum`.
    - `$covenantFailureCounts[$e->sourceNameNormalized]++` (opcional, para o relatório).
  - Em todos os pontos de saída (cancelamento, limite do plano, fim normal), persistir:
    - `covenant_error_lines` = lista (mesmo vazia).
    - `preview.covenant_summary` = relatório decorado com os status atuais e `failed_rows` exatos.
    - `covenant_mapping_snapshot` = se ainda não existir, `$resolver->snapshot(array_keys($covenantCounts))`; se já existir, preservar o snapshot usado.
    - `files_expire_at` = `now() + config('patient_imports.retention_days', 30)` (renova ao terminar).
  - `safeFailureReason()`: aceitar `CovenantResolutionException` como erro de negócio (retornar a mensagem) além do `RuntimeException` exato. Sem essa mudança, a mensagem amigável viraria “falha genérica” e o relatório perderia o motivo.
  - Atualizar o comentário de `ProcessPatientImportJob` (`tries = 1`): o reprocessamento não é “reprocessar o pai”, e sim um filho com `reprocess_lines`; a justificativa de não retentar continua válida para o job em si.
- O `total_rows` do import filho é a contagem de linhas filtradas; o progresso mostra “606 de 606”, não “606 de 20.000”.
- O limite do plano continua sendo verificado por linha; se o limite já foi atingido, o reprocessamento aborta com `abort_reason` e sem gravar novos pacientes. Isso é o comportamento correto.
- Deduplicação existente: CPF só dentro da `entity_id`; CPF de outra clínica cria cadastro novo local (não consulta nem altera a outra clínica). Não mudar essa regra no reprocessamento.
- Em `PatientImportsController::confirm`, antes de despachar o job, se `covenant_mapping_snapshot` for nulo, gravar o snapshot dos nomes completos do arquivo (`ReportService::sourceNames`, não só o preview truncado); se falhar, não confirmar. Assim o job usa o de-para do momento do confirm, mesmo que alguém altere o de-para enquanto ele está na fila.

### 4.5 Relatório e compatibilidade com imports antigos

Criar `app/Services/Covenants/PatientImportCovenantReportService.php`:

- `forImport(PatientImport $import): array` — prioridade:
  1. `covenant_error_lines` + `preview.covenant_summary` (import novo).
  2. CSV de erros (`errors_file_path`) — parser streaming, sem carregar o arquivo inteiro.
  3. CSV original — varredura streaming para contar nomes e linhas.
- O parser do CSV de erros:
  - Detecta BOM e separador `;`.
  - Localiza `_linha`, `_erro`, `_covenant` no cabeçalho.
  - Considera erro de convênio quando a mensagem contém `convênio`/`health plan` **e** (`não encontrado`/`not found` ou `inativo`/`inactive`), sem confundir com “Plano ... não encontrado”. Isso é só para imports antigos; imports novos usam a exceção tipada.
  - Acumula `source_name` e `line_number`; ignora linhas sem `_covenant`.
  - Se o CSV de erros não existir/expirou, retorna `available: false` e a UI mostra “relatório indisponível; importe novamente”.
- `sourceNames(PatientImport $import): array` — todos os nomes únicos do arquivo (preview/errors/original), para validar o `PUT` e montar o snapshot; não usa o payload truncado.
- `retryLineNumbers(PatientImport $import): array` — retorna as linhas de convênio; para import novo lê a coluna; para antigo usa o parser. Se não houver, `[]`.
- `failed_rows` por nome: contar no CSV de erros (`_linha`/`_covenant`) quando existir; se não existir, usar a estimativa do preview e marcar `exact = false` no total. Para o caso real, o CSV de erros existe e a contagem é exata.
- Se `preview.covenant_summary.truncated` for `true`, completar os nomes faltantes com uma passagem streaming pelo CSV original (ou pelo CSV de erros), sem devolver PHI.
- O serviço não loga conteúdo de linha. Em falha de parsing, loga apenas `import_id` e classe da exceção.
- O relatório recalcula o status de cada nome com o de-para **atual** (tabela), porque o objetivo é resolver/reprocessar; o `covenant_mapping_snapshot` fica no import apenas como auditoria do que o job usou.
- Para import antigo, o CSV de erros é a única fonte das linhas reprocessáveis. Se ele não existir/expirou, o original ainda pode listar nomes para o de-para, mas `retryLineNumbers()` retorna vazio e o `POST` responde 422; a UI mostra “relatório indisponível; importe novamente”.\n- Para o import real de 1.604 erros, o parser deve listar os 19 nomes e as contagens; a implementação deve validar com um fixture anonimizado que reproduza o cabeçalho e os nomes.

### 4.6 Persistência do de-para

Criar `app/Services/Covenants/PatientImportCovenantMappingService.php`:

- `save(PatientImport $import, array $mappings, User $user): array`.
- Validar contra o relatório **completo** (`ReportService::sourceNames($import)`), não contra o `preview` truncado; montar `allowed` por `source_name_normalized`.
- Carregar convênios visíveis ativos e planos necessários em lote (`whereIn`), não por linha.
- Para `create`, carregar candidatos visíveis (próprios + globais) e indexar por `ImportNameNormalizer::key(name)`; criar/reutilizar/reativar/restaurar conforme D6/3.3.
- Para `map`, validar convênio ativo e plano escolhível; para `particular`, resolver o `particularId` do resolvedor; para `clear`, apagar.
- Usar `updateOrCreate` por `(entity_id, source_name_normalized)` dentro da transação. O volume típico é 19; o limite de 1.000 protege o endpoint.
- Atualizar `preview.covenant_summary` do import com o relatório decorado após salvar, para a prévia refletir o de-para sem reload.
- Se `confirmed_at` ainda for nulo, atualizar também `covenant_mapping_snapshot`; se o import já foi confirmado, **não** alterar o snapshot em execução (o de-para novo vale para importações futuras).
- Auditar criação/reativação/restauração de convênio e alterações de de-para (traits já existentes).
- Retornar o mesmo payload do `GET` + `meta`.

### 4.7 Reprocessamento

Criar `app/Services/Covenants/PatientImportReprocessService.php` (ou método no controller, se preferir menos arquivos; o plano recomenda serviço para testar a transação):

- `reprocess(PatientImport $parent, User $user): PatientImport`.
- Validar entidade, status `done|failed`, `files_expire_at`/`files_purged_at`, existência do arquivo.
- Obter linhas via `ReportService::retryLineNumbers($parent)`.
- Transação:
  - `lockForUpdate` no pai.
  - Verificar import ativo na clínica e reprocessamento ativo do pai.
  - Copiar o arquivo para um novo path com UUID.
  - Criar o filho com os campos da seção 2.2 e `preview` mínimo.
  - Em caso de falha, apagar a cópia.
- Dispatch após commit. Se falhar, marcar `failed` e manter o arquivo.
- O índice único parcial é a última barreira contra duas requests concorrentes; o serviço traduz a violação para `409`.

### 4.8 Retenção e download

Criar `config/patient_imports.php`:

```php
return [
    'retention_days' => (int) env('PATIENT_IMPORT_RETENTION_DAYS', 30),
    'max_covenant_summary_items' => 500,
];
```

Definir `files_expire_at` já no upload (`PatientImportsController::store`) e no reprocessamento, e renovar em `saveErrorsFile()` e no fim do processamento. Assim um import pendente/parado também expira; a renovação ao terminar dá o prazo cheio para o usuário baixar o relatório.

Criar `app/Console/Commands/PrunePatientImportFilesCommand.php`:

- Assinatura `imports:prune-patient-files {--days=}`; padrão `config('patient_imports.retention_days', 30)`.
- Para `done|failed|cancelled` com `files_expire_at <= now()` e `files_purged_at` nulo, em chunks: apagar `file_path` e `errors_file_path`, atualizar os caminhos para `null` e `files_purged_at = now()`; manter a linha de `patient_imports` (histórico).
- Para `pending` com `confirmed_at` nulo e `files_expire_at <= now()`: apagar arquivos e o registro (mesmo efeito do cancel), pois não há job. Nunca tocar em `processing`.
- Logar apenas contagem agregada e `import_id`; nunca nome/CPF/telefone.
- Agendar em `routes/console.php` diariamente (ex.: `03:50`), `withoutOverlapping()`, `onOneServer()`.

Ajustar `PatientImportsController::errors()`:

- `abort_if` de entidade (404) e de caminho (404) como hoje.
- Se `files_expire_at` passou ou `files_purged_at` preenchido: `back()->with('error', __('imports.errors_file_expired'))` (sem 500).
- Se o arquivo sumiu do disco: manter o aviso atual.
- Download com `Content-Type: text/csv; charset=UTF-8`, `Cache-Control: private, no-store, max-age=0`, `X-Content-Type-Options: nosniff`.

## 5. Frontend

### 5.1 Componente novo

Criar `resources/js/Components/Panel/PatientImportCovenantMapping.vue`:

- Props: `importId`, `items`, `totals`, `permissions`, `urls` (show/update/reprocess/covenantSearch/covenantPlanSearch), `mode` (`preview|reprocess`), `t` (objeto de traduções), `busy`.
- Estado local: `rows` (cópia dos itens), `selected` (Set de normalizados), `filter` (`pending|all|found`), `draft` por linha, `saving`, `errors`, `savedMessage`.
- Ações:
  - Filtro “Pendentes (N)”, “Todos (M)”, “Encontrados (K)”.
  - Busca textual local por nome do convênio.
  - Checkbox por linha + “Selecionar todos os pendentes” (indeterminate quando parcial).
  - Barra de ações em lote: ação (`map|create|particular|clear`), `SearchSelect` de convênio (quando `map`), `SearchSelect` de plano (opcional, quando `map`), botão “Aplicar aos selecionados”.
  - Editor inline por linha (expandir): status, contagem, convênio atual, ação, selects, plano, botão “Aplicar”.
  - “Mapear todos os não encontrados para Particular” com confirmação.
  - “Salvar mapeamentos” e, no modo `reprocess`, “Salvar e reprocessar”.
- `SearchSelect`:
  - Convênios: seed inicial via `GET covenants/search` (sem `q`) e busca remota com `?q=__Q__`; `remoteMinChars=2`.
  - Planos: seed ao escolher convênio (`GET covenant-plans/search?covenant_id=...`) e busca remota com `?covenant_id=...&q=__Q__`; desabilitado sem convênio.
  - Mostrar `label` e `sub_label` (código do convênio; características do plano). Incluir o alvo atual como opção fixada para não “sumir” se não estiver entre os 30 primeiros.
  - Se o alvo atual estiver inativo/removido, mostrar como texto “Indisponível” e exigir novo alvo; não permitir salvar o inválido.
- Status:
  - `found` verde “Encontrado”; `unmatched` vermelho “Não encontrado”; `inactive` laranja “Inativo”; `mapped` azul “Mapeado”; `particular` cinza “Particular”; `invalid_mapping` vermelho “De-para inválido”.
  - Texto sempre visível, não só cor.
- Contagens: `rows` como “X linhas”; se `failed_rows` diferente, “Y com erro de convênio”.
- Plano:
  - Hint: “Opcional. Se preenchido, este plano vale para todas as linhas deste convênio e prevalece sobre a coluna ‘plano’ da planilha. Se vazio, a planilha decide; plano não encontrado gera aviso e o paciente entra sem plano.”
  - Particular: esconder plano e informar que carteirinha/plano serão ignorados.
- Acessibilidade:
  - `<caption class="visually-hidden">`, `scope="col"`, `aria-label` nos checkboxes com o nome do convênio.
  - `aria-live="polite"` para “mapeamentos salvos”, “reprocessamento iniciado” e erros.
  - `aria-describedby` ligando selects a hints/erros; foco no primeiro erro após 422.
  - Botões com texto ou `aria-label`; não usar só ícone.
  - Tabela responsiva; em telas pequenas, layout em cards (mesmos dados/ações) para não exigir scroll horizontal.
  - Não usar modal para a lista principal; a seção fica na própria página, evitando armadilha de foco.
- Estado vazio: “Todos os convênios da planilha foram encontrados.” com ícone de check.
- Aviso de expiração: se `files_expired`, desabilitar “Salvar e reprocessar” e mostrar “O arquivo desta importação expirou; envie novamente para reprocessar.”

### 5.2 Integração em `Import.vue`

- Adicionar props `can_create_covenant`, `urls.covenant_search`, `urls.covenant_plan_search`.
- Na prévia (`previewImport`), renderizar o componente após as amostras, com `mode="preview"` e `items = previewImport.preview.covenant_summary.items`.
- No histórico, adicionar botão “Resolver convênios” quando `item.has_covenant_errors` ou `item.error_rows > 0` (imports antigos). Ao clicar:
  - `GET item.urls.covenants`; abrir a seção de resolução logo acima do histórico com o componente em `mode="reprocess"`.
  - Fechar/cancelar apenas fecha a seção; não apaga de-para.
- Adicionar badge “Reprocessamento” quando `item.is_reprocess`, com link textual para o pai se disponível.
- Exibir `files_expire_at` no histórico (“Relatório disponível até dd/mm/aaaa”) e “expirado” quando aplicável.
- Confirmar importação:
  - O componente expõe `save()` via `defineExpose`; se houver alterações pendentes, `Import.vue` chama `mappingRef.save()` antes do `POST confirm`; se o save falhar, não confirmar.
  - Mostrar no diálogo de confirmação: “X convênios ainda não encontrados; as linhas deles falharão e poderão ser reprocessadas depois.” (não bloquear; o fluxo de reprocessamento existe).
  - Checar `response.ok` no `fetch` atual (hoje o retorno é ignorado); em erro, mostrar flash/alert.
- Reprocessar:
  - “Salvar e reprocessar” salva o de-para e chama `POST .../reprocess`; em `202`, `router.reload({ only: ['imports','pending_import','preview_id'] })`.
  - Em `409/422`, mostrar mensagem do backend e manter a seção aberta.
- Atualizar o comentário desatualizado do topo do arquivo (“Polling 2s”) para “WebSocket/Reverb”.

### 5.3 Traduções

Adicionar em `lang/pt_BR/imports.php` e `lang/en/imports.php`, sob `patients.covenant_mapping`:

- `title`, `subtitle`, `status_found`, `status_unmatched`, `status_inactive`, `status_mapped`, `status_particular`, `status_invalid_mapping`.
- `rows`, `failed_rows`, `target_covenant`, `target_plan`, `plan_hint`, `plan_override_hint`, `particular_hint`.
- `action_map`, `action_create`, `action_particular`, `action_clear`, `bulk_apply`, `select_pending`, `save`, `save_and_reprocess`, `reprocess`, `reprocess_confirm`.
- `no_pending`, `expired`, `file_missing`, `report_unavailable`, `mapping_saved`, `reprocess_started`, `create_requires_settings`, `created_covenant`, `reused_covenant`, `clear_confirm`.
- Erros: `target_unavailable`, `plan_unavailable`, `no_retry_lines`, `reprocess_running`, `source_not_in_file`, `invalid_action`.
- `imports.errors_file_expired` no nível raiz.

## 6. LGPD e segurança

- O relatório de convênios (`GET`/`PUT`/`POST`) **não** contém PHI: só nome de convênio, contagens e IDs/nomes de catálogo.
- O CSV de erros continua no disco privado (`storage/app/private`), baixado por rota autenticada e escopada por `entity_id`; outra clínica recebe 404.
- Download com `Cache-Control: private, no-store` e `X-Content-Type-Options: nosniff`; não gerar URL pública.
- Expiração: 30 dias padrão, configurável; limpeza diária apaga original + erros, mantém o registro e registra `files_purged_at`.
- Backfill de imports existentes com 30 dias de prazo a partir do deploy, para não apagar o relatório real antes da resolução.
- Nenhum log de linha: não logar `full_name`, CPF, telefone, carteirinha, e-mail, endereço ou o conteúdo do CSV. Logs de falha usam `import_id`, classe da exceção, SQLSTATE e localização, como hoje.
- `CovenantResolutionException` é erro de negócio: não logar; a mensagem vai para o CSV de erros, que é privado.
- Auditoria: criação/reativação/restauração de convênio, alterações de de-para e criação do import filho ficam nos `audit_logs` existentes. O snapshot do de-para no import preserva o que foi aplicado.
- Não enviar dados de paciente para serviços externos; nenhuma dependência nova.

## 7. Desempenho

- **Prévia:** uma passagem pelo CSV; a coleta de convênios usa arrays em memória. O resolvedor faz no máximo 3 queries por prévia (convênios, mappings, planos mapeados). Não consultar por linha.
- **Processamento:** `resolver->load()` uma vez por import; `loadCovenantsMap` deixa de ser a única fonte e passa a ser parte do resolvedor. `plansCache` continua por convênio. Nenhum `Covenant::where`/`CovenantPlan::where` dentro de `importRow` ou `covenantData`.
- **Reprocessamento:** não faz a passagem de contagem completa; `total_rows = count(reprocess_lines)`. A leitura do CSV continua streaming e só processa as linhas do filtro.
- **Relatório:** para import novo, usa `covenant_error_lines` e `preview.covenant_summary`; para antigo, streaming do CSV de erros. Não carrega o CSV inteiro em memória.
- **F4:** o `COUNT`/`fresh` por linha existente **não é alterado** por este plano. O de-para acrescenta custo constante por import, não por linha. Uma otimização de F4 (cache de total/status a cada 25 linhas) pode ser feita em tarefa separada, com teste de concorrência/cancelamento.
- **Payload:** `preview.covenant_summary` limitado a 500 itens; `reprocess_lines`/`covenant_error_lines` não vão para o Inertia. O relatório completo é buscado sob demanda.

## 8. Testes

### 8.1 Pest — backend

Criar `tests/Feature/Patients/PatientImportCovenantMappingTest.php`:

1. Prévia lista convênios únicos com contagem e status `found|unmatched|inactive`, incluindo item “Sem convênio” como Particular.
2. Normalização: “SulAmérica”, “SULAMERICA” e “Sul América” compartilham o mesmo item/de-para.
3. `PUT` mapeia nome do arquivo para convênio existente + plano; resposta reflete `mapped`; o de-para é reutilizado em uma segunda prévia/import.
4. `PUT action=create`: cria convênio da clínica, ativo, nome uppercase, cor única; segunda chamada reutiliza (não duplica); sem `settings.manage` retorna 403; admin cria.
5. `PUT action=particular`: linhas importam como Particular, sem plano/carteirinha.
6. `PUT action=clear`: remove o de-para e a prévia volta ao status anterior.
7. Validação: plano de outro convênio, plano de outra clínica, convênio de outra clínica, convênio inativo, `source_name` fora do arquivo, ação inválida => 422; nada é gravado.
8. Isolamento: clínica B não vê o de-para da A; `GET`/`PUT` do import da A retornam 404 para B; busca de convênios não retorna convênio próprio da A.
9. `GET /covenants/search`: retorna próprios + globais ativos, ignora inativos/outra clínica, busca sem acento/caixa, limite 30.

Criar `tests/Feature/Patients/PatientImportCovenantReprocessTest.php`:

1. Arquivo misto: linha 1 convênio válido importa; linha 2 convênio desconhecido falha. Mapear e reprocessar: só a linha 2 é processada; total de pacientes = 2; linha 1 não é duplicada.
2. Duplicidade por CPF: duas linhas falham por convênio com o mesmo CPF; após mapear, uma cria e a outra é ignorada por CPF; total = 1.
3. Duplicidade por `import_code`: duas linhas com o mesmo código; uma importa e a outra falha; após mapear/reprocessar, não cria duplicata e mantém o código no paciente correto.
4. Idempotência: rodar o processamento do filho duas vezes (ou reprocessar de novo) não cria pacientes duplicados.
5. Limite do plano: limite já atingido; reprocessar aborta com `abort_reason`, sem novo paciente.
6. Filho: `reprocess_of_id`, `reprocess_lines`, `total_rows` = linhas filtradas, arquivo copiado, pai inalterado; `preview` mínimo.
7. Concorrência: segundo `POST` enquanto o primeiro está `pending` retorna 409; índice único parcial é exercitado.
8. Arquivo expirado/ausente => 422 e mensagem amigável; `errors()` após expiração => redirect com `imports.errors_file_expired`.
9. Import antigo sem `covenant_error_lines`: CSV de erros no formato atual (com `_linha`, `_erro`, `_covenant`) é parseado; relatório lista nomes/contagens; reprocessamento funciona. CSV ausente => `available: false` e 422.
10. [LGPD] `errors()` de outra clínica => 404; download tem `Cache-Control: private, no-store`; nenhum log contém nome/CPF/telefone (usar `Log::spy()` e fixture com PHI).

Criar `tests/Feature/Patients/PatientImportFilesRetentionTest.php`:

1. Comando apaga original + erros expirados, mantém registro, preenche `files_purged_at` e não toca em `pending`/não expirados.
2. Backfill/migration deixa `files_expire_at` futuro para imports existentes.
3. `errors()` antes da expiração baixa; depois retorna aviso.

Regressão existente (rodar sem alterar expectativas):

- `tests/Feature/Patients/PatientImportServiceTest.php`.
- `tests/Feature/Patients/PatientImportCovenantPlanTest.php`.
- `tests/Feature/Patients/PatientImportAllergiesTest.php` (se existir).
- `tests/Feature/Patients/CovenantPlansEndpointsTest.php`.
- Testes de broadcast/progresso e falha de job de importação.

### 8.2 Vitest — frontend

Criar `tests/JavaScript/Panel/Patients/ImportCovenantMapping.test.js`:

1. Renderiza itens encontrados/não encontrados com contagem e status textual (não só cor).
2. Filtro “Pendentes” e “Selecionar todos os pendentes” (inclui indeterminate).
3. Ação em lote “Particular” aplica a todos os selecionados e monta o payload correto.
4. Ação em lote “Mapear” exige convênio; plano fica desabilitado até escolher convênio; payload inclui `covenant_plan_id`.
5. “Criar convênio” aparece só com `can_create_covenant`; sem permissão, mostra hint.
6. Erro 422 do backend é exibido por linha e o foco vai ao primeiro erro; `aria-live` anuncia.
7. “Salvar e reprocessar” desabilitado quando `files_expired` ou sem linhas; confirmação e estado de busy.
8. Snapshot/axe básico: labels associados, `scope="col"`, checkbox com nome acessível.

Se o projeto não tiver harness de componente Vue montado, testar a lógica exportada do componente (funções de payload/filtro) e manter os testes de página em `Import.vue` com `mount`/stubs no padrão dos testes existentes. Não adicionar dependência nova de teste.

### 8.3 Comandos e pré-requisitos

- Banco de testes: somente PostgreSQL `easyeye_test`, local, sem `--parallel`, sem criar banco por sessão, conforme `phpunit.xml` e `AGENTS.md`. A etapa de implementação deve confirmar autorização para `RefreshDatabase` (recria schema) antes de rodar.
- Backend: `rtk proxy php vendor/bin/pest tests/Feature/Patients/PatientImportCovenantMappingTest.php tests/Feature/Patients/PatientImportCovenantReprocessTest.php tests/Feature/Patients/PatientImportFilesRetentionTest.php` e, depois, a suíte de pacientes/importação.
- Frontend: `rtk npm test`.
- Estilo: `rtk proxy php vendor/bin/pint --test` nos arquivos alterados; `rtk npm run lint` e `rtk npm run format:check`.
- Não rodar `composer dev`, migrations/seeds de setup, nem `npm run build` como check passivo. Se o build for necessário, avisar que ele substitui `public/vendor`, `public/build` e `bootstrap/ssr` e preservar artefatos locais.
- Cypress, se necessário, sempre de `e2e/` (`cd e2e && npx cypress ...`), nunca da raiz.

## 9. Divisão de implementação

### 9.1 Forja-nova (backend/testes)

Ordem:

1. Migrations + models (`PatientImportCovenantMapping`, ajustes em `PatientImport`) + casts/relações/índices.
2. `ImportNameNormalizer` e substituição no `PatientImportService` (sem mudar semântica).
3. `PatientImportCovenantResolver` + `CovenantResolutionException` + ajuste de `safeFailureReason`.
4. `PatientImportCovenantReportService` (incluindo parser do CSV de erros antigo).
5. `PatientImportCovenantMappingService` + `PatientImportCovenantMappingsRequest` + `CovenantSearchController`.
6. `PatientImportCovenantsController` (show/update/reprocess) + `PatientImportReprocessService`.
7. Ajustes em `PatientImportService` (prévia, filtro de linhas, resolução, persistência de linhas/snapshot).
8. `PrunePatientImportFilesCommand` + `config/patient_imports.php` + schedule + ajuste de `errors()`.
9. Rotas + `PatientImportsController@index/serializeImport` + traduções.
10. Testes Pest e regressão.

Arquivos previstos:

- `database/migrations/2026_10_23_120000_create_patient_import_covenant_mappings_table.php`
- `database/migrations/2026_10_23_121000_add_covenant_mapping_fields_to_patient_imports.php`
- `app/Models/PatientImportCovenantMapping.php`
- `app/Models/PatientImport.php`
- `app/Support/ImportNameNormalizer.php`
- `app/Services/Covenants/PatientImportCovenantResolver.php`
- `app/Services/Covenants/CovenantResolutionException.php`
- `app/Services/Covenants/PatientImportCovenantReportService.php`
- `app/Services/Covenants/PatientImportCovenantMappingService.php`
- `app/Services/Covenants/PatientImportReprocessService.php`
- `app/Http/Controllers/PatientImportCovenantsController.php`
- `app/Http/Controllers/CovenantSearchController.php`
- `app/Http/Requests/PatientImportCovenantMappingsRequest.php`
- `app/Console/Commands/PrunePatientImportFilesCommand.php`
- `config/patient_imports.php`
- `routes/web.php`, `routes/console.php`
- `app/Services/PatientImportService.php`
- `app/Http/Controllers/PatientImportsController.php`
- `lang/pt_BR/imports.php`, `lang/en/imports.php`
- `tests/Feature/Patients/PatientImportCovenantMappingTest.php`
- `tests/Feature/Patients/PatientImportCovenantReprocessTest.php`
- `tests/Feature/Patients/PatientImportFilesRetentionTest.php`

### 9.2 Prisma (frontend)

Depende do contrato da seção 3; pode começar com fixtures/mocks enquanto Forja implementa.

1. `resources/js/Components/Panel/PatientImportCovenantMapping.vue`.
2. `resources/js/Pages/Panel/Patients/Import.vue` (prévia, histórico, botão resolver, confirmação, reprocessamento, expiração).
3. Ajustes de estilo Bootstrap/Tailwind existentes; sem dependência nova.
4. `tests/JavaScript/Panel/Patients/ImportCovenantMapping.test.js`.
5. Rodar `rtk npm test`, `rtk npm run lint`, `rtk npm run format:check`.

Handoff: Forja entrega endpoints e payloads; Prisma não inventa campos. Se o contrato mudar, atualizar o plano/API antes de codar.

## 10. Riscos e mitigação

| Risco | Mitigação |
|---|---|
| CSV de erros antigo sem código de erro legível | Para imports novos, usar `CovenantResolutionException` + `covenant_error_lines`; para antigos, parser por fragmentos estáveis + fixture. Se o CSV sumiu/expirou, pedir reenvio do arquivo. |
| De-para muda o convênio e o plano da planilha deixa de existir no novo convênio | UI mostra hint; plano mapeado prevalece; sem plano mapeado, a planilha é resolvida no novo convênio e pode gerar aviso. |
| De-para persiste após cancelar a importação | É configuração da clínica; avisar na UI; auditoria registra. |
| Criar convênio duplica nome/cor | Busca idempotente por nome normalizado; reutiliza/reativa/restaura; cor determinística com checagem; `CovenantRequest` continua sendo a regra da tela de configurações. |
| Duas requests de reprocessamento ao mesmo tempo | Transação + `lockForUpdate` + índice único parcial + guarda de import ativo; traduzir violação para 409. |
| Limite do plano atinge o reprocessamento | Verificação por linha já existente; aborta com `abort_reason` e sem novos pacientes; teste dedicado. |
| Payload grande de prévia | Limite de 500 itens + `truncated`; relatório completo sob demanda; não enviar listas de linhas ao Inertia. |
| F4 (COUNT/fresh por linha) piorar | Resolvedor carrega uma vez; nenhuma query nova dentro do loop; prévia em passagem única; reprocessamento sem contagem completa. |
| `EntityScope` esconder convênios globais ou vazar próprios de outra clínica | Resolvedor e busca usam explicitamente próprios + globais; testes de tenant; nunca confiar no `covenant_id` do request sem validar visibilidade/ativo. |
| `safeFailureReason` tratar a nova exceção como erro inesperado | Atualizar a condição para aceitar `CovenantResolutionException`; teste de mensagem no CSV de erros. |
| Arquivo original expirado antes de reprocessar | `files_expire_at` com 30 dias + aviso na UI + 422 claro; backfill com prazo a partir do deploy. |
| Exclusão de usuário deixar arquivo órfão | `User` usa soft delete; hard delete é exceção. Registrar como limitação e, se necessário, tarefa futura de limpeza de órfãos. |
| `patient_imports.user_id` com cascade em hard delete | Fora do escopo; não alterar o contrato agora. O comando de retenção cobre os registros existentes. |

## 11. Critérios de aceite

- [ ] A prévia de um CSV com convênios desconhecidos mostra todos os nomes únicos, com contagem de linhas e status `found/unmatched/inactive`, além do alvo atual quando houver de-para.
- [ ] O relatório de um import concluído lista os 19 nomes do caso real com as contagens; a UI filtra “Pendentes” e permite mapear em lote.
- [ ] Mapear “PAS 606” para um convênio/plano existente grava o de-para por `entity_id`; uma nova prévia/import do mesmo nome já aparece como `mapped` e usa o alvo automaticamente.
- [ ] “SulAmérica”, “SULAMERICA” e “Sul América” compartilham o mesmo de-para.
- [ ] Criar convênio na hora funciona para admin/`settings.manage`, é idempotente, não duplica cor/nome e não é permitido para quem não tem a permissão.
- [ ] “Importar como particular” grava `covenant_id` do Particular e zera plano/carteirinha.
- [ ] O plano mapeado prevalece sobre a coluna `plano`; sem plano mapeado, a regra atual de aviso permanece.
- [ ] Reprocessar cria um import filho, copia o arquivo, processa **somente** as linhas de convênio e não altera o pai.
- [ ] Não há paciente duplicado por CPF, nome+telefone ou `import_code` após reprocessar; o teste de duas execuções passa.
- [ ] Limite do plano é respeitado no reprocessamento; não grava além do limite.
- [ ] Reproduzir duas vezes o mesmo reprocessamento em paralelo resulta em um único import ativo (409 no segundo).
- [ ] Import antigo sem colunas novas é lido pelo CSV de erros; se o CSV sumiu/expirou, a UI explica e não quebra.
- [ ] Download de erros é autenticado, escopado por `entity_id`, privado e `no-store`; após a expiração, retorna aviso amigável.
- [ ] A limpeza diária apaga original + erros expirados, mantém o histórico e registra `files_purged_at`.
- [ ] Nenhum log contém nome, CPF, telefone, carteirinha ou conteúdo de linha; o relatório de convênios não contém PHI.
- [ ] Nenhuma consulta nova por linha; prévia em passagem única; reprocessamento sem contagem completa; F4 não piora.
- [ ] Testes Pest e Vitest verdes; Pint/ESLint/Prettier sem novas falhas; build só se autorizado.
- [ ] Nenhum commit sem “commit autorizado” do orquestrador; nenhuma alteração em prontuário/assinatura/locks/TISS/billing/recall.

## 12. Regras do repositório aplicáveis

- **Isolamento por `entity_id`:** toda leitura/gravação usa `selected_entity_id`/`import->entity_id`; `PatientImport` e o de-para são escopados; `abort_if` 404 para outra clínica; nunca aceitar `entity_id` do request.
- **Camadas:** controllers fazem a borda HTTP; `PatientImportService` e os serviços de convênios concentram regra/transação. Não colocar resolução de convênio no Vue.
- **Menor mudança completa:** reutilizar `normalizeKey`, `CovenantPlan::selectableFor`, `SearchSelect`, `BroadcastsImportProgress`, `Auditable`/`HasAuditColumns`, `whereLikeUnaccent`; sem dependência nova.
- **LGPD:** CSV de erros é PHI em disco privado; download autenticado; retenção; nenhum log de linha; nenhum envio externo.
- **Banco/testes:** somente `easyeye_test`, local, sem `--parallel`, sem migrations/seeds de setup; `RefreshDatabase` exige autorização da etapa.
- **Qualidade:** Pest para comportamento/risco; Vitest para UI; Pint/ESLint/Prettier; registrar comando, resultado e limitações; falha legada não pode ser escondida.
- **Escopo sensível:** o plano não toca prontuário, assinatura, locks de agenda, TISS, billing ou recall. A resolução de convênio/plano é a mesma do cadastro manual e não altera contratos financeiros.
- **Commit:** apenas após “commit autorizado” do orquestrador; esta etapa entrega somente o plano.
