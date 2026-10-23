# Plano — Remarcar a série de sessões (iGUT Fase 1)

Data: 2026-10-21 · Autor: Bússola (planejador) · Origem: briefing do orquestrador Claude Code (fechamento da Fase 1 "Agenda e atendimento")

Âncoras conferidas no código em 21/10/2026, no branch `desenv` (HEAD `5aeab203`): `ScheduleSeriesService` (`lockSeries`, `seriesLockKey`, `preview`, `createSessions`, `addSession`, `cancelFuture`, `copyableAttributes`), `ScheduleService` (`validateFor`, `validateFitIn`, `validateSlot`, `fitInLimitError`, `fitInCount`, `assertFitInCapacity`, `assertDoctorFreeOfSurgery`, `bulkReschedule`), `SchedulesController` (`reschedule`, `seriesPreview`, `seriesRows`, `seriesCancellableCount`, `toScheduleRow`, `buildScheduleItems`, `authorizeSchedule`), rotas de série, `RescheduleModal.vue`, `ScheduleDetailDrawer.vue`, `Index.vue`, `ScheduleFormModal.vue`, `ScheduleRequest`, migração do índice único, `ScheduleSeriesTest.php`, `PostOpReturnsTest.php`, `ScheduleSeriesNumberingRaceTest.php`, lang `pt_BR/en`, manuais e `e2e/`.

Hipótese de invalidação: se `ScheduleSeriesService::lockSeries` mudar de chave/ordem, se o índice único `schedules_doctor_datetime_active_unique` deixar de excluir cancelados/faltas ou encaixes, ou se o `reschedule()` unitário mudar a lista de campos copiados, este plano precisa ser revisto antes da implementação.

> Este documento é só planejamento. Nenhum código, migration, teste ou commit foi produzido. A execução é das frentes **Forja** (backend + testes PHP) e **Prisma** (front + Vitest + lang), com revisão da Lupa.

---

## 0. Regras do repositório que valem para esta entrega

Citadas do `CLAUDE.md`/`AGENTS.md` e de `docs/internal/engineering/domain-invariants.md`:

- **Isolamento por clínica**: toda query/rota usa `entity_id` + `selected_entity_id`; agendamento de outra clínica responde **404** (route binding/`authorizeSchedule`), médico sem vínculo responde **403**. A série é sempre lida com `entity_id` + `series_id` (defesa em profundidade).
- **Regra de negócio em service**: controller só valida a borda HTTP, autoriza e traduz exceções; a transação, a ordem de locks e a semântica de cancelar/criar ficam em `ScheduleSeriesService`/`ScheduleService`.
- **Locks da agenda** (`domain-invariants`, Cirúrgico item 3 e Validação clínica/recall item 8): a ordem é **cota do encaixe → trava da SÉRIE → linhas da série**; todo caminho que põe consulta no horário do médico confere a cirurgia sob a trava (`ScheduleService::assertDoctorFreeOfSurgery`). A remarcação em lote segue a mesma ordem do `addSession`/`store()`; não se toma `lockPatientDay` porque a transição é para **Cancelado** a partir de Agendado/Confirmado (não há reconciliação de capturas de Atendido).
- **Sem prontuário/assinatura/TISS/billing/recall**: esta entrega não escreve em prontuário, assinatura, guias TISS, financeiro nem recall. O único efeito colateral clínico é o `PostOpReturnService::followReschedule` (retorno pós-operatório), na mesma transação, como no unitário. A remarcação **não** escreve em curso/aplicação anti-VEGF (invariante item 8): o vínculo é lido ao vivo.
- **Testes**: único banco autorizado **`easyeye_test`**, sem `--parallel`, sem criar banco, sem migrations/seeds como preflight. `RefreshDatabase`/`DatabaseMigrations` recriam schema e exigem autorização explícita para esse efeito. Testes de integração rodam um caminho por vez.
- **Qualidade**: Pint nos arquivos PHP, ESLint/Prettier nos Vue/JS, Pest/Vitest. Sem `npm run build` nesta entrega (o build mexe em `public/` e `bootstrap/ssr/`); se o orquestrador pedir, fazer fora da tarefa.
- **Commit**: nenhum commit/push sem a frase exata **"commit autorizado"**. O status doc está untracked no checkout atual; não commitar sem autorização.

---

## 1. Objetivo e resultado esperado

A recepção, ao reagendar uma sessão de uma série, escolhe:

1. **Só esta sessão** — comportamento atual (não muda).
2. **Esta e as seguintes (N)** — a sessão escolhida e as futuras da mesma série **a partir dela** que ainda não começaram deslocam pelo **mesmo delta** de data/hora; as anteriores (mesmo futuras), passadas, atendidas, em andamento, canceladas e faltas ficam intactas.

A prévia mostra, por sessão, a data nova e os conflitos (médico, escala, bloqueio, sala e teto do turno no encaixe). **Qualquer conflito bloqueia a série inteira** (all-or-nothing). A numeração (`3/10`) e o total são preservados; as sessões antigas viram **Cancelado** com a nota "Reagendado para :code", com log de situação. Convênio inativo recusa.

### Fora de escopo

- Não cria recorrência nova, não regenera datas por frequência e não muda `session_number`/`session_total`.
- Não altera o comportamento do `reschedule()` unitário além de tomar a trava da série (para serializar com o lote) e reutilizar a lista de campos copiados.
- Não dispara `NotifyScheduleChangeJob` no lote (decisão D7); o manual avisa que a recepção combina o novo horário.
- Não adiciona Cypress: `e2e/` não cobre remarcação hoje.
- Não mexe em prontuário/assinatura/TISS/billing/recall/anti-VEGF.
- Não muda a regra de "Cancelar as futuras" (`cancelFuture`), exceto tomar a trava da série antes de ler as sessões.

---

## 2. Decisões adotadas

| # | Decisão | Justificativa |
|---|---|---|
| D1 | **Conjunto afetado**: sessões da mesma `entity_id` + `series_id`, com `date_time >= now()` **e** `date_time >= âncora`, situação **Agendado (1)** ou **Confirmado (2)**; perfil médico filtra `doctor_id` dele. A âncora entra (se elegível). | Reaproveita a régua de elegibilidade do `cancelFuture` (futuro + Agendado/Confirmado) e acrescenta o piso da âncora; passadas/atendidas/em andamento/canceladas/faltas não podem ser "desmarcadas" por um lote. |
| D2 | **Delta**: `novo_início_âncora - antigo_início_âncora` em segundos, aplicado a cada `date_time` com `addSeconds()`. Não usar `addWeeks()`/`addMonths()`. | Preserva o intervalo real e o dia 31 (um delta fixo não "volta" o mês). |
| D3 | **Médico/encaixe**: `doctor_id` e `is_fit_in` são opcionais no payload. Ausente/null = **cada sessão mantém o seu**; presente = **vale para todas as afetadas**. O front só envia o campo se a recepção o mudou. | Série pode ter médicos diferentes (admin) ou misturar encaixe/normal; o unitário mantém o que não foi tocado. Evita "promover" todas as sessões ao médico da âncora sem intenção. |
| D4 | **Prévia read-only, sem lock**: valida cada sessão com `validateFor(...)` e **exclui todos os ids do conjunto afetado** da checagem de conflito/cota. `old_label`/`new_label` formatados no servidor. | O caso clássico (+1 semana) faz a data nova da sessão k coincidir com a antiga da k+1; excluir só a âncora daria falso conflito. A escrita revalida sob lock; a prévia pode ficar stale. |
| D5 | **Escrita em uma transação**: pré-leitura sem lock → locks de cirurgia (médicos ordenados) → cotas de encaixe de destino (chaves ordenadas) → **trava da série** → linhas `FOR UPDATE` (ordenadas) → relê; se o conjunto **cresceu**, o delta mudou ou uma sessão remanescente mudou de data/médico/encaixe, **409** "tente de novo"; se só **encolheu**, segue com o que restou → **revalida cada sessão com `validateFor`** (escala, bloqueio, sala, dupla marcação; ids afetados excluídos) → **cancela todas as antigas primeiro** → cria as novas (com o teto reconferido sob a cota) → nota/log finais. Qualquer erro = rollback total. | Cancelar antes de criar libera o índice único do médico (que ignora cancelados/faltas e encaixes) e evita colisão k→k+1; o 409 protege contra mudança concorrente sem abrir deadlock; a revalidação sob as travas cobre o que a prévia viu antes de outra pessoa reservar sala/bloqueio. |
| D6 | **Semântica do unitário**: o novo agendamento copia exatamente a lista do `reschedule()` atual (paciente, convênio, visita, tipo, área, nome, telefones, WhatsApp, notas, situação Agendado, encaixe, série/número/total, `surgical_case_id`, `active`); `origin` **não** é copiado (igual ao unitário); sala/equipamento são sincronizados; `followReschedule` roda para retorno cirúrgico; a antiga vira Cancelado com `reschedule_note` e `ScheduleSituationLog`. | Reaproveita a regra existente e mantém a paridade; evita divergência silenciosa entre remarcar uma e remarcar a série. |
| D7 | **Sem `NotifyScheduleChangeJob`** no lote (o unitário também não dispara). `cancelFuture` dispara, mas é outro fluxo. | Decisão de produto registrada; o manual diz que a recepção combina o horário. Se o dono quiser aviso, é mudança separada. |
| D8 | **Convênio inativo**: se qualquer sessão afetada tem `covenant_id` inativo, a prévia mostra o erro e a escrita recusa com 422 `covenant_id`, sem gravar nada. | O unitário já recusa; a série não pode "passar adiante" um convênio inativado no meio do caminho. |
| D9 | **Data inválida**: `date_time` deve ser futura e **diferente** do horário atual da âncora; delta zero ou passado = 422, sem gravar. A regra vale para a série; o unitário permanece como está. | Evita transação inútil e prévia "tudo igual". |
| D10 | **Resposta**: `{ message, anchor_id, updated, new_schedules: [...] }`; `anchor_id` é o id do novo agendamento da sessão escolhida. O front atualiza `viewScheduleId` e o drawer recarrega. | Sem `anchor_id` o drawer continuaria na sessão cancelada. |
| D11 | **Trava da série no unitário**: `reschedule()` unitário de sessão de série toma `lockSeries` depois da cota e antes de criar/cancelar; `cancelFuture` toma antes de ler. `addSession` já está na ordem correta e não muda. | Serializa o unitário com o lote e com `addSession`/`cancelFuture`, sem inverter a ordem documentada. |
| D12 | **Sem Cypress** nesta entrega; cobertura de front via Vitest e de comportamento via Pest. | `e2e/` não tem suíte de remarcação; criar uma agora seria escopo novo. |

---

## 3. Contratos

### 3.1 `GET /panel/schedules/{schedule}/series/reschedule-preview`

Nome: `panel.schedules.series.reschedule-preview` · query string · autenticado/painel.

Query:

| Campo | Tipo | Obrigatório | Regra |
|---|---|---|---|
| `date_time` | string `Y-m-d H:i` (aceita ISO do SlotPicker) | sim | `date`, `after:now`; o service recusa delta zero |
| `doctor_id` | uuid | não | se vier, `canAccessDoctorId` + médico da clínica + não deletado (mesma closure do unitário) |
| `is_fit_in` | boolean | não | se ausente, cada sessão mantém o seu |

Resposta `200`:

```json
{
  "total": 5,
  "delta_seconds": 604800,
  "sessions": [
    {
      "id": "uuid-da-sessao",
      "session_number": 1,
      "session_total": 5,
      "doctor_id": "uuid-do-medico-efetivo",
      "is_fit_in": false,
      "old_date_time": "2026-10-05T09:00",
      "new_date_time": "2026-10-12T09:00",
      "old_label": "05/10/2026 09:00",
      "new_label": "12/10/2026 09:00",
      "errors": []
    }
  ]
}
```

- `total` = quantidade de sessões afetadas (não o `session_total` da série).
- `errors` já traduzidos, únicos, na ordem: cirurgia, escala, bloqueio, sala, cota de encaixe, convênio, data repetida. Qualquer `errors` não vazio desabilita o confirmar.
- Série inexistente/âncora não elegível: `422` com `message` e `errors.date_time` (ou `series`), nunca `200` com lista vazia.
- Outra clínica: `404`; médico sem vínculo: `403`; médico de outra clínica no `doctor_id`: `422` como no unitário.

### 3.2 `POST /panel/schedules/{schedule}/series/reschedule`

Nome: `panel.schedules.series.reschedule` · JSON · mesma autorização.

Body:

```json
{
  "date_time": "2026-10-12T09:00",
  "doctor_id": "uuid-opcional",
  "is_fit_in": false
}
```

- `doctor_id`/`is_fit_in` **omitidos** = cada sessão mantém o seu; presentes = aplicam-se a todas as afetadas.
- `date_time` obrigatório, futura, diferente da âncora.

Resposta `201`:

```json
{
  "message": "Série remarcada: 5 sessões.",
  "anchor_id": "uuid-do-novo-agendamento-da-ancora",
  "updated": 5,
  "new_schedules": [
    {
      "id": "uuid",
      "code": "AGD-000123",
      "date_time": "2026-10-12T09:00",
      "session_number": 1,
      "session_total": 5,
      "doctor_id": "uuid",
      "is_fit_in": false,
      "old_id": "uuid-da-sessao-antiga"
    }
  ]
}
```

Erros:

| HTTP | Quando | Corpo |
|---|---|---|
| 404 | sem série, outra clínica, rota não encontrada | mensagem padrão |
| 403 | médico sem vínculo / sessão de outro médico no perfil médico | `schedules.unauthorized` |
| 422 | data passada/igual, sem sessões futuras, conflito em qualquer sessão, convênio inativo, data repetida | `{ message, errors: { date_time|series_dates|covenant_id: [msg] } }` |
| 409 | a série mudou entre a prévia e o lock (cresceu, o delta mudou ou uma sessão remanescente mudou) | `{ message: "A série mudou enquanto você confirmava. Tente de novo." }` |
| 500 | erro inesperado | rollback; nada gravado |

### 3.3 Payload da agenda/drawer

- `toScheduleRow()` ganha `series_future_count` (quantas sessões o "esta e as seguintes" moveria a partir desta; 0 se a linha não é elegível) e `reschedule_series_url`/`reschedule_series_preview_url` (null quando não há série).
- `seriesRows()` (drawer) ganha `series_id`, `series_future_count` e as duas URLs; mantém `number`/`total`, `is_past`, `is_terminal`, `is_current`.
- `show()` já devolve `series_id`, `session_number`, `session_total`, `cancel_series_url`, `add_session_url`; não precisa de campo novo.
- `toCompactRow()` (calendário) não precisa do count/URLs: o drawer busca o detalhe ao abrir.

---

## 4. Frente Forja — backend e testes PHP

### 4.1 `ScheduleSeriesService`

Arquivo: `app/Services/ScheduleSeriesService.php`.

Imports novos: `App\Exceptions\ScheduleSeriesChangedException`, `App\Models\Covenant`, `App\Models\ScheduleSituationLog`, `App\Rules\ActiveCovenant`, `App\Services\Surgery\PostOp\PostOpReturnService`, `App\Support\ScheduleShift`.

1. **Tornar `lockSeries` utilizável pelo controller** — hoje é `private` (linha ~324). Trocar para `public` e manter `seriesLockKey` público. Alternativa: expor `lockSeriesFor(Schedule $schedule)`; a assinatura pública direta é suficiente e já é usada por `addSession`.
2. **Novo método `previewReschedule`**:

```php
/**
 * @return array{total:int, delta_seconds:int, sessions:list<array<string,mixed>>}
 */
public function previewReschedule(
    Schedule $anchor,
    Carbon $newDateTime,
    ?string $doctorOverride,
    ?bool $fitInOverride,
    ?string $doctorScope,
): array
```

Passos:
- `abort`/`InvalidArgumentException` se `! $anchor->series_id`.
- Ler as sessões afetadas pela régua D1 (query ordenada por `date_time`, `session_number`), com `doctorScope` quando perfil médico. Se a âncora não estiver no conjunto (passada/terminal), lançar `ScheduleSlotUnavailableException(__('schedules.series_reschedule_anchor_not_cancellable'))`. Se o conjunto for vazio, `series_reschedule_no_sessions`.
- `$delta = $newDateTime->getTimestamp() - $anchor->date_time->getTimestamp()`; se `0`, `series_reschedule_same_time`; se negativo, `series_reschedule_past`.
- Para cada sessão, na ordem:
  - `$effectiveDoctor = $doctorOverride ?: $session->doctor_id;`
  - `$effectiveFitIn = $fitInOverride ?? (bool) $session->is_fit_in;`
  - `$newDate = $session->date_time->copy()->addSeconds($delta);`
  - `$resourceIds = $session->resources()->pluck('clinic_resources.id')->map(fn ($id) => (string) $id)->all();`
  - `$quotaKey = $effectiveDoctor . '|' . $newDate->toDateString() . '|' . ScheduleShift::ofTime($newDate);`
  - `$errors = $this->schedules->validateFor($effectiveFitIn, $effectiveDoctor, $newDate, $affectedIds, $resourceIds, $pendingFitIns[$quotaKey] ?? 0);` (ver 4.2)
  - Se `$session->covenant_id && ! ActiveCovenant::isActive(...)`: acrescentar a mensagem de `validation.custom.covenant.reschedule_inactive` (nome do convênio via `Covenant::withoutGlobalScopes()->whereKey(...)->value('name')`).
  - Se a chave `Y-m-d H:i` já apareceu no conjunto novo: `__('schedules.series_duplicate_date')`.
  - `array_values(array_unique($errors))`.
  - Se `$effectiveFitIn`, incrementar `$pendingFitIns[$quotaKey]` **depois** da validação (a primeira do turno não conta duas vezes).
- Retornar `total`, `delta_seconds` e a lista com `id`, `session_number`, `session_total`, `doctor_id`, `is_fit_in`, `old_date_time`, `new_date_time`, `old_label` (`d/m/Y H:i`), `new_label`, `errors`.
- A prévia **não** toma lock, **não** cria log, **não** altera nada.

3. **Novo método `rescheduleFuture`**:

```php
/**
 * @return array{anchor_id:string, updated:int, new_schedules:list<array{old_id:string, schedule:Schedule}>}
 */
public function rescheduleFuture(
    Schedule $anchor,
    Carbon $newDateTime,
    ?string $doctorOverride,
    ?bool $fitInOverride,
    ?string $entityUserId,
    ?string $doctorScope,
): array
```

Passos dentro de `DB::transaction` (o método é dono da transação, como `cancelFuture`):

1. **Pré-leitura sem lock** das sessões afetadas (D1), guardando `id`, `date_time`, `situation`, `doctor_id`, `is_fit_in`, `covenant_id`, `surgical_case_id`, `resources`. Se a âncora não está no conjunto, 422; se vazio, 422. Calcular `$delta` e os destinos efetivos.
2. **Locks de cirurgia**: para cada par distinto `(doctor_id, new_date)` em ordem `doctor_id` → `new_date`, chamar `$this->schedules->assertDoctorFreeOfSurgery(...)`. Isso trava as janelas cirúrgicas e reconfere; a prévia já mostrou o conflito, mas a escrita não confia nela.
3. **Locks de cota de encaixe**: para cada destino efetivo com `is_fit_in = true`, chamar `$this->schedules->lockFitInQuota($doctorId, $newDate)` **sem contar ainda**. A ordem é a chave ordenada `doctor|Y-m-d|turno`, sem duplicatas. O importante é **travar antes da série**; a contagem autoritativa é feita por `assertFitInCapacity` depois de cancelar as antigas.
4. **Trava da série**: `$this->lockSeries((string) $anchor->entity_id, (string) $anchor->series_id)`.
5. **Linhas**: reler as sessões afetadas com `lockForUpdate()`, ordenadas por `date_time`/`id`. Recalcular o conjunto e o delta a partir da âncora **relida**. Comparar com a pré-leitura:
   - Se a âncora deixou de ser elegível (cancelada/atendida/movida), 422 com a mensagem de "só agendadas/confirmadas".
   - Se o delta mudou, se o conjunto **cresceu** (sessão nova/religada) ou se uma sessão remanescente mudou `date_time`/`doctor_id`/`is_fit_in`, lançar `ScheduleSeriesChangedException` (409).
   - Se o conjunto só **encolheu** (sessão cancelada/atendida por outra pessoa), seguir com o que restou; as cotas extras travadas na etapa 3 são inofensivas.
   - Com o conjunto final, calcular os novos `date_time`, reconferir o convênio de cada antiga (D8: `covenant_id` inativo → 422 `covenant_id`) e a duplicidade de chave `Y-m-d H:i` (→ 422 `series_dates`). Isso falha **antes de qualquer escrita**; o rollback ainda cobre o resto.
   - Revalidar cada sessão final com `validateFor($effectiveFitIn, $effectiveDoctor, $newDate, $affectedIds, $resourceIds)` (escala, bloqueio, sala, dupla marcação e cirurgia; os ids afetados ficam excluídos). Qualquer erro → 422 `series_dates` antes de cancelar. O teto do encaixe fica para o `assertFitInCapacity` depois do cancelamento (a contagem muda quando as antigas viram Cancelado).
6. **Cancelar todas as antigas primeiro**: para cada sessão relida, guardar `from_situation`, `update(['situation' => Cancelled, 'cancellation_reason' => null])`. Não criar `ScheduleSituationLog` ainda.
7. **Criar as novas**: para cada antiga, `$newDate = old.date_time + delta`; se `is_fit_in`, `assertFitInCapacity($doctorId, $newDate)` **sem exclusão** (as antigas já estão canceladas; as novas anteriores contam); criar com a lista de campos do unitário (D6), `entity_id = $old->entity_id`, `situation = Scheduled`, `series_id/session_number/session_total` copiados, `surgical_case_id` copiado; `resources()->sync($resourceIds)`; se `surgical_case_id`, `app(PostOpReturnService::class)->followReschedule($old, $new)`.
   - Capturar `UniqueConstraintViolationException`: se `Schedule::isDoctorSlotConflict($e)`, converter em `ScheduleSlotUnavailableException` com `series_conflict` (data + `validation.custom.schedule.doctor_datetime_unique`, igual ao `createSessions`) e campo `series_dates`; senão relançar. O rollback desfaz o cancelamento.
8. **Nota/log finais**: para cada par antigo→novo, `$note = __('schedules.reschedule_note', ['code' => $new->code]); $old->update(['cancellation_reason' => $note]); ScheduleSituationLog::create([... 'from_situation' => $from, 'to_situation' => Cancelled, 'notes' => $note, 'entity_user_id' => $entityUserId, 'created_at' => now()]);` (mesma forma do unitário; não usar `changeSituation`, que teria outra semântica).
9. `Cache::forget("waiting_room:{$anchor->entity_id}")` ao final (fora ou dentro da transação, como o unitário; preferir depois do commit).
10. Retornar `anchor_id` = id do novo da âncora, `updated` = count, `new_schedules` = lista de pares `old_id` → modelo (o controller mapeia o payload; não expor o modelo cru).

Observações de implementação:
- **Não** reutilizar `copyableAttributes()` para o reschedule: ele inclui `recurrence_group_id`/`recurrence_type`/`recurrence_until` e `situation`; o unitário não copia recorrência. Criar um helper privado `rescheduleAttributes(Schedule $old, string $doctorId, Carbon $newDate, bool $isFitIn): array` com **exatamente** os 20 campos do `SchedulesController::reschedule()` (linhas 709–731), e um teste de paridade indireto (campos abaixo). Se preferir centralizar, mover a lista para `ScheduleService::rescheduleAttributes()` e usar nos dois; a opção de helper no service da série é menor e não mexe no unitário além do necessário.
- `origin` não entra na lista (igual ao unitário). Documentar que uma sessão de pronto atendimento remarcada vira agendamento normal — comportamento atual, fora do escopo.
- O `series_id`/`session_number`/`session_total` vêm da antiga, nunca recalculados.
- `entity_user_id` pode ser null em contextos de sistema; o log aceita nullable (conferir a migration; se não, usar o usuário autenticado).

4. **`cancelFuture`**: adicionar `$this->lockSeries(...)` no início da transação, antes da query. Hoje ele lê as linhas sem a trava (linhas 357–365); com o lote tomando a mesma trava, a leitura precisa ser serializada. Não mudar a semântica (`changeSituation`, notificação por sessão).
5. **`addSession`**: não mudar a ordem (já é cota → série → linhas). Só conferir que continua compilando com as assinaturas novas de `validateFor`/`assertFitInCapacity` (parâmetros opcionais no fim). Ele **não** toma a trava de cirurgia hoje (`assertDoctorFreeOfSurgery`); é uma lacuna pré-existente, fora do escopo desta entrega — registrar se a Lupa apontar, sem corrigir de carona.

### 4.2 `ScheduleService`

Arquivo: `app/Services/ScheduleService.php`.

- **Aceitar múltiplos ids de exclusão** em `validateSlot`, `validateFitIn`, `validateFor`, `fitInLimitError`, `fitInCount`, `resourceErrors`, `isDoubleBooked`, `isResourceDoubleBooked` e `assertFitInCapacity`. Assinatura sugerida: `array|string|null $excludeScheduleIds = null`; normalizar para `array` no início e usar `whereNotIn('id', $ids)` (ou `where('id','!=',...)` quando 1). Chamadas atuais com string continuam funcionando.
- **`fitInLimitError`/`fitInCount`**: adicionar `int $pending = 0` no fim (contagem + pendentes da prévia). `assertFitInCapacity` chama com 0; a prévia chama com o mapa de pendentes.
- **`lockFitInQuota`**: já é `public` (linha ~260); reutilizar no lote para travar as cotas antes da série, sem contar ainda. A contagem autoritativa continua em `assertFitInCapacity` depois do cancelamento. Se preferir uma API explícita, expor `lockFitInQuotas(array $keys)` como açúcar, mas não é necessário.
- **Não** mudar a regra de `fitInCount` (só exclui Cancelado; Faltou ocupa a cota) nem a de `isDoubleBooked` (terminais + encaixes + `origin`). O conjunto afetado só contém Agendado/Confirmado, então a exclusão múltipla não muda a semântica.
- **`rescheduleAttributes`** (opcional, se centralizar): mover a lista do unitário para cá e usar no controller e no service da série. Se não mover, manter os dois blocos idênticos e um comentário cruzado; o teste de campos protege.

### 4.3 Controller e rotas

Arquivo: `app/Http/Controllers/SchedulesController.php`.

- **`seriesReschedulePreview(Request $request, Schedule $schedule, ScheduleSeriesService $series)`**:
  - `$this->authorizeSchedule($schedule);`
  - `abort_unless($schedule->series_id, 404);`
  - Validar `date_time` (`required|date|after:now`), `doctor_id` (mesma closure do unitário: `canAccessDoctorId`, médico da `selected_entity_id`, não deletado), `is_fit_in` (`nullable|boolean`).
  - `$doctorScope = CurrentDoctor::isDoctorProfile() ? (string) CurrentDoctor::doctor()?->id : null;`
  - Chamar `$series->previewReschedule($schedule, Carbon::parse(...), $request->input('doctor_id'), $request->has('is_fit_in') ? $request->boolean('is_fit_in') : null, $doctorScope)`.
  - Capturar `ScheduleSlotUnavailableException`/`InvalidArgumentException` → `slotUnavailableResponse`/422.
  - Responder `200` com o array (sem envelope `data`, para simplicidade do modal; documentar no teste).
- **`seriesReschedule(Request $request, Schedule $schedule, ScheduleSeriesService $series)`**:
  - Mesma autorização/validação.
  - `$doctorOverride = $request->input('doctor_id') ?: null;`
  - `$fitInOverride = $request->has('is_fit_in') ? $request->boolean('is_fit_in') : null;`
  - `$result = $series->rescheduleFuture($schedule, Carbon::parse(...), $doctorOverride, $fitInOverride, session('selected_entity_user_id'), $doctorScope);`
  - Capturar `ScheduleSeriesChangedException` → `409` com `message`; `ScheduleSlotUnavailableException` → `slotUnavailableResponse`; `UniqueConstraintViolationException` → `rethrowUnlessSlotConflict` (ou 422 de conflito de médico); `InvalidArgumentException` → 422.
  - **Exceção nova**: `app/Exceptions/ScheduleSeriesChangedException.php` (`RuntimeException` com a mensagem `schedules.series_reschedule_changed`, ou um `render()` 409). Não reutilizar `ScheduleChangedConcurrentlyException`: ela exige um `Schedule` e usa a mensagem genérica de identidade (`changed_concurrently`); a série precisa da mensagem própria. Se o time preferir reutilizar, o contrato do front (409 + `message`) é o mesmo, mas o texto fica genérico.
  - Responder `201` com `message` (`trans_choice('schedules.series_rescheduled', $result['updated'], ['count' => $result['updated']])`), `anchor_id`, `updated`, `new_schedules` mapeados para `id`, `code`, `date_time` ISO, `session_number`, `session_total`, `doctor_id`, `is_fit_in`, `old_id`.
- **`reschedule()` unitário**: dentro da transação, depois de `assertFitInCapacity` e antes do `create`, chamar `$series->lockSeries(...)` quando `$schedule->series_id` (injetar `ScheduleSeriesService $series` no método ou resolver via `app()`). Não mudar a ordem de criar/cancelar, a lista de campos, a nota nem a ausência de notificação. Isso serializa o unitário com o lote e com `addSession`/`cancelFuture`.
- **`seriesRows`**: adicionar `series_id`, `series_future_count` e as URLs; calcular o count em PHP a partir da coleção já carregada (série ≤ 60), sem N+1:
  ```php
  $cancellable = $sessions->filter(fn (Schedule $s) => in_array($s->situation, ScheduleSeriesService::CANCELLABLE_SITUATIONS, true)
      && $s->date_time->gte(now()));
  // para cada $session: se elegível, count de $cancellable com date_time >= $session->date_time; senão 0.
  ```
- **`toScheduleRow`**: adicionar `series_future_count` e as URLs; `buildScheduleItems` calcula um mapa `series_id => contagem por data` com **uma** query agrupada (só para os `series_id` da página, `date_time >= now()`, situações canceláveis, mesmo escopo de médico) e passa ao `toScheduleRow`. Não fazer query por linha.
- **`authorizeSchedule`**: já cobre entidade + perfil médico; as rotas novas usam. A query da série também filtra `entity_id` (defesa em profundidade).

Rotas (`routes/web.php`, depois de `schedules/{schedule}/series/add-session`, ~848):

```php
// Dentro do grupo 'as' => 'panel.' (routes/web.php:200), o nome final fica
// panel.schedules.series.reschedule-preview / panel.schedules.series.reschedule.
Route::get('schedules/{schedule}/series/reschedule-preview', [SchedulesController::class, 'seriesReschedulePreview'])
    ->name('schedules.series.reschedule-preview');
Route::post('schedules/{schedule}/series/reschedule', [SchedulesController::class, 'seriesReschedule'])
    ->name('schedules.series.reschedule');
```

Ordem: as rotas literais `schedules/series/preview` e `schedules/slots` continuam antes das paramétricas; não há conflito porque as novas têm mais segmentos.

### 4.4 Ordem de locks (resumo)

```
pré-leitura (sem lock)
  → assertDoctorFreeOfSurgery (pares médico/data ordenados)
  → lockFitInQuota (chaves de destino ordenadas)
  → lockSeries (advisory da série)
  → SELECT ... FOR UPDATE das linhas da série (ordenadas)
  → relê / compara (409 se cresceu, o delta mudou ou uma remanescente mudou; encolhimento segue)
  → validateFor de cada nova (escala, bloqueio, sala, dupla marcação)
  → UPDATE antigas para Cancelado (primeiro)
  → assertFitInCapacity por nova (cota já travada; conta as novas anteriores)
  → INSERT novas + sync recursos + followReschedule
  → UPDATE nota final + ScheduleSituationLog
COMMIT
```

- É a mesma família de `store()`/`addSession` (cota → série → linha) e não cria ciclo com `cancelFuture` (série → linha) nem com o unitário (cirurgia → cota → série → linha/INSERT).
- O 409 acontece quando o conjunto **cresceu**, o delta mudou ou uma sessão remanescente mudou de data/médico/encaixe; se só **encolheu**, segue com as remanescentes. O caminho normal não paga retry.
- Se o conjunto encolher (sessão cancelada por outra pessoa), as cotas extras travadas são inofensivas.

### 4.5 Exceção nova

`app/Exceptions/ScheduleSeriesChangedException.php`:

```php
final class ScheduleSeriesChangedException extends RuntimeException
{
    public function __construct(string $message = '')
    {
        parent::__construct($message ?: __('schedules.series_reschedule_changed'));
    }
}
```

- O controller mapeia para **409** com `{ message }`. O modal mostra a mensagem e refaz a prévia.
- Alternativa sem classe: `throw new ScheduleSlotUnavailableException(..., 'series')` e status 409 no controller; a classe é mais explícita e não conflita com o 422 padrão.

### 4.6 Testes PHP

#### `tests/Feature/Schedules/ScheduleSeriesRescheduleTest.php` (novo)

Helpers únicos (`ssrAs`, `ssrStoreSeries`, `ssrSeries`, `ssrPreview`, `ssrReschedule`) para não colidir com `sssAs`/`sssPreview` de `ScheduleSeriesTest.php`. Relógio fixo `2026-10-01 07:00`, entidade client, médico com escala seg–sáb 08–12/15 min, secretária, sala. Série de 5 sessões **semanais** (ex.: 05/10, 12/10, 19/10, 26/10, 02/11 às 09:00 — todas segundas, dentro da escala). `Queue::fake()` **depois** de criar a série, antes do reschedule, para provar que o lote não enfileira.

Casos:

1. **"só esta" inalterado**: `POST /reschedule` unitário continua movendo só a âncora; as demais ficam; resposta `new_schedule`; sem notificação.
2. **"esta e as seguintes" desloca pelo delta**: a sessão 1 é **anterior à âncora** (futura) e fica; da sessão 2 com `+2 dias`, 2..5 movem +2 dias; `delta_seconds` correto; numeração/total preservados. (Isso prova que "a partir da escolhida" não move as anteriores, mesmo futuras.)
3. **Passadas/atendidas/em andamento/canceladas/faltas intocadas**: usar uma série separada (ou um relógio posterior) com uma sessão passada, uma `Attended`, uma `InProgress`, uma `Cancelled` e uma `NoShow`; o lote só move Agendado/Confirmado futuros; as demais mantêm `date_time`, `situation` e `updated_at` (comparar antes/depois).
4. **Um conflito recusa tudo**: reservar um horário de destino com outro agendamento normal; `POST` → 422; **nenhuma** sessão mudou (contar por situação/data; verificar que a antiga não foi cancelada e que não nasceu nova); `errors.series_dates.0` contém o motivo. Variante que prova a **revalidação da escrita**: chamar a prévia (sem conflito), criar o agendamento normal (ou reservar a sala) **depois** da prévia e antes do `POST`; o `POST` ainda deve dar 422 e não gravar.
5. **Caso k→k+1 sem falso conflito**: série semanal; prévia da sessão 1 com `+1 semana` → todas as datas novas coincidem com as antigas das seguintes; `errors` vazio em todas (a exclusão múltipla funciona); `POST` → 201; as 5 antigas Cancelado e as 5 novas Agendado; sem unique violation. **Este é o teste que pega a armadilha central.**
6. **Perfil médico**: médico A com série; médico B tenta preview/POST na série de A → 403/404 conforme `authorizeSchedule`; médico A só move as dele (se a série tiver sessões de outro médico, elas não entram; para admin, entram).
7. **Outra clínica**: sessão de outra entidade → 404; `doctor_id` de outra clínica → 422; não vaza dados.
8. **Encaixe no teto**: série com `is_fit_in`; destino com teto já ocupado (contar Faltou como ocupante) → prévia com erro e POST 422; um caso com vaga → passa. Testar override `is_fit_in = true` para todas e `false` para todas.
9. **Médico override**: série com dois médicos (admin); sem `doctor_id` cada nova mantém o seu; com `doctor_id` todas ficam com o novo; a prévia reflete o novo médico e os conflitos dele.
10. **Pós-operatório**: usar o padrão de `tests/Feature/Surgery/PostOpReturnsTest.php` para criar um caso com D7/D30; remarcar a série a partir do D7; os dois `surgical_postop_returns` apontam para os novos `schedule_id` e as datas novas; o D1 (passado) fica. (Pode ser um teste dentro de `PostOpReturnsTest.php`, não no arquivo novo.)
11. **Numeração/total**: série fechada 3/5 movida; as novas mantêm `session_number`/`session_total`; a série aberta (`session_total` null) também preserva e não renumerar.
12. **Convênio inativo**: uma sessão com convênio inativado → prévia com erro e POST 422 `covenant_id`; nada gravado; reativar e repetir → 201.
13. **Data igual/passada**: POST com o mesmo horário da âncora → 422 `date_time`; com data passada → 422; nada gravado.
14. **Sem série / série sem futuras**: POST em agendamento solto → 404; série só com passadas → 422.
15. **Recursos**: sala/equipamento sincronizados nas novas; a antiga mantém os recursos (não é obrigatório remover, mas o novo precisa ter); conferir `resource_names` no payload.
16. **Sem notificação**: `Queue::fake()` após criar; `Queue::assertNotPushed(NotifyScheduleChangeJob::class)` após o lote. (O `cancelFuture` continua notificando; não confundir.)
17. **Payloads**: `toScheduleRow`/`seriesRows` expõem `series_future_count` correto para a âncora e para uma sessão do meio; `reschedule_series_preview_url`/`reschedule_series_url`; sem N+1 (contar queries com `DB::listen` é opcional).
18. **409 de série alterada** (coberto na integração abaixo): um filho acrescenta uma sessão (crescimento) ou muda a data de uma remanescente; o pai retorna 409 e não grava. O caso de **encolhimento** (filho cancela uma sessão) é o cenário principal da integração e deve **seguir** movendo as remanescentes.

#### `tests/Unit/Lang/SchedulesTranslationParityTest.php` (novo)

- Carregar `lang/pt_BR/schedules.php` e `lang/en/schedules.php`, achatar com `Arr::dot`.
- **Dívida legada**: hoje há 11 chaves só em pt_BR (`btn_attend`, `btn_call`, `call_sent`, `call_panel_title`, `call_panel_subtitle`, `call_panel_enable`, `call_panel_hint`, `call_panel_url`, `call_panel_open`, `call_panel_regen`, `call_panel_saved`) e nenhuma só em en. Não corrigir isso nesta entrega (seria escopo de tradução separado).
- O teste usa uma allowlist `LEGACY_PT_ONLY` com essas 11 chaves e falha se aparecer **qualquer outra** chave só em pt_BR ou qualquer chave só em en. Assim as chaves novas (`series_reschedule_*`) precisam existir nos dois idiomas sem obrigar a traduzir a dívida antiga.
- Comparar valores não é necessário; o teste é de contrato de chaves.

#### `tests/Integration/ScheduleSeriesRescheduleRaceTest.php` + `tests/Integration/Support/series_reschedule_child.php` (novos)

- Seguir o padrão de `ScheduleSeriesNumberingRaceTest.php`/`series_add_session_child.php`: `DatabaseMigrations`, PostgreSQL, processo filho com o mesmo banco, marcador de arquivo, `Process`.
- Cenário: série de 5; o filho abre transação, toma `pg_advisory_xact_lock(ScheduleSeriesService::seriesLockKey($entityId, $seriesId))`, cancela a sessão 2 (update para `Cancelled`), grava o marcador e dorme ~2 s; o pai chama `rescheduleFuture` a partir da sessão 1 com `+1 semana`, espera a trava da série, relê e **exclui** a sessão 2 do conjunto; ao commit do filho, o pai move 1,3,4,5 e deixa a 2 cancelada na data antiga. Medir o tempo do pai (> 1 s) para provar a espera; conferir que não nasceu duplicata e que a numeração ficou.
- Um segundo caso (opcional): o filho **acrescenta** uma sessão nova ou muda a data de uma sessão remanescente; o pai deve retornar 409 e não gravar nada. O caso de **encolhimento** (o filho cancela uma sessão) é o cenário principal e deve **seguir** movendo as remanescentes. Se o 409 for difícil de orquestrar, cobrir com um teste de service que chama `rescheduleFuture` com a série já alterada por outra conexão; a integração principal (releitura) é a mais importante.
- Não usar `--parallel`; um caminho por vez.

#### Ajustes em testes existentes

- `tests/Feature/Schedules/ScheduleSeriesTest.php`: se a assinatura de `validateFor`/helpers mudar, os helpers continuam com string; nenhum ajuste esperado. Adicionar um caso de `cancelFuture` que prove que a trava foi tomada? Não é necessário; a integração cobre o lote.
- `tests/Feature/Schedules/ScheduleRescheduleTest.php` (se existir): conferir que o unitário continua sem notificação e que a trava da série não mudou o resultado.
- `tests/Feature/Surgery/PostOpReturnsTest.php`: adicionar o caso 10 (série + D7/D30) no bloco de pós-op; reutilizar os helpers existentes.

### 4.7 Comandos da frente Forja

```bash
rtk proxy php vendor/bin/pest tests/Feature/Schedules/ScheduleSeriesRescheduleTest.php
rtk proxy php vendor/bin/pest tests/Unit/Lang/SchedulesTranslationParityTest.php
rtk proxy php vendor/bin/pest tests/Feature/Surgery/PostOpReturnsTest.php
rtk proxy php vendor/bin/pest tests/Integration/ScheduleSeriesRescheduleRaceTest.php
rtk proxy php vendor/bin/pint app/Services/ScheduleSeriesService.php app/Services/ScheduleService.php app/Http/Controllers/SchedulesController.php app/Exceptions/ScheduleSeriesChangedException.php tests/Feature/Schedules/ScheduleSeriesRescheduleTest.php tests/Feature/Surgery/PostOpReturnsTest.php tests/Unit/Lang/SchedulesTranslationParityTest.php tests/Integration/ScheduleSeriesRescheduleRaceTest.php tests/Integration/Support/series_reschedule_child.php
```

- Rodar os testes de integração **depois** de confirmar `easyeye_test`, ambiente local/testing, sem outro escritor e com autorização para `DatabaseMigrations`.
- Se o Pint alterar arquivos fora do escopo, não incluir; rodar só nos arquivos tocados.

---

## 5. Frente Prisma — front, i18n e Vitest

### 5.1 `resources/js/Pages/Panel/Schedules/RescheduleModal.vue`

1. **Estado novo**:
   ```js
   import { ref, watch, computed, nextTick } from 'vue';
   const scope = ref('one'); // 'one' | 'following'
   const seriesPreview = ref([]);
   const seriesLoading = ref(false);
   const seriesError = ref('');
   let seriesDebounce = null;
   let seriesRequest = 0;
   ```
2. **Ao abrir (`watch` do `item`)**: resetar `scope = 'one'`, `seriesPreview = []`, `seriesLoading = false`, `seriesError = ''`, `seriesRequest++` (invalidar respostas antigas); limpar o debounce.
3. **Elegibilidade**:
   ```js
   const seriesCount = computed(() => Number(props.item?.series_future_count ?? 0));
   const canRescheduleSeries = computed(() => Boolean(props.item?.series_id) && seriesCount.value > 1);
   const followingLabel = computed(() => (props.t.series_reschedule_following ?? 'Esta e as seguintes (:count)').replace(':count', seriesCount.value));
   ```
   O rádio só aparece se `canRescheduleSeries`. O label "Esta e as seguintes (N)" usa `followingLabel`; o fallback acontece **antes** do `.replace` (uma chave ausente no stub de Vitest não pode quebrar o render; o backend sempre manda `t`).
4. **Prévia** (somente `scope === 'following'`):
   - `watch([dateTime, doctorId, isFitIn, scope], schedulePreview)`; `schedulePreview` limpa timeout e agenda 350 ms; se `!dateTime`, limpa preview/erro e retorna.
   - Ao agendar (ou ao entrar em `following`), limpar `seriesPreview`/`seriesError`, incrementar `seriesRequest` e marcar `seriesLoading = true` **antes** do debounce: a prévia antiga não pode validar o horário novo, e o confirmar fica bloqueado até a resposta atual. Ao voltar para `one`, limpar tudo e `seriesLoading = false`.
   - `const body = new URLSearchParams({ date_time: dateTime.value })`; se `doctorId.value !== props.item.doctor_id`, `body.set('doctor_id', doctorId.value)`; se `isFitIn.value !== Boolean(props.item.is_fit_in)`, `body.set('is_fit_in', isFitIn.value ? '1' : '0')`.
   - `fetch(`${props.item.reschedule_series_preview_url}?${body}`, { headers: { Accept: 'application/json' } })`; token local; se a resposta não for a última, ignora.
   - `200`: `seriesPreview = json.sessions ?? []`; `seriesError = ''`.
   - `422`: `seriesPreview = []`; `seriesError = json.message`.
   - `catch`: `seriesError = t.series_reschedule_error`.
   - `finally`: `seriesLoading = false` só se a requisição for a atual.
   - `const seriesConflicts = computed(() => seriesPreview.value.filter(s => (s.errors ?? []).length > 0));`
   - `const conflictsLabel = computed(() => (props.t.series_reschedule_conflicts ?? 'Há conflito em :count sessão(ões). Ajuste o horário para continuar.').replace(':count', seriesConflicts.value.length));`
   - `const seriesBlocked = computed(() => scope.value === 'following' && (seriesLoading.value || Boolean(seriesError.value) || seriesConflicts.value.length > 0 || seriesPreview.value.length === 0));`
5. **Confirmar**:
   - `:disabled="saving || !dateTime || seriesBlocked"`.
   - `onConfirm`: no início, `if (scope.value === 'following' && seriesBlocked.value) return;` (o disabled não é a única barreira). Se `scope === 'one'`, manter o fetch atual (`reschedule_url`, body com `date_time`, `doctor_id`, `is_fit_in`).
   - Se `following`: `fetch(props.item.reschedule_series_url, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': ... }, body: JSON.stringify({ date_time: dateTime.value, ...(doctorId.value !== props.item.doctor_id ? { doctor_id: doctorId.value } : {}), ...(isFitIn.value !== Boolean(props.item.is_fit_in) ? { is_fit_in: isFitIn.value } : {}) }) })` — os mesmos headers do caminho unitário.
   - `201`: `bsModal.hide(); emit('rescheduled', json)` (o pai usa `json.anchor_id`).
   - `409`/`422`: `errorMsg = json.message`; se `409`, limpar a prévia e chamar `schedulePreview()` de novo (a mensagem orienta tentar de novo).
6. **Template**:
   - Envolver o rádio num `<fieldset v-if="canRescheduleSeries" class="mb-3" data-test="series-reschedule-scope">` com `<legend class="form-label fw-semibold fs-6">{{ t.series_reschedule_scope }}</legend>` e dois `form-check`:
     ```html
     <input id="reschedule-scope-one" v-model="scope" type="radio" value="one" class="form-check-input" name="reschedule-scope" />
     <label for="reschedule-scope-one" class="form-check-label small">{{ t.series_reschedule_one }}</label>
     <input id="reschedule-scope-following" v-model="scope" type="radio" value="following" class="form-check-input" name="reschedule-scope" />
     <label for="reschedule-scope-following" class="form-check-label small">{{ followingLabel }}</label>
     ```
   - Texto de ajuda `t.series_reschedule_hint` (passadas/atendidas/etc. ficam).
   - Se `doctorId.value !== props.item.doctor_id` ou `isFitIn.value !== Boolean(props.item.is_fit_in)` (no template, `doctorId !== item.doctor_id`), mostrar `t.series_reschedule_doctor_hint`/`t.series_reschedule_fit_in_hint` quando `following` (deixa claro que vale para todas).
   - Bloco da prévia abaixo do SlotPicker:
     ```html
     <div v-if="scope === 'following'" class="mt-3" data-test="series-reschedule-preview">
       <div class="small fw-semibold mb-1">{{ t.series_reschedule_preview }}</div>
       <div v-if="seriesLoading" class="text-muted small" role="status" aria-live="polite">
         <span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>{{ t.series_reschedule_loading }}
       </div>
       <div v-else-if="seriesError" class="alert alert-warning py-2 small mb-2" role="alert">{{ seriesError }}</div>
       <div v-else-if="seriesConflicts.length" class="alert alert-danger py-2 small mb-2" role="alert">
         {{ conflictsLabel }}
       </div>
       <ul v-if="seriesPreview.length" class="list-unstyled small mb-0" style="max-height: 35vh; overflow-y: auto;" aria-live="polite">
         <li v-for="s in seriesPreview" :key="s.id" class="d-flex justify-content-between gap-2 py-1 border-bottom">
           <span class="text-nowrap">#{{ s.session_number }}<template v-if="s.session_total">/{{ s.session_total }}</template></span>
           <span class="flex-grow-1">{{ s.old_label }} → <strong>{{ s.new_label }}</strong></span>
           <span v-if="s.errors.length" class="text-danger text-end">{{ s.errors.join('; ') }}</span>
           <span v-else class="text-success text-nowrap">{{ t.series_ok }}</span>
         </li>
       </ul>
     </div>
     ```
   - `aria-live="polite"` no container da prévia; `role="alert"` nos erros; o spinner tem `aria-hidden="true"` (o texto ao lado já anuncia).
   - O botão de confirmar ganha um `:title`/texto visível quando `seriesBlocked` ("Resolva os conflitos para confirmar" / `t.series_reschedule_blocked`) para não depender só da cor/disabled.
7. **Fallbacks**: manter os fallbacks atuais (`?? 'Reagendar'` etc.) e usar `t.series_reschedule_*` com fallback pt_BR apenas quando a chave não existir; o backend sempre manda `t` completo.

### 5.2 `ScheduleDetailDrawer.vue` e `Index.vue`

- `ScheduleDetailDrawer.vue`:
  - `rescheduleSession(session)` passa a incluir `series_id: session.series_id`, `series_future_count: session.series_future_count`, `reschedule_series_url: session.reschedule_series_url`, `reschedule_series_preview_url: session.reschedule_series_preview_url`.
  - Nada mais muda no drawer (o rádio vive no modal). O botão já só aparece para `!is_terminal && !is_past`.
- `Index.vue`:
  - `onRescheduled(json)`:
    ```js
    const newId = json.anchor_id ?? json.new_schedule?.id;
    if (detailOpen.value && newId) viewScheduleId.value = newId;
    router.reload({ only: ['scheduleItems', 'counters'] });
    ```
    O `watch` do drawer em `props.scheduleId` recarrega o detalhe (linhas 128–135); não é preciso fechar/reabrir.
  - Manter `onDrawerReschedule`/`onDrawerChanged`.
  - Se o `router.reload` re-renderizar e o drawer não recarregar (id igual), chamar `detailDrawer.value?.reload()` via `defineExpose` no drawer; o caminho normal (id novo) já dispara o watcher. Preferir a solução mínima: `viewScheduleId` + watcher; adicionar `defineExpose` só se o teste mostrar necessidade.

### 5.3 Lang

`lang/pt_BR/schedules.php` e `lang/en/schedules.php`, junto do bloco `series_*`/`reschedule_*`:

Backend:
- `series_rescheduled` → pt: `'{1} Série remarcada: :count sessão.|[2,*] Série remarcada: :count sessões.'`; en: `'{1} Series rescheduled: :count session.|[2,*] Series rescheduled: :count sessions.'` (usar `trans_choice`, no estilo de `series_cancelled`).
- `series_reschedule_no_sessions` → pt: `'Não há sessões futuras para remarcar a partir desta.'`; en: `'There are no future sessions to reschedule from this one.'`
- `series_reschedule_anchor_not_cancellable` → pt: `'Só é possível remarcar sessões agendadas ou confirmadas.'`; en: `'Only scheduled or confirmed sessions can be rescheduled.'`
- `series_reschedule_same_time` → pt: `'Escolha um horário diferente do atual.'`; en: `'Choose a time different from the current one.'`
- `series_reschedule_past` → pt: `'Escolha um horário futuro.'`; en: `'Choose a future time.'`
- `series_reschedule_changed` → pt: `'A série mudou enquanto você confirmava. Tente de novo.'`; en: `'The series changed while you were confirming. Try again.'`

UI (o modal recebe `t`):
- `series_reschedule_scope` → `'Remarcar'` / `'Reschedule'`
- `series_reschedule_one` → `'Só esta sessão'` / `'Only this session'`
- `series_reschedule_following` → `'Esta e as seguintes (:count)'` / `'This and the following (:count)'`
- `series_reschedule_hint` → `'Passadas, atendidas, em andamento, canceladas e faltas ficam como estão.'` / `'Past, attended, in-progress, cancelled and no-show sessions stay as they are.'`
- `series_reschedule_doctor_hint` → `'O médico escolhido vale para todas as sessões afetadas.'` / `'The chosen doctor applies to all affected sessions.'`
- `series_reschedule_fit_in_hint` → `'O encaixe escolhido vale para todas as sessões afetadas.'` / `'The chosen fit-in applies to all affected sessions.'`
- `series_reschedule_preview` → `'Sessões que serão remarcadas'` / `'Sessions that will be rescheduled'`
- `series_reschedule_loading` → `'Conferindo as sessões…'` / `'Checking the sessions…'`
- `series_reschedule_conflicts` → `'Há conflito em :count sessão(ões). Ajuste o horário para continuar.'` / `'There is a conflict in :count session(s). Adjust the time to continue.'`
- `series_reschedule_error` → `'Não foi possível conferir a série. Tente de novo.'` / `'The series could not be checked. Try again.'`
- `series_reschedule_blocked` → `'Resolva os conflitos para confirmar.'` / `'Resolve the conflicts to confirm.'`

Reutilizar: `schedules.reschedule_note`, `schedules.rescheduled`, `schedules.series_conflict`, `schedules.series_duplicate_date`, `schedules.series_ok`, `schedules.form_fit_in`, `schedules.saving`. Conferir se `series_ok` existe nos dois idiomas (existe no bloco de série); se não, adicionar.

### 5.4 Vitest

Arquivo: `tests/JavaScript/Panel/Schedules/ScheduleSeriesAndFitInForms.test.js`, novo `describe('Remarcar — série')` (reutiliza `RescheduleModal`, `SlotPickerStub`, `SearchSelectStub`, `jsonResponse`, `flushPromises`).

Casos:
1. **Sem série**: `item` sem `series_id` → o fieldset `[data-test="series-reschedule-scope"]` não existe; o POST continua em `reschedule_url` (teste existente de encaixe já cobre o body).
2. **Série com 1 futura**: `series_future_count: 1` → sem rádio; "só esta".
3. **Série com N**: `series_future_count: 3`, `series_id`, URLs → o rádio aparece; o label "Esta e as seguintes (3)" contém o número.
4. **Prévia**: selecionar "following", definir `dateTime`, avançar o debounce (350 ms + `flushPromises`) → `fetch` chamado na URL `.../series/reschedule-preview?date_time=...`; renderiza `old_label → new_label` e `#2/3`.
5. **Conflito**: resposta com `errors: ['Horário ocupado']` → alerta de conflito, botão de confirmar `disabled`; nenhum POST.
6. **Erro de rede/422**: mostra a mensagem e desabilita.
7. **Confirma**: resposta 201 com `{ message, anchor_id, updated }` → `emit('rescheduled', json)`; body contém `date_time` e **não** contém `doctor_id`/`is_fit_in` quando não mudados; contém quando mudados.
8. **Mudou o médico**: body com `doctor_id`; mostra o hint de que vale para todas.
9. **Acessibilidade**: fieldset/legend, inputs com labels, `role="alert"` no erro, `aria-live` na prévia; o botão tem texto/motivo quando bloqueado (não só `disabled`).
10. **Debounce**: duas mudanças rápidas de `dateTime` → uma única chamada (ou a última vence); resposta antiga não sobrescreve a nova (token).

Rodar:

```bash
rtk npm test -- tests/JavaScript/Panel/Schedules/ScheduleSeriesAndFitInForms.test.js
rtk npm test -- tests/JavaScript/Panel/Schedules/ScheduleModalsNativeDismiss.test.js
```

- `npm test` é `vitest run`; o caminho depois de `--` é repassado ao Vitest. Se necessário, usar `rtk npx vitest run tests/JavaScript/Panel/Schedules/ScheduleSeriesAndFitInForms.test.js`.
- Lint/format só nos arquivos tocados (o lint completo tem dívida conhecida):
  ```bash
  rtk npx eslint resources/js/Pages/Panel/Schedules/RescheduleModal.vue \
    resources/js/Pages/Panel/Schedules/ScheduleDetailDrawer.vue \
    resources/js/Pages/Panel/Schedules/Index.vue \
    tests/JavaScript/Panel/Schedules/ScheduleSeriesAndFitInForms.test.js
  rtk npx prettier --check resources/js/Pages/Panel/Schedules/RescheduleModal.vue \
    resources/js/Pages/Panel/Schedules/ScheduleDetailDrawer.vue \
    resources/js/Pages/Panel/Schedules/Index.vue \
    tests/JavaScript/Panel/Schedules/ScheduleSeriesAndFitInForms.test.js
  ```
- Não rodar `npm run build` (side effects em `public/`/`bootstrap/ssr/`).

---

## 6. Fechamento — documentação

### 6.1 `docs/internal/planos/igut-status-implementacao.md`

- Linha 16: `| 1. Agenda e atendimento | 10 | 1 | 0 |` → `| 1. Agenda e atendimento | 11 | 0 | 0 |`.
- Linha 23: `| **Total (52 entregas)** | **41** | **5** | **6** |` → `| **Total (52 entregas)** | **42** | **4** | **6** |` (42+4+6=52).
- Remover a linha 37 (item 7 "Remarcar a série de sessões inteira") da tabela "O que falta"; renumerar os itens 8–11 para 7–10. Conferir que nenhum texto referencia "item 7" por número.
- A linha 65 da tabela de itens: `| Série de sessões (numeração "3/10", cancelar série) | Parcial: não remarca a série inteira |` → `| Série de sessões (numeração "3/10", cancelar série) | Feito |`. A coluna "Onde mexer" pode ficar como está. Depois de remover o item 7 da tabela "O que falta", essa linha desloca uma posição (passa a ~64); localizar pelo texto, não pelo número.
- Não alterar as demais fases; o total fecha 52.
- O doc está untracked no checkout atual; não commitar sem autorização.

### 6.2 `docs/internal/manuais/clinic/secretaria/README.md`

- Linha ~158: acrescentar que o botão **Reagendar** do card também permite remarcar a série inteira, com link para a nova subseção.
- Depois da seção "Série de sessões no detalhe do agendamento" (linha ~203), acrescentar:

```md
### Reagendar a série de sessões

Para mudar o horário de uma sessão ou da série inteira, abra o detalhe do agendamento e clique em
**Reagendar** na sessão desejada (ou use o botão **Reagendar** do card, na agenda do dia).

- **Só esta sessão** — mantém o comportamento antigo: só a sessão escolhida muda.
- **Esta e as seguintes (N)** — desloca a sessão escolhida e as futuras **a partir dela** pelo mesmo
  intervalo. As anteriores (mesmo futuras), passadas, atendidas, em andamento, canceladas e faltas
  ficam como estão.

Se você trocar o **médico** ou marcar/desmarcar **encaixe**, a escolha vale para todas as sessões
afetadas; sem trocar, cada sessão mantém o que já tinha.

Ao escolher a série, o sistema mostra a **prévia** com a data nova de cada sessão e os conflitos
(médico, escala, bloqueio, sala e teto de encaixe). Se qualquer sessão não couber, o botão de
confirmar fica bloqueado e nada é gravado. A numeração ("3/10") e o total da série são preservados;
as sessões antigas aparecem como **Cancelado** com a nota "Reagendado para ...".

> A remarcação da série não envia aviso automático ao paciente; combine o novo horário na recepção.
```

- O manual do médico (`docs/internal/manuais/clinic/medico/README.md`) só cita "série aberta"; não precisa de mudança. Conferir se ele descreve remarcação; se descrever, acrescentar uma frase e linkar a seção da secretaria.
- Não há screenshot novo; a imagem existente da série continua válida. Se a Lupa exigir, abrir issue separada (não bloquear).

### 6.3 Nota do plano e status do floor

- Criar/sincronizar a nota `plano serie-remarcar` com **este arquivo** (byte-a-byte), como no plano ICP.
- `maestri floor status` ao terminar a etapa de planejamento; avisar o Claude Code com `maestri ask "Claude Code" "Plano serie-remarcar entregue em docs/internal/planos/agenda-serie-remarcar-plano.md; frentes Forja/Prisma, contratos, riscos e testes; sem código alterado."`.

---

## 7. Sequência de execução sugerida

1. **Forja — contratos e helpers**: `ScheduleService` (exclusão múltipla, `pending`, reutilizar `lockFitInQuota` público), `ScheduleSeriesService::lockSeries` público, `ScheduleSeriesChangedException`, lang backend.
2. **Forja — prévia**: `previewReschedule` + controller + rota + testes de prévia (incluindo k→k+1 e conflito externo).
3. **Forja — escrita**: `rescheduleFuture` (locks, cancelar-antes-de-criar, nota/log, followReschedule), controller/rota, testes de escrita/perfil/convênio/encaixe/numeração/pós-op.
4. **Forja — unitário e payloads**: `lockSeries` no `reschedule()` unitário, `seriesRows`/`toScheduleRow`/`buildScheduleItems` com count/URLs, testes de payload e de "sem notificação".
5. **Forja — integração**: child + race test; rodar um caminho por vez.
6. **Prisma — modal**: rádio, prévia, payload, acessibilidade; Vitest.
7. **Prisma — drawer/Index**: repassar campos, `anchor_id`, reload do drawer; Vitest de regressão.
8. **Lang**: chaves pt_BR/en + paridade; rodar o teste de paridade.
9. **Docs**: status doc + manual; revisão da Lupa; correções.
10. **Commit** só com "commit autorizado".

> Forja e Prisma podem trabalhar em paralelo depois do passo 1 (contratos). O contrato de resposta é o mesmo; se o backend atrasar, o front pode usar mocks do Vitest. A integração final precisa das duas frentes.

---

## 8. Riscos e mitigação

| Risco | Impacto | Mitigação |
|---|---|---|
| **k→k+1**: a data nova de uma sessão coincide com a antiga da seguinte. | Falso conflito ou unique violation. | Excluir **todos** os ids afetados na validação; cancelar todas as antigas antes de criar; teste específico (caso 5). |
| Índice único do médico não liberar por causa de `origin`/encaixe. | INSERT falha ou passa indevidamente. | O índice ignora cancelados/faltas e encaixes; cancel-first libera as normais. Sessões com `origin` não são copiadas (viram normais), igual ao unitário. Testar normal e fit-in. |
| Deadlock entre lote, unitário, `addSession` e `cancelFuture`. | 500/rollback. | Ordem única: cirurgia → cota → série → linhas; `cancelFuture` série → linhas; unitário cirurgia → cota → série → linha; `addSession` já é cota → série → linhas. Teste de integração com a série travada. |
| Conjunto muda entre prévia e escrita. | Prévia stale; usuário confirma algo diferente. | Reler sob a trava da série e das linhas; se cresceu, o delta mudou ou uma remanescente mudou, 409 "tente de novo"; se só encolheu, seguir com as remanescentes. |
| Sala/bloqueio/escala muda entre a prévia e a escrita. | Nova sessão em conflito. | Revalidar `validateFor` sob as travas antes de cancelar; 422 `series_dates` e rollback. Teste que cria o conflito depois da prévia. |
| Corrida de sala/equipamento com agendamento de fora da série. | Duas reservas no mesmo horário. | `validateFor` revalida sob as travas da série/linhas, mas **não há trava por recurso** no caminho da agenda (nem no unitário): a revalidação é point-in-time. Limitação pré-existente; se o dono exigir garantia, nova frente com advisory `resource|data/hora` em `store`, unitário, bulk e série. |
| Duas sessões afetadas no mesmo turno/dia com encaixe. | Prévia otimista; escrita recusa. | `pending` no `fitInLimitError` da prévia; a escrita conta as novas sequencialmente sob a cota. Testar teto com 2 destinos. |
| `PostOpReturnService::followReschedule` falhar no meio. | Retorno aponta para cancelado ou some. | Mesma transação do unitário; qualquer exceção = rollback de tudo. Teste com D7/D30. |
| Convênio inativado no meio. | Série inteira recusada sem aviso claro. | Prévia mostra o erro por sessão; escrita 422 `covenant_id`; teste. |
| Notificação esquecida/indevida. | Paciente não avisado ou fila inundada. | Decisão D7: sem `NotifyScheduleChangeJob` no lote; `Queue::assertNotPushed`; manual avisa. |
| Front enviar `doctor_id`/`is_fit_in` sempre e mudar sessões sem intenção. | Série com médicos mistos "promovida" ao médico da âncora. | Enviar só se mudou; payload omitido = mantém; teste de body e de série com 2 médicos. |
| Drawer continuar na sessão cancelada. | Usuário vê dado velho. | Resposta com `anchor_id`; `Index.onRescheduled` atualiza `viewScheduleId`; watcher do drawer recarrega. |
| Teste de integração recriar schema sem autorização. | Efeito colateral no `easyeye_test`. | Rodar só com autorização explícita, um caminho por vez, sem `--parallel`; confirmar ambiente antes. |
| `series_future_count` com N+1. | Lentidão na agenda. | Uma query agrupada em `buildScheduleItems`; cálculo em PHP no `seriesRows` (≤ 60). |
| Série aberta (`session_total` null) renumeração. | "3/n" errado. | Copiar `session_number`/`session_total`; não chamar `addSession`/`createSessions`; teste. |

---

## 9. Critérios de aceite

- [ ] "Só esta sessão" continua idêntico (unitário, sem notificação, mesma resposta `new_schedule`).
- [ ] "Esta e as seguintes (N)" só aparece quando há série e `series_future_count > 1`; o N é o número de sessões que serão movidas.
- [ ] A prévia mostra data antiga → nova por sessão, com conflitos; qualquer conflito desabilita o confirmar.
- [ ] O caso +1 semana em série semanal **não** gera falso conflito e grava as 5 (ou N) sem unique violation.
- [ ] Passadas, atendidas, em andamento, canceladas e faltas ficam com `date_time`/`situation`/logs intactos.
- [ ] Todas as antigas ficam Cancelado com nota "Reagendado para :code" e um `ScheduleSituationLog` por sessão; as novas ficam Agendado, com a mesma série/numeração/total, sala e `surgical_case_id`.
- [ ] Conflito em uma sessão = 422 e **nenhuma** escrita (contar linhas e situações).
- [ ] Convênio inativo = 422 `covenant_id`, nada gravado.
- [ ] Perfil médico só move as dele; outra clínica = 404; médico de outra clínica no override = 422.
- [ ] Encaixe respeita o teto sob a cota; normal respeita o índice único; override de médico/encaixe vale para todas, e ausente mantém cada uma.
- [ ] Pós-operatório D7/D30 aponta para os novos agendamentos.
- [ ] Nenhum `NotifyScheduleChangeJob` no lote; `cancelFuture` continua notificando (não regride).
- [ ] 409 de série alterada não grava nada e a mensagem orienta tentar de novo.
- [ ] Drawer continua na sessão nova (`anchor_id`) e recarrega; lista/contadores recarregam.
- [ ] Chaves novas em pt_BR/en; teste de paridade passa com a allowlist da dívida legada (11 chaves pt-only).
- [ ] Status doc: Fase 1 11/0/0; total 42/4/6; item 7 removido e 8–11 renumerados.
- [ ] Manual da secretária descreve a série e o aviso de que não há notificação automática.
- [ ] Pint/ESLint/Prettier nos arquivos tocados; Pest feature/unit e Vitest verdes; integração verde com autorização.
- [ ] Nenhum commit/push sem "commit autorizado".

---

## 10. Decisões em aberto (dono)

**Nenhuma bloqueante.** Duas decisões foram adotadas por padrão e podem ser revertidas sem refazer o desenho:

1. **Aviso ao paciente**: não dispara `NotifyScheduleChangeJob` no lote (igual ao unitário); o manual orienta a recepção a combinar o novo horário. Se o dono quiser aviso, é uma mudança separada (fila, template e idempotência).
2. **Médico/encaixe**: só mudam nas sessões se a recepção alterar o campo; nesse caso, valem para todas as afetadas. Se o dono preferir que o médico da âncora sempre prevaleça, o payload/UI mudam, mas o desenho de locks/transação continua.

Também vale confirmar, sem bloquear: manter o `origin` fora da cópia (igual ao unitário), não atualizar screenshot do manual nesta entrega e aceitar a limitação pré-existente de corrida de sala/equipamento (não há trava por recurso; a revalidação é point-in-time, como no unitário). Se o dono tratar a corrida de sala como bloqueante, ela vira uma frente separada (advisory por recurso em todos os caminhos de agendamento), não um ajuste escondido nesta entrega.

---

## 11. Comandos e ambiente

```bash
# PHP — feature/unit (banco easyeye_test, sem --parallel)
rtk proxy php vendor/bin/pest tests/Feature/Schedules/ScheduleSeriesRescheduleTest.php
rtk proxy php vendor/bin/pest tests/Unit/Lang/SchedulesTranslationParityTest.php
rtk proxy php vendor/bin/pest tests/Feature/Surgery/PostOpReturnsTest.php

# PHP — integração (recria schema; só com autorização, um caminho por vez)
rtk proxy php vendor/bin/pest tests/Integration/ScheduleSeriesRescheduleRaceTest.php

# Estilo PHP
rtk proxy php vendor/bin/pint app/Services/ScheduleSeriesService.php app/Services/ScheduleService.php \
  app/Http/Controllers/SchedulesController.php app/Exceptions/ScheduleSeriesChangedException.php \
  tests/Feature/Schedules/ScheduleSeriesRescheduleTest.php tests/Feature/Surgery/PostOpReturnsTest.php \
  tests/Unit/Lang/SchedulesTranslationParityTest.php tests/Integration/ScheduleSeriesRescheduleRaceTest.php \
  tests/Integration/Support/series_reschedule_child.php

# Front
rtk npm test -- tests/JavaScript/Panel/Schedules/ScheduleSeriesAndFitInForms.test.js
rtk npm test -- tests/JavaScript/Panel/Schedules/ScheduleModalsNativeDismiss.test.js

# Front — lint/format só nos arquivos tocados (o lint completo tem dívida conhecida)
rtk npx eslint resources/js/Pages/Panel/Schedules/RescheduleModal.vue \
  resources/js/Pages/Panel/Schedules/ScheduleDetailDrawer.vue \
  resources/js/Pages/Panel/Schedules/Index.vue \
  tests/JavaScript/Panel/Schedules/ScheduleSeriesAndFitInForms.test.js
rtk npx prettier --check resources/js/Pages/Panel/Schedules/RescheduleModal.vue \
  resources/js/Pages/Panel/Schedules/ScheduleDetailDrawer.vue \
  resources/js/Pages/Panel/Schedules/Index.vue \
  tests/JavaScript/Panel/Schedules/ScheduleSeriesAndFitInForms.test.js

# Não usar `npm run verify` nesta entrega: ele encadeia o build, que mexe em
# public/ e bootstrap/ssr/ sem necessidade para este diff.
```

Pré-requisitos: confirmar `APP_ENV=testing`, conexão local, banco **`easyeye_test`**, nenhum outro escritor; não rodar migrations/seeds como setup; não usar `--parallel`. O teste de integração usa `DatabaseMigrations` e recria o schema — exige autorização explícita.

---

## 12. Checklist de arquivos

**Backend (Forja)**
- [ ] `app/Services/ScheduleService.php` — exclusão múltipla, `pending`, reutilizar `lockFitInQuota` (já público), opcional `rescheduleAttributes`.
- [ ] `app/Services/ScheduleSeriesService.php` — `lockSeries` público, `previewReschedule`, `rescheduleFuture`, helper de campos, `lockSeries` no `cancelFuture`.
- [ ] `app/Http/Controllers/SchedulesController.php` — `seriesReschedulePreview`, `seriesReschedule`, `lockSeries` no unitário, `seriesRows`/`toScheduleRow`/`buildScheduleItems` com count/URLs.
- [ ] `app/Exceptions/ScheduleSeriesChangedException.php` — novo.
- [ ] `routes/web.php` — duas rotas novas.
- [ ] `lang/pt_BR/schedules.php`, `lang/en/schedules.php` — chaves backend/UI.

**Testes PHP**
- [ ] `tests/Feature/Schedules/ScheduleSeriesRescheduleTest.php` — casos 1–17 (pós-op em `PostOpReturnsTest.php`; 409 na integração).
- [ ] `tests/Unit/Lang/SchedulesTranslationParityTest.php` — paridade com allowlist das 11 chaves legadas.
- [ ] `tests/Integration/ScheduleSeriesRescheduleRaceTest.php` + `tests/Integration/Support/series_reschedule_child.php` — corrida.
- [ ] `tests/Feature/Surgery/PostOpReturnsTest.php` — caso D7/D30.

**Front (Prisma)**
- [ ] `resources/js/Pages/Panel/Schedules/RescheduleModal.vue` — rádio, prévia, payload, acessibilidade.
- [ ] `resources/js/Pages/Panel/Schedules/ScheduleDetailDrawer.vue` — repassar `series_id`/count/URLs.
- [ ] `resources/js/Pages/Panel/Schedules/Index.vue` — `anchor_id` → `viewScheduleId`.
- [ ] `tests/JavaScript/Panel/Schedules/ScheduleSeriesAndFitInForms.test.js` — novo `describe`.

**Docs**
- [ ] `docs/internal/planos/igut-status-implementacao.md` — 11/0/0, 42/4/6, item 7 removido/renumerado, linha 65 Feito.
- [ ] `docs/internal/manuais/clinic/secretaria/README.md` — subseção "Reagendar a série de sessões" + nota de aviso.
- [ ] Nota `plano serie-remarcar` sincronizada; floor status atualizado.
