# CLAUDE.md

Guia operacional para agentes (Claude/Codex) neste repositório.

## Contexto do Projeto

**EasyEye** é SaaS multi-tenant para oftalmologia, rodando em **Laravel 12** com UI em **Vue 3 + Inertia.js + Vite**.

Foco atual sistema:
1. Operação clínica completa (pacientes, médicos, agenda, prontuário).
2. Compliance (CFM/LGPD) com trilha de auditoria.
3. Financeiro + faturamento + domínio TISS.
4. Billing multi-gateway e crescimento (parceiros/referral/ativação).

## Stack Atual

1. Backend: PHP 8.4 runtime, Laravel 12.x.
2. Frontend: **Vue 3 (Composition API + `<script setup>`) + Inertia.js + Bootstrap 5 + Tailwind CSS**.
3. Blade só sobrevive como entry-point Inertia (`app/panel-app/guest-app/portal-app`) e templates server-side de PDF (`resources/views/pdf/`).
4. Banco: PostgreSQL via `host.docker.internal` (rodando no host); suporte SQLite/MySQL/MariaDB/SQL Server.
5. Filas/cache: queue default `database`; Redis em container `easyeye_redis`.
6. Observabilidade: Sentry habilitado em `production` e `testing`.
7. Rich-text editor: TinyMCE 8 via wrapper Vue `Components/Panel/TinyMceEditor.vue`.
8. Tempo real: Laravel Reverb (WebSocket) + `@laravel/echo-vue`, escala horizontal via Redis (`REVERB_SCALING_ENABLED`). Progresso de importações (pacientes, médicos, agenda, medicamentos) só por broadcast — `ImportProgressUpdated` + `composables/useImportProgress.js`; não existe endpoint HTTP de status.

## Comandos Comuns

```bash
# Desenvolvimento
composer dev          # server + queue + logs(pail) + vite + reverb (WebSocket)
npm run dev
npm run build

# Banco
php artisan migrate
php artisan db:seed

# Rotas
php artisan route:list
php artisan route:list --json

# Qualidade
./vendor/bin/pint
./vendor/bin/pest
./vendor/bin/pest --filter "nome do teste"
```

## Arquitetura por Camadas

1. Controllers orquestram fluxo HTTP e delegam regra para services.
2. Services concentram regra de negócio e transações.
3. Models + Traits concentram persistência, auditoria, versionamento e assinatura.
4. Enums removem magic strings de status/roles/gates/feature keys.

Pasta-chave de negócio:
1. `app/Services`
2. `app/Domains/Tiss`
3. `app/Services/Billing`
4. `app/Http/Controllers/{Manager,Financial,Api,Partner,Setting}`

## Multi-Tenancy e ACL

### Tenant
1. Isolamento lógico por `entity_id`.
2. Entidade ativa vem da sessão (`selected_entity_id`).
3. Usuário pode participar de múltiplas entidades via `entity_users`.

### ACL
1. Gates em `AuthServiceProvider` usando `EntityGate`.
2. Roles SaaS em `SaasRule`; roles cliente em `ClientRule`.
3. Middleware principal:
- `entity.selected`
- `entity.member`
- `entity.role`
- `partner`
- `check.subscription`
- `feature`

## Mapa de Rotas

1. `routes/web.php`: painel principal (`/panel`), clínico, financeiro, compliance, settings.
2. `routes/manager.php`: painel SaaS manager (`/panel/manager`).
3. `routes/api.php`: integradores (`/api/integrators/*`) e webhook billing.
4. `routes/portal.php`: portal parceiro (`/portal/*`).
5. `routes/auth.php`: autenticação e seleção de entidade.

Estado atual: ~367 rotas registradas.

## Domínios Importantes

### Clínico
1. Pacientes, médicos, agenda, blocks, waiting list, schedule events.
2. Prontuário com documentação, anexos, PDFs e ações rápidas.
3. Catálogos clínicos configuráveis (`panel/setting/*`).

### Compliance
1. `Signable`: trava prontuário após assinatura. `clinical_revision` é o token que a tela de assinar devolve: sobe a cada update do prontuário **e** a cada criar/alterar/excluir de conteúdo clínico filho — documento, anexo, evolução e procedimento (`GuardedBySignedRecord`, mesma transação e mesmo `FOR UPDATE` da linha do prontuário que o `sign()` toma). Com o prontuário assinado, o filho é recusado (403 traduzido). Filho novo que guarda conteúdo clínico: `use GuardedBySignedRecord` (o hash da assinatura cobre só o prontuário, nunca os filhos). Única exceção deliberada: o ciclo de vida do procedimento (`status`/`executed_*`/`notes`, via `signedRecordWritableAttributes()`), porque a lista do paciente executa/cancela o que foi pedido num atendimento antigo já assinado — `MedicalRecordProcedureExecutionService` (tudo em `transitionUnderLock()`) trava a origem, **relê o procedimento com `FOR UPDATE`** e só então confere o status e escreve — nunca valide status na instância carregada antes da trava (duas abas passam as duas e a segunda sobrescreve a primeira) —; com a origem assinada **acrescenta** ao texto do pedido em vez de sobrescrever, sem subir a revisão. Bookkeeping de entrega (ex.: `requesting_doctor_sent_at`) grava com `saveQuietly()` e não invalida a revisão.
2. `Versionable`: snapshot antes de update.
3. `Auditable` + `HasAuditColumns`: trilha CUD.
4. `LogsDataAccess`: rastreio de leitura sensível.
5. Tabelas: `audit_logs`, `record_versions`, `data_access_logs`, `patient_consents`, `lgpd_requests`, `term_versions`, `user_term_acceptances`.

### Financeiro/Billing
1. Fluxo de caixa, faturamento individual/lote, claims e relatórios.
2. Billing multi-gateway com fallback + circuit breaker + webhook idempotente.
3. Gateways suportados: Asaas, InfinitePay, Mercado Pago, Pagar.me, Stripe BR, PagBank.

### TISS
1. Domínio dedicado em `app/Domains/Tiss`.
2. Workflow de guia/lote, geração XML por versão, envio e processamento de retorno.
3. Jobs: `GenerateTissBatchXmlJob`, `SendTissBatchJob`, `ProcessTissReturnJob`.

### Cirúrgico (Fase 4 — `docs/internal/auditorias/adr/0003-modulo-cirurgico.md`)
1. Recurso de plano `has_surgery` (Premium). Código em `app/{Models,Services,Enums}/Surgery`, `routes/surgery.php` (+ `routes/surgery/<etapa>.php`), `config/surgery.php`, `lang/*/surgery*.php`. Gates `ManageSurgery` (admin/médico/secretária), `PerformSurgery` (médico com cadastro ativo) e `ViewSurgeryIndicators`.
2. Caso cirúrgico (`surgical_cases`) é entidade própria, NÃO linha de `schedules`. Estado só muda por `SurgicalCaseService::lock()` + `changeStatus()` dentro da transação do chamador (trava `FOR UPDATE`, relê o estado ATUAL, `revision` é o token da tela → 409). Autorização é log imutável (`surgical_authorization_events`) desnormalizado no caso; a resposta da operadora vale pelos itens da GUIA que a recebeu (`scope` em `SurgeryAuthorizationService::record()`: internação = procedimentos, anexo de OPME = materiais) e o caso só sai de "aguardando" quando nenhum item fica sem resposta (resposta parcial do pedido = anotação no log; `request()` zera as respostas da rodada anterior; o registro manual sem escopo cobre só o que falta). O parser lê os dois envelopes (`autorizacaoServicos` e `recebimentoAnexo/…/anexoOPME`). "Particular" = `Covenant::isParticular()` (paciente sempre tem convênio); `SurgicalCase::requiresInsurer()`.
3. Ordem de locks: advisory dos recursos da janela (sala, médicos, paciente, em ordem crescente) → `FOR NO KEY UPDATE` nos médicos → linha do caso → filhos → estoque (produto → lote). Consulta da Agenda toma `FOR SHARE` na linha do médico e é barrada durante cirurgia agendada. Criação dos retornos pós-op (evento `SurgeryPerformed`, depois do commit) segue a ordem da Agenda e roda FORA da trava do caso. TODO caminho que põe consulta no horário do médico confere a cirurgia sob essa trava (`ScheduleService::assertDoctorFreeOfSurgery`): criar, editar, remarcar (um e em lote — `bulkReschedule` também) e REATIVAR (`changeSituation()`: Cancelado/Faltou/Atendido → situação ativa; só o atendimento do pronto atendimento fica de fora, como na criação). Quem chama `changeSituation()` trata `ScheduleSlotUnavailableException` (422; no lote, linha em `blocked` com `reason` `slot_unavailable`). A grade mensal (`ScheduleAvailabilityService`) desconta a cirurgia pelo INTERVALO da consulta (início + intervalo do médico), não pelo início do horário — igual ao seletor e à validação; bloqueio comum continua valendo pelo início.
4. Documento cirúrgico (TCLE, orientações, termo do implante, alta) = retrato congelado (`SurgeryDocumentIssueService`: texto, cabeçalho, signatário e `content_hash` canônico; reemissão marca a anterior; nunca `MedicalRecordDocumentation`). Cópia assinada pelo paciente = só metadado de arquivo. O TCLE guarda o que afirma (`ConsentBasis`: olho, procedimentos, cirurgião, anestesia se afirmada): mudou depois de emitido → termo "desatualizado", a cópia assinada não vale até reemitir (data, sala, título e LIO não invalidam); a prévia devolve `basis_hash` e a emissão com o pedido já mudado é 409. A trava de alergia das orientações pós-op vale para o documento inteiro (texto livre incluído).
5. Registrar "realizada" (`SurgeryPerformService`) roda `config('surgery.perform_guards')` (Pausa Cirúrgica fechada, TCLE com cópia assinada E que ainda cobre o pedido) mais autorização vigente; cada condição só cede à justificativa do cirurgião (>= 10 caracteres), gravada. Implante é congelado (lote/série/validade/ANVISA copiados, índice único de série ativa), baixa e registro na MESMA transação; estorno via `StockService::reverseConsumption()` (único por consumo; o produto volta pelo custo médio que o consumo gravou e o lote NÃO muda de custo — o `unit_cost` do consumo é o médio do PRODUTO, ponderá-lo no lote o distorcia). Consumo recusa lote vencido.
6. Aba nova na tela do caso: classe `SurgeryShowSection` em `config('surgery.show_sections')` + `resources/js/Components/Surgery/Tabs/<Key>Tab.vue` (props `section, surgicalCase, can, urls, options, t`). **`case` é palavra reservada no template Vue — use `surgicalCase`.** O formulário do caso abre pela regra única `SurgicalCaseStatus::allowsAnyChange()` (cabeçalho, aba Pedido e `SurgicalCaseService::update` concordam; o pedido trava antes via `allowsRequestChanges()`, equipe e parte clínica não). Link para uma aba do caso usa o fragmento `#tab=<chave>` (`PostOpSection::KEY`), nunca `?tab=`. Textos: `lang/*/surgery_<sufixo>.php` chega como `t.<sufixo>` (`SurgeryLang`).
7. Toda tabela nova com dado do paciente entra em `app/Services/Lgpd/SurgeryDataExporter.php`. WhatsApp de autorização é neutro (primeiro nome + clínica), desligado de fábrica por clínica, revalidado no envio. O sistema NÃO calcula potência de LIO.

### Growth
1. Referral codes/events.
2. Parceiros, leads, comissões.
3. Activation score por eventos de domínio.

### Validação clínica e recall (Fase 6)
1. O papel externo é **Revisor Clínico** (`app/Domains/ClinicalReview`, `app/Domains/Recall`) — NÃO é "médico parceiro" (`doctors.partner`/módulo Partner são comerciais). Decisões: `docs/internal/auditorias/adr/0002-validacao-clinica-e-recall.md`; operação: `docs/internal/runbooks/validacao-clinica-protocolos-de-retorno.md`; termos: `docs/internal/glossario.md`.
2. O protocolo é **global** (sem `entity_id`) e a unidade validada é a **versão**: conteúdo em coluna `text` com JSON canônico (`App\Support\CanonicalJson`, hash `1:` + sha256 com domínio — nada de `jsonb`/float/`serialize()`), congelado por trigger do PostgreSQL (só `draft` muda). Seeder/migration só cria `draft` (I8). Texto/hash ficam na transação; `GovernanceLog` só grava DENTRO de transação e falha alto (o `AuditLogger` engole exceção, não serve de prova).
3. Página pública `/revisao-clinica` (`routes/clinical-review.php`, rootView `guest-app`, sem login/tenant): o token só aparece no 1º salto (URL assinada + token → sessão → URL limpa); **GET nunca consome** o convite, o parecer é um POST único sob `FOR UPDATE`; cabeçalhos `ClinicalReviewHeaders` (no-referrer, no-store, noindex); nenhuma prop com dado de paciente/clínica/usuário — **nem CRM/RQE/e-mail do cadastro** (o Revisor DECLARA a identidade e a EasyEye compara; preencher de antemão deixaria o link encaminhado assinar como o convidado); `HandleInertiaRequests` não manda `auth` nessas rotas; rascunho cifrado NO SERVIDOR, nada em `localStorage`; a concordância com a declaração (`attestation.agreed`) vale para o TEXTO que estava na tela — `Show.vue` a zera quando `statement.term_version_id`/`sha256` mudam (volta `statement_changed`, `preserveState` mantém o resto). A paleta fica em `ReviewLayout.vue` (`.review-shell`): o cinza do template (#6c7688) e o `text-bg-secondary` (verde-água + branco, 1,9:1) reprovam AA — use `NEUTRAL_BADGE` (`Support/recallRule.js`) e `review-alert`, nunca `alert-warning`/`text-bg-secondary` nessas telas.
4. Governança: ordem de locks **protocolo → versões (id crescente) → revisor → convite → atestado** (qualquer código novo que escreva versão/convite segue a ordem). Liberar só parte de `validated` e **reconfere** a prova (aceite `accepted` + decisão `approved` + mesmo hash + conteúdo íntegro); aceitar exige CRM/UF **e** RQE conferidos no CFM (o conferido é o declarado); retomar nunca deixa duas `released`. `recall:verify-integrity` (05:15) suspende/retira a versão com hash divergente e FALHA o comando.
5. Testes: `tests/{Feature,Unit}/ClinicalReview`, `tests/Feature/Recall`, `tests/Feature/Manager/{ClinicalReviews,ClinicalReviewAcceptanceHttp,RecallProtocolGovernanceHttp,RecallProtocols}*Test.php`; corrida em `tests/Integration/ClinicalReviewConcurrencyTest.php` e `RecallCatalogConcurrencyTest.php` (processo filho; depois `php artisan migrate:fresh --env=testing --force`); Vitest `tests/JavaScript/ClinicalReview` e `tests/JavaScript/Panel/Manager/RecallProtocols`; contrato de textos `tests/Unit/ClinicalReview/ReviewViewTextsTest.php` + `tests/Unit/Recall/RecallViewTextsTest.php` (toda chave `tx()` existe em pt_BR e en). `tests/JavaScript/ClinicalReview/support/texts.js` é GERADO de `lang/pt_BR` — regere ao mudar os textos.
6. Recall (retornos, `app/Domains/Recall`, feature `has_patient_recall`): `patient_recalls` é **derivado**, nunca fonte. Reavaliado por paciente sob `RecallLocks::patient` (`pg_advisory_xact_lock`), alimentado pela outbox transacional `recall_dirty_patients` (observers `MarksRecallDirty`, gravada na MESMA transação do ato; a governança também grava: `ProtocolGovernanceService::move()` chama `RecallOutbox::markVersion` quando a versão entra/sai de uso — suspensa, aposentada, retomada) e pela conciliação noturna `recall:reconcile` — `saveQuietly()` não marca sujo, quem depende disso fica por conta da conciliação. O cálculo é o núcleo puro `RecallRuleEngine` (sem banco). `rule_suspended` é derivado e chega com atraso: contato/adiar/dispensar/reabrir/"feito fora", o vínculo com o agendamento e o prefill da Agenda conferem também a versão VIVA (`RecallRuleInUse`: adoção ativa, versão em uso, íntegra, igual à adotada). Ordem de locks do agendamento de retorno: cota → paciente/dia → paciente (recall, `RecallLocks::patient`) → INSERT do agendamento (dispara a outbox) → vínculo — `SchedulesController::lockRecallPatient`; o INSERT antes da trava deadlockava com `PatientConditionService` (trava → outbox). Prazos operacionais (a vencer/vencido/perdido) em `config/recall.php`; o intervalo clínico mora SÓ no protocolo validado. AO (`Eye::covers`) cobre qualquer olho (ADR 0002 item 11). Agendados (`routes/console.php`): `recall:evaluate-dirty` (1 min), `recall:reconcile`, `recall:verify-integrity` (05:15), `recall:send-invites`, `recall:requeue-pending`, `clinical-review:expire`. Condição crônica nasce `active` só com CID forte em prontuário ASSINADO ou medicação confirmada; senão `suggested` (não gera convite); confirmar/descartar/dar alta é ato clínico (`DecideRecall`). A clínica só adota versão `released` com a prova completa (`ValidationEvidence`) e a adoção é ato do médico.
7. Convite de retorno por WhatsApp: UMA porta, `RecallInviteGuard` — a prévia, o clique, a rodada automática e o job leem a MESMA decisão, e o envio **revalida** (`stillEligible`): consentimento retirado, SAIR, pausa, versão suspensa, retorno tratado ou retorno que andou para o ciclo seguinte (o paciente voltou: a MESMA linha segue aberta, mas `invite_from`/`lost_from`/`due_at` já não cabem hoje — `withinInviteDates` espelha o SQL de `eligibleRecalls`) entre enfileirar e enviar viram `suppressed` e NÃO saem. Três chaves, todas desligadas de fábrica (`RecallKillSwitch`): pausa global (Manager, lida sem cache), `whatsapp_settings.recall_enabled` (Manager, só com o modelo aprovado na Meta) e `recall_clinic_settings.whatsapp_invites_enabled`. Além das chaves, o PLANO com `has_patient_recall` também é conferido no guard (`InviteBlocker::FeatureOff`, no criar E no enviar — o worker zera o cache de assinatura antes de revalidar): rebaixar o plano não apaga configurações nem retornos, e sem isso o robô seguia convidando de uma tela já bloqueada. Só recebe quem marcou `ConsentType::CareReminders` no cadastro (a autorização é um ATO: `usePatientForm` só manda `care_reminders_consent` quando a pessoa MUDOU a caixa em relação ao que o servidor tem — o valor carregado voltando a cada salvamento restaurava em silêncio um consentimento retirado por SAIR com a tela aberta; essa base e o `form.defaults` avançam para o que foi ENVIADO a cada `savePatient` que deu certo (o agendamento salva o paciente antes do horário e, recusado o horário, a tela fica aberta: marcar, tentar, desmarcar e tentar de novo perdia a revogação, ou o `isDirty` a pulava); SAIR retira (casa o CELULAR, não a pessoa; no app PRÓPRIO só os pacientes da clínica, no número GLOBAL só as clínicas SEM app próprio — `ConsentService::revokeCareRemindersForPhone`); a mensagem é neutra (primeiro nome + clínica — **nunca condição, exame ou motivo**; um teste varre texto e variáveis). Tetos de ritmo (ramp-up 5/10/20, intervalo mínimo ≥ 7 dias, onda por paciente/dia) decididos sob a trava da CLÍNICA e só depois a do paciente. Mensagem `skipped`/`suppressed` nunca pesa nos tetos — a seleção de candidatos da rodada (`RecallInviteService::candidates`) usa a MESMA lista (`RecallInvitePacing::UNCOUNTED_STATUSES`) que o guard; o teto `max_per_run_per_clinic` é de ondas ENFILEIRADAS (a lista de candidatos vem inteira, sem `LIMIT`: quem o guard recusa não ocupa vaga). Operação: `docs/internal/runbooks/convite-de-retorno-whatsapp.md`.
8. Anti-VEGF (`app/Domains/Recall/Intravitreal`): o curso é acoplado ao `markDone` do procedimento, DENTRO da transação dele (prontuário de origem → procedimento → paciente → curso → aplicação); nada é gravado em `medical_record_procedures` além do ciclo de vida já permitido (prontuário assinado é imutável) — tudo vive em `intravitreal_courses`/`intravitreal_applications`. Lote e validade obrigatórios, validade vencida nunca passa; autorização do convênio é POR aplicação (negada/vencida só com `authorization_override` + justificativa gravada); o médico decide o intervalo (2–16 semanas, `config/recall.php`) a cada aplicação ou encerra com motivo. Aplicação de curso NÃO aceita "feito fora" (`RecallActionService`). Série aberta da Agenda (`ScheduleSeriesService`: `every_weeks`, `startOpenSeries`, `addSession`, `session_total` nulo) usa a mesma ordem de locks de `store()` (cota → paciente/dia → linha), com a trava da SÉRIE (`pg_advisory_xact_lock`, `seriesLockKey`) logo depois da cota e ANTES de ler as sessões: `SELECT … FOR UPDATE` só enxerga o snapshot do comando, então duas sessões acrescentadas juntas repetiam o `session_number` (não há índice único). `addSession` aplica `validateFor` também ao encaixe (escala, bloqueio do médico, sala) e depois o teto sob a trava da cota; sessão cancelada/faltada libera a data (não é "data repetida"). A reserva que a aplicação planejada guarda (`schedule_id`) só conta se ainda vale (`ApplicationBooking::isLive`: existe, não excluída, nem cancelada/faltada) — na tela e no `scheduleNext`; a aplicação não é reescrita quando a Agenda cancela, o `schedule_id` velho é trocado pela nova reserva. `scheduleNext` (agendar a próxima na série aberta) toma cota do encaixe da âncora → paciente do recall (`RecallLocks::patient`) → curso → aplicação → série → INSERT: travar curso e DEPOIS aplicação, ao contrário da execução (paciente → aplicação → curso), deadlockava (40P01) e a cota vem antes da trava do paciente como em `store()` com `recall_id`. A âncora (agendamento copiado por `addSession`: paciente, nome, contatos) é SEMPRE do paciente do curso, nas duas buscas (última sessão da série; atendimento da aplicação) — um agendamento que a Agenda passou a outra pessoa nunca serve de modelo. Perfil médico só agenda a próxima a partir de âncora da PRÓPRIA agenda (`CurrentDoctor::canAccessDoctorId`, a regra de `ScheduleRequest`/`SchedulesController::addSeriesSession`): a sessão herda o médico da âncora, então a agenda de outro recusa com `OTHER_DOCTOR_CALENDAR` (422 no painel; na execução a injeção vale e `schedule.ok=false` devolve o motivo) — conferida na âncora ESCOLHIDA sob as travas, antes de tocar a série dela. A reserva da aplicação (`schedule_id`) só vale se for DO PACIENTE além de viva (`ApplicationBooking::live($entity, $patient, $ids)`: a recepção passa o agendamento a outra pessoa SEM cancelá-lo, e a tela do original mostrava "agendada" e `scheduleNext` recusava). A data da próxima aplicação é a da reserva VIVA (`ApplicationBooking::dayOf`) no painel (`forPatient`: `next_due_on`/`days_to_next`/`planned_for`) e no prazo do retorno (`RecallEvaluator::courseItems`): remarcar na Agenda muda só o agendamento, sem escrita nova em aplicação/curso (um observador de agendamento travaria curso/aplicação na ordem errada, ver acima); `planned_for`/`next_due_on` gravados são só o que vale SEM reserva viva (cancelada, faltada, excluída, de outro paciente), e o aviso da execução (`afterExecution`) fala da data marcada. `scheduleNext` avisa o paciente da criação (`NotifyScheduleChangeJob`, `EVENT_CREATED`, `->afterCommit()` de dentro da transação), como o agendamento comum e o `addSeriesSession`. Na tela, gerir o curso (agendar a próxima, encerrar) é `canManageCourse` do `MedicalRecordProceduresModal` (médico + URLs do paciente) e vai ao painel como `canManage` — NUNCA `canWrite`, que inclui `!isLocked`: o curso não é do prontuário e os endpoints não olham a assinatura (teste Pest e Vitest fixam os dois lados).
9. Orientações pós-procedimento (`app/Domains/CareGuidance`): tabelas próprias (`care_guidance_*`), NÃO `MedicalRecordDocumentation` (o guard do prontuário assinado bloquearia emitir depois da assinatura). A emissão é um retrato CONGELADO (título, texto, local, colírios, olho, CÓPIA dos PDFs e hash): editar/apagar o modelo nunca muda o entregue. PDF anexo: cabeçalho `%PDF-` real, ≤ 5 MB, ≤ 5 por modelo (conferido sob a trava do modelo). Clique duplo devolve a emissão que já existe. A limpeza das cópias (`DB::afterRollBack`) é registrada ANTES da 1ª cópia e lê a lista por referência, com o destino entrando nela antes de cada `copy()`: PDF que falta ou cópia que falha no meio do laço desfaz a transação e apaga as anteriores (e o arquivo pela metade); registrada depois do laço, a limpeza nunca existia e cada nova tentativa deixava mais PDFs órfãos.
10. Exportação LGPD (`PatientDataExporter::FORMAT_VERSION = 6`: 5 = recall/Fase 6, 6 = cirurgias/Fase 4 via `SurgeryDataExporter`): `tests/Feature/Lgpd/PatientDataCoverageTest.php` varre o ESQUEMA — toda tabela com FK para `patients` precisa estar classificada como coberta por uma seção da exportação, excluída por motivo que `export_scope.not_included` declara, ou lacuna registrada. Tabela nova com `patient_id` sem classificação REPROVA o teste: crie a seção no exportador (e a chave de texto nos dois idiomas) junto com a migration.

## Providers e Observers

1. `SubscriptionServiceProvider`: observer de entidade + gates de assinatura/feature.
2. `BillingServiceProvider`: registro de gateways, default gateway e circuit breaker.
3. `TissServiceProvider`: transporte mock/http para TISS.
4. Observers ativos:
- `EntityObserver`
- `ActivationObserver`
- `SubscriptionObserver`

## Frontend e Padrões UI

1. Entry-points Inertia: `resources/views/{app,panel-app,guest-app,portal-app}.blade.php`. Cada controller usa `Inertia::render()` apontando para `resources/js/Pages/...`.
2. Layouts Vue: `resources/js/Layouts/{AppLayout,GuestLayout,PortalLayout}.vue`.
3. Páginas por área: `resources/js/Pages/{Site,Auth,Panel,Portal}/...`.
4. Componentes compartilhados: `resources/js/Components/Panel/...` (PageHeader, ActionIconButton, OffcanvasPanel, TinyMceEditor, TablePagination, etc.). `CenteredModal`/`OffcanvasPanel` são o mesmo diálogo (CSS diferente) — contrato de acessibilidade (foco ao abrir/voltar ao fechar, Tab contido, Escape, fundo `inert`, pilha p/ diálogo aninhado) fica em `resources/js/Support/useModalA11y.js`; novo componente de modal deve chamar esse composable em vez de reimplementar (montado com `v-if` e sem prop `open`: `() => true`). Overlay de fora da pilha — SweetAlert2 (confirmações, aviso de sessão), menu teleportado do `ActionDropdown`, UI do TinyMCE — é dono do próprio foco e teclado: o trap só puxa de volta o foco que cai no fundo (`#app`) ou num diálogo de baixo, e ignora tecla vinda deles. Componente próprio que abre POR CIMA de um diálogo (ex.: `PdfPreviewModal`) tem que entrar na pilha chamando o composable, senão o Escape fecha os dois.
5. Estado de formulário: sempre `useForm` do `@inertiajs/vue3`. Multipart precisa `forceFormData: true`.
6. Navegação: `<Link>` para SPA, `router.get/post` para programático, `window.location` apenas para asset/PDF externo.
7. Bundles Vite: `vendor.js` (jQuery + Bootstrap plugins legados), `panel.js` (Inertia app painel), `site.js` (landing + auth + portal).
8. Rich text: usar `<TinyMceEditor v-model="content" />` — wrapper Vue do TinyMCE 8.
9. SCSS modular em `resources/css/system/_*.scss` e `system.scss` como entry.
10. **Não usar Alpine.js, Yajra DataTables ou Blade fora de PDFs/entry-points** — removidos do projeto.
11. Tooltip: basta o atributo `title` (ou `data-bs-title`) — vira o tooltip do template automaticamente, sob demanda (`resources/js/Support/tooltips.js`, ligado em `panel.js`/`site.js`). Não criar `new bootstrap.Tooltip` em componente. `data-bs-placement` muda a posição; `data-tooltip="off"` mantém o nativo. Botão/campo `disabled` com `title` também mostra (o CSS reativa o ponteiro só neles — use `title` para explicar o bloqueio).
12. Emissão que esconde o formulário quando dá certo (ex.: `EyeImageReportModal`, `save()` → `savedResult`) trava TODO controle que entra no payload enquanto `saving`: `<fieldset :disabled="saving">` em volta do formulário, `TinyMceEditor :disabled`, caixas de exames e escritores assíncronos do conteúdo (`extractingPdf`) também. O que é digitado depois que o POST saiu não está nele, o sucesso esconde o editor e, num rascunho, o servidor já o fechou (`Issued`): a correção sumia em silêncio (`isDirty()` dá false depois do sucesso). Vale para qualquer tela nova com esse formato.
13. SSR (Inertia): páginas e layouts também rodam no Node (`resources/js/ssr.js`, `renderToString`), onde NÃO existem `document`, `window` nem `localStorage`. Nada do navegador no `setup` — só em `onMounted`/handlers ou atrás de `typeof document !== 'undefined'`; `watch(..., { immediate: true })` **roda no servidor** (o `ReviewLayout` escrevia o `<html lang>` num watcher imediato e lia `data-app` no setup: `ReferenceError: document is not defined` em toda carga inicial da página do Revisor, que caía no render do navegador ou falhava com `INERTIA_SSR_THROW_ON_ERROR`). Valor que vem do `<html>` (ex.: nome do app) lê-se ao montar, com padrão igual no servidor (sem divergência de hidratação). Prova: `tests/JavaScript/ClinicalReview/ssr.test.js` (`// @vitest-environment node`; o `tests/JavaScript/setup.js` só liga o DOM quando ele existe) — página nova pública/guest com SSR ganha caso ali.

## Convenções de Implementação

1. Evitar lógica de negócio complexa em controller.
2. Em fluxos críticos, usar transação explícita no service.
3. Preservar `entity_id` em queries e policies.
4. Usar enums/gates existentes antes de criar novos status/strings.
5. Em mudanças de prontuário/compliance, validar impacto em assinatura/versionamento/auditoria.
6. Toda nova tela do painel: `Inertia::render('Panel/<Modulo>/<Tela>', $props)` + página Vue equivalente.

## Testes Relevantes para Regressão

Executar ao mexer em ACL/subscription/billing/TISS/compliance:

```bash
./vendor/bin/pest tests/Feature/ClinicalReview tests/Feature/Recall tests/Unit/ClinicalReview tests/Unit/Recall
./vendor/bin/pest tests/Feature/Manager/ClinicalReviewsControllerTest.php tests/Feature/Manager/ClinicalReviewAcceptanceHttpTest.php tests/Feature/Manager/RecallProtocolGovernanceHttpTest.php tests/Feature/Manager/RecallProtocolsControllerTest.php
npx vitest run tests/JavaScript/ClinicalReview tests/JavaScript/Panel/Manager/RecallProtocols
./vendor/bin/pest tests/Feature/CareGuidance tests/Feature/Lgpd tests/Feature/Schedules/OpenSeriesTest.php tests/Feature/Patients/PatientCareRemindersConsentTest.php tests/Unit/CareGuidanceTextsTest.php
npx vitest run tests/JavaScript/Components/Recall tests/JavaScript/Panel/Recall tests/JavaScript/Panel/Intravitreal tests/JavaScript/Panel/CareGuidance tests/JavaScript/Panel/Schedules/OpenSeries.test.js
./vendor/bin/pest tests/Feature/ACL
./vendor/bin/pest tests/Feature/Subscriptions
./vendor/bin/pest tests/Feature/Billing
./vendor/bin/pest tests/Unit/Tiss
./vendor/bin/pest tests/Feature/Tiss
./vendor/bin/pest tests/Feature/Financial/BillingHttpTest.php tests/Feature/Financial/BillingIntegrityTest.php
./vendor/bin/pest tests/Feature/AuditLogTest.php tests/Feature/RecordVersionTest.php
./vendor/bin/pest tests/Feature/Surgery tests/Feature/Stock tests/Feature/Schedules   # módulo cirúrgico (Fase 4)
./vendor/bin/pest tests/Integration/Surgery*.php tests/Integration/PostOp*.php         # concorrência real, um caminho por vez
```

Concorrência real do TISS (conexões PostgreSQL de verdade, processos filhos; `tests/Integration` só roda por caminho explícito e, depois, `php artisan migrate:fresh --env=testing --force`):

```bash
./vendor/bin/pest tests/Integration/TissReturnImportRaceTest.php tests/Integration/TissReturnRedeliveryTest.php tests/Integration/TissReturnResendLockOrderTest.php
./vendor/bin/pest tests/Integration/TissReturnDuringResendTest.php tests/Integration/TissSendDocumentRaceTest.php tests/Integration/TissFailureHandlerRaceTest.php
php artisan migrate:fresh --env=testing --force
```

Concorrência real do recall/anti-VEGF (mesmo regime; `tests/Integration/Recall*ConcurrencyTest.php`, `ClinicalReviewConcurrencyTest.php`, `IntravitrealConcurrencyTest.php`; depois `php artisan migrate:fresh --env=testing --force`).

## Gate de Lint/Formatação (CI)

1. Único workflow de CI: `.github/workflows/integrator-contracts.yml`, roda `scripts/check-integrator-style.py` (nome é histórico — hoje é o gate geral de estilo do repo, não é específico de integrador).
2. Alcance: só arquivos **alterados** no PR (`git diff --name-only --diff-filter=ACMR <base> HEAD`), nunca o repo inteiro.
- PHP sob `app/`, `routes/`, `database/`, `lang/`, `scripts/`, `tests/` → `pint --test` nesses arquivos.
- JS/Vue sob `resources/js/`, `tests/JavaScript/` → `eslint` + `prettier --check` nesses arquivos.
3. Para rodar o equivalente no repo inteiro (antes de abrir PR grande ou depois de um refactor amplo):
```bash
./vendor/bin/pint --test
npx eslint .
npx prettier --check .
```
4. Resíduo legado de lint (pré-existente, não é meta zerar numa tacada): `no-unused-vars` (~51, sobretudo `WorkSchedule.vue`/scripts e2e), `vue/prop-name-casing` + `vue/no-mutating-props` (~38 cada, concentrado em `PatientFormSections.vue` e páginas `Index.vue` geradas por tabela), `no-undef` (~18, `bootstrap`/`route` globais fora do escopo do ESLint em alguns arquivos), `vue/no-v-html` (~12 — candidatos a revisão de segurança, não fix mecânico), `no-irregular-whitespace` (~11), `no-useless-escape` (~5, specs Cypress). Contagem sobe só com código novo tocando esses arquivos — não "cresce sozinha".

## Pontos de Atenção

1. `route:list --columns` não existe no Laravel 12 deste projeto.
2. Docker via Snap tem bind-mount estagnado: edits no host podem não propagar para o container. Fix: `docker compose rm -f -s app node ssr && docker compose up -d`. Migrar para Docker Engine nativo resolve em definitivo.
3. Multi-tenancy por sessão: jobs/CLI precisam resolver entidade explicitamente quando necessário.
4. Ajustes em billing podem afetar webhook idempotency e retry/circuit breaker.
5. Ajustes em templates clínicos afetam emissão de documentação e histórico de versões.
6. PostgreSQL aceita conexões da subnet Docker `172.16.0.0/12` (`pg_hba.conf`). Mudanças na rede podem exigir liberação adicional.
7. Redis em container (`easyeye_redis`) — `.env` deve apontar `REDIS_HOST=redis`.
8. Reverb é processo longo: mudou evento/canal (`routes/channels.php`, `app/Broadcasting`) → `php artisan reverb:restart`. `VITE_REVERB_*` são lidas no build/start do Vite (reiniciar `npm run dev`). Canais privados repetem a regra de acesso da tela — mudou a rota, mude o canal. Configuração do servidor de teste (nginx, cron, `.env`, deploy): `docs/internal/infra/reverb-ambiente-teste.md`.
9. Locks da agenda: tudo que trava vários agendamentos do mesmo paciente/dia (reconciliação de capturas órfãs na transição para Atendido, importação de captura) toma ANTES o lock `Schedule::lockPatientDay()` — só depois qualquer linha de agendamento (`refreshSituationUnderLock`, `UPDATE`). Quem dá UPDATE no agendamento antes de `changeSituation()` chama `ScheduleService::lockAttendanceReconciliation()` primeiro. Sem essa ordem, duas transições simultâneas entram em deadlock. O paciente/dia que valem são os da linha TRAVADA, não os da instância carregada no route binding: `changeSituation(Atendido)` relê paciente/data/médico sob o lock da linha e, se a chave do lock mudou (agendamento remarcado/trocado por outra requisição), desfaz a tentativa — SAVEPOINT solta a linha E o lock — e recomeça com o lock certo, sem segurar linha (`ScheduleService::lockForTransition`; 3 tentativas, depois `ScheduleChangedConcurrentlyException` 409). Reconciliar capturas com a identidade velha deixava o ato duplicado no repasse (agendamento de exame + captura sem vínculo). Ordem ÚNICA dos locks da agenda, em `store()` e `update()`: cota do encaixe (`assertFitInCapacity`) → paciente/dia → linha do agendamento (invertida, criação e edição do mesmo paciente/dia e médico/turno deadlockam). O PUT (`ScheduleService::lockForEdit`) trava a linha e, se o agendamento fica Atendido — a situação pedida ou, se omitida, a do BANCO lida sob a trava, nunca a do route binding (outra requisição pode ter comitado Atendido no meio e a edição deixaria as capturas sem vínculo) —, o paciente/dia de ORIGEM **e** de DESTINO, em ordem crescente de chave (`Schedule::lockPatientDays`), antes da escrita; se a situação ou a identidade lidas sob a trava pedem locks que não foram tomados, desfaz (SAVEPOINT) e refaz: só o destino deixava duas edições opostas (X→Y e Y→X do mesmo paciente) segurando a PRÓPRIA linha e esperando a do outro na reconciliação — deadlock. Quem precisar travar mais de um paciente/dia usa `lockPatientDays`, nunca `lockPatientDay` em sequência. O vínculo exame→agendamento (`patient_exams.schedule_id`) só vale com paciente, dia e médico batendo — regra única em `App\Support\ExamScheduleLink`: editar o agendamento desvincula o que deixou de valer e reconcilia as capturas da identidade nova (`ScheduleService::reconcileExamLinks`), e quem consome o vínculo (repasse, rateio do recebido, âncora do laudo) confere a regra em vez de confiar no `schedule_id`.
10. PostgreSQL (`pdo_pgsql`/libpq) lê o resultado INTEIRO de cada consulta pra memória nativa do processo no `execute()`: `cursor()`/`lazy()` não limitam memória aqui, e `memory_get_peak_usage()`/`memory_limit` não enxergam (o contêiner sim). Coluna grande (ex.: `metadata` de laudo com imagem embutida, até ~27 MiB) se lê uma linha por consulta, pela chave primária, depois de listar só os ids — ver `PatientDataExporter::documentationsByRecord()`; a prova é o RSS do processo (`tests/Integration/PatientDataExportMemoryTest.php`, zera o pico via `/proc/self/clear_refs`), não o pico do PHP.
11. `MedicalRecordService::update()` grava numa instância FRESCA e travada e devolve ela; o `$record` passado não é atualizado em memória. Quem precisa dos valores salvos (ex.: `schedule_id` novo, que decide o Finalizar/Dilatar/Exame do `redirectAfterSave`) usa o retorno.
12. XML TISS e idempotência do envio: a chave de idempotência (`SendTissBatchService::buildIdempotencyKey`) nasce do HASH do arquivo, e o builder grava data/hora de geração no cabeçalho e no hash do epílogo — regerar o MESMO lote um segundo depois dava outra chave, e o lote que a operadora já tinha aceito (ou recebido em dúvida: timeout, worker morto) saía de novo. `GenerateTissXmlService::generateBatch` por isso (a) devolve o XML enviado, sem regerar nem mexer em status, quando o lote está `sending`/`sent`/`acknowledged`/`processed`/`partially_paid`/`paid` (o "Baixar XML" de lote enviado também empurrava lote e guias de volta para "XML pronto") e (b) reaproveita o documento atual que já foi oferecido ao transporte (há `tiss_transmissions` sobre ele) quando o conteúdo é igual — `TissXmlContentFingerprint` ignora só data/hora do cabeçalho e o hash do epílogo; guia retirada/corrigida ou dado cadastral (CNES) mudado gera documento novo. Documento que nunca foi ao transporte continua sendo regerado com o horário de agora. Quem regera XML antes de enviar (`BillingService::submitViaTissWorkflow`) depende disso para ser seguro de repetir; não reintroduza `now()` novo no conteúdo que entra no hash sem passar por `TissXmlContentFingerprint`. Escolher o XML e reivindicar a transmissão no envio (`SendTissBatchService::send`) são dois passos e outra requisição pode regerar o XML entre eles (documento ainda sem transmissão é regerado): a reivindicação (`claimTransmission`) é UMA transação que começa travando a linha do lote (`lockBatchRow`, a MESMA trava da geração) e relê `xml_document_id`/status — XML trocado → nada é gravado e `send()` adota o atual (até 3 escolhas, depois `tiss_send.xml_changed_during_send`); lote cancelado nesse intervalo não sai. O fechamento do envio (`finalizeTransmission`) trava o lote primeiro também: ordem única lote TISS → transmissão → guias (reivindicar pela transmissão primeiro e fechar pelo lote primeiro cruzaria duas tentativas do mesmo lote). ENTREGA EM DÚVIDA × ERRO DEFINITIVO: sem resposta conclusiva da operadora (exceção de transporte, HTTP 408, erro do servidor 500, erro de gateway 502/503/504, timeout) a requisição PODE ter sido processada — a transmissão fica `timeout` e o lote continua `sending` (travado: nada de guia nova/retirada, cancelar ou regerar XML; `GenerateTissXmlService` devolve o XML enviado), e o reenvio retoma a MESMA transmissão/chave/XML mesmo que CNES ou guia tenham mudado entretanto (mudar o dado e reenviar com chave nova entregava o lote de novo a uma operadora que já o tinha aceito). A dúvida SÓ SAI COM O ACEITE da operadora: depois de uma tentativa sem resposta (ou interrompida — `sending` com lease vencido), nenhuma falha seguinte a desfaz — 401, erro HTTP devolvido, endpoint ausente (que nem sai da clínica) —, porque nada disso diz se o 1º POST chegou; olhar só o desfecho da ÚLTIMA tentativa liberava o lote (`error`, editável) e o reenvio saía com XML e chave novos. A retomada que falha mantém transmissão `timeout`, lote `sending` e chave `uncertain`: `reuseExistingTransmission` devolve a dúvida pendente (linha `timeout` ou `sending` vencida), `finalizeTransmission` a respeita (`$priorDeliveryUnresolved`) e `reserveIdempotency` não regrava `uncertain` como `reserved`; a mensagem passa a `tiss_send.delivery_still_unknown`. HTTP 500 é dúvida porque o servidor pode ter gravado o lote e falhado ao montar a resposta — só a recusa EXPLÍCITA do protocolo no corpo (`protocol_status` `rejected`, o `statusProtocolo`/`situacaoProtocolo` "Rejeitado" que o transporte já extrai) faz dele um veredito; consequência assumida: SOAP fault/erro de conteúdo em 500 SEM esse corpo mantém o lote travado até a operadora aceitar. Só erro DEVOLVIDO pela operadora (HTTP 4xx, ou 500 com recusa explícita) ou endpoint ausente numa transmissão SEM dúvida anterior é definitivo (`failed` + lote `error`, editável). `SendTissBatchJob::failed()` e `GenerateTissBatchXmlJob::failed()` não derrubam para `error` lote que já saiu (`GenerateTissXmlService::SENT_STATUSES`). Não há sinal de recusa correlacionado ao envio ORIGINAL no contrato do transporte (a resposta dele se perdeu) nem tela/comando de conciliação manual: o lote só sai da dúvida quando a operadora aceita. REENVIO FORÇADO (`force=true`) de transmissão já CONFIRMADA: com o lote já ENTREGUE (`sent`/`acknowledged`/`processed`/`partially_paid`/`paid`) a confirmação é um fato que o reenvio não reescreve — `claimConfirmedResend` só sobe `attempt` e grava o marcador `metadata.forced_resend.started_at` (lease do reenvio); a transmissão segue `acknowledged` e lote, guias e chave (`completed`) ficam intocados; a falha vai para `metadata.forced_resend.last_failure` + histórico de status (mensagem `tiss_send.forced_resend_failed`) e o sucesso só atualiza a resposta, sem recuar o status do lote/guias (processado, pago) nem um protocolo que já andou. Antes o reenvio reescrevia tudo como "enviando" e, se falhasse, a confirmada virava `failed`, o lote `error` (editável) e a chave `failed`: mudar o dado e gerar o XML de novo saía com chave nova para uma operadora que já tinha o lote. `reserveIdempotency` não reescreve `completed` nem `uncertain`, e `SendTissBatchJob::failed()` não derruba lote entregue para `error`. Lote em `error` por rejeição via retorno (a operadora o recusou) continua reenviável à força: sucesso → `sent`, falha → `error`. O `failed()` dos jobs (`SendTissBatchJob`, `GenerateTissBatchXmlJob`) decide e escreve na MESMA transação, com a linha do lote travada — `RecordTissBatchFailureAction`, FOR UPDATE, a trava da reivindicação/fechamento do envio, da geração do XML e das edições do faturamento: ler o status sem trava e gravar `error` depois deixava uma sessão que comitasse `sending`/`sent` no intervalo ter o status sobrescrito (lote editável, XML e chave novos, com a entrega original talvez já na operadora). Só lote que ainda não saiu vai a `error`; `sending` apenas registra o motivo (envio) e lote enviado..pago ou `cancelled` nunca é tocado. Não escreva status de lote TISS a partir de um retrato lido sem trava. Ordem de locks do TISS: a linha do LOTE é sempre a 1ª (lote → transmissão → protocolo → guias). O processamento do retorno (`ProcessTissReturnService`) a toma ANTES de glosas, protocolo e guias e relê tudo depois dela: o reenvio forçado de transmissão confirmada atualiza o protocolo já existente com o lote travado, e o processamento que atualizava o protocolo e só depois o lote dava deadlock (40P01) — o PostgreSQL abortava um dos dois (`tests/Integration/TissReturnResendLockOrderTest.php`). Todo código novo que escreva protocolo/guia/glosa de um lote trava o lote primeiro. A requisição ao transporte NÃO segura trava: um retorno processado nesse intervalo (lote em `enviando` → processado/parcialmente pago/confirmado, guias pagas, protocolo aceito) é o desfecho MAIS NOVO da operadora. Por isso `finalizeTransmission` relê o lote sob a trava (`refresh`) e, se ele ficou entregue (`DELIVERED_BATCH_STATUSES`), só registra o resultado do reenvio (`finalizeConfirmedResend`: transmissão confirmada se já houve aceite, senão `timeout`; protocolo sem recuar; chave `completed`) — falha não devolve o lote a `error` (editável, XML/chave novos) e sucesso não o rebaixa, nem as guias, para `sent`. A reivindicação segue a mesma regra: lote entregue por retorno (inclusive depois de uma entrega em dúvida) não é retomado nem rebaixado por um reenvio — sem `force` devolve a transmissão (`ALREADY_SENT`), com `force` só registra (`claimConfirmedResend`); a tentativa 'sending' interrompida de um lote assim é encerrada (`settleInterruptedAttempt`). Quem decide o status do lote depois de uma chamada externa relê o lote sob a trava antes de gravar. Lote CANCELADO nunca é regerado nem enviado: `GenerateTissXmlService::generateBatch` o recusa (`TissBatchCancelledException`, a mesma do envio) sob a trava e ANTES de gravar XML, status ou guias — o rollback da transação não desfaz o arquivo já gravado no disco. Com os workers de `tiss-xml`/`tiss-send` ligados, jobs enfileirados antes do cancelamento rodam depois: sem essa recusa regeravam o XML das guias canceladas, devolviam o lote a `xml_ready` e o envio seguinte (sem nenhum sinal do cancelamento) transmitia o que o usuário cancelou. `GenerateTissBatchXmlJob` e `SendTissBatchJob` tratam lote cancelado como "nada a fazer" (pré-checagem + captura da exceção): sem retentativas nem relatório de falha, pois cancelar é ação legítima. Transporte REAL exige XML validado contra o XSD (`SendTissBatchService::assertDeliverable`: status `validated`/`signed` + `metadata.schema`), mas SÓ quando uma requisição vai sair: a guarda roda dentro da reivindicação (`claimTransmission` antes de criar a transmissão, `reuseExistingTransmission` depois de decidido que não é `ALREADY_SENT` nem `IN_FLIGHT`). Exigi-la antes de decidir (em `selectXmlDocument`) trancava a retomada de entrega em dúvida e o "tentar de novo" de envio já concluído de XML anterior à guarda — o validador antigo procurava um `ansTISS.xsd` que nunca existiu e gravava `validated` com `metadata.schema=null`, e lote enviando/enviado não regera o XML. XML sem a evidência é revalidado NO LUGAR (`revalidateInPlace`): o MESMO `xml_content` contra o XSD da versão do lote, com a evidência gravada no próprio documento (`metadata.schema`/`revalidated_at`, `validated_at`, `validation_errors`) antes de a requisição sair — bytes, `file_hash`, id e portanto a chave de idempotência ficam intactos. XML que o XSD rejeita (ou malformado) continua barrado sem tocar o documento; sem como resolver o XSD, segue o bloqueio "validação efetiva". Nunca regere o XML de um lote enviando/enviado para "consertar" a evidência: muda o hash, a chave e a operadora recebe o lote de novo. Retorno da operadora processado é FINAL e o processamento é IDEMPOTENTE: `ProcessTissReturnJob` é entregue "pelo menos uma vez" (worker morto depois do commit e antes de confirmar, duplo despacho), então `ProcessTissReturnService::process` trava o lote e, DEPOIS, a linha do retorno (`FOR UPDATE`; ordem lote → retorno → protocolo → guias), relê o retorno e, se ele já está `processed`, devolve sem escrever nada — o efeito financeiro e a marca `processed` ficam na MESMA transação. Sem isso `ProcessGlosaReturnService` (o upsert da glosa é idempotente, o ACRÉSCIMO em `guide.denied_amount` não) dobrava a glosa (50 → 100, pago 100 → 50) e a reentrega de um retorno VELHO regredia protocolo, lote e guias por cima do desfecho de um mais novo. Só `processed` é pulado: `received`/`parsed`/`error` seguem processáveis (a tentativa que falha dá rollback inteiro). `ProcessTissReturnJob::failed()` lê e escreve sob a mesma trava e NUNCA rebaixa um retorno `processed` para `error` (reabriria o retorno para a próxima soma). Nunca chame `ProcessGlosaReturnService::process` fora desse guarda. A importação também é idempotente por ARQUIVO: `ReceiveTissResponseService::receive` (único criador de retorno) identifica o arquivo por (clínica, operadora, SHA-256 dos bytes — o `file_hash` do documento `return_xml`) e, sob `pg_advisory_xact_lock` tomado como 1ª instrução da transação e antes do `Storage::put` (`importLockKey()`), devolve `ReceivedTissReturn`: retorno do arquivo já `processed` volta com `alreadyImported` sem gravar/travar/despachar nada (a tela mostra erro do campo `xml_file`, não flash — flash fecha o modal), e `received`/`parsed`/`error` é RETOMADO (o mesmo retorno é processado, nunca o 2º). Antes o mesmo XML importado 2x somava a glosa 2x (150,50 → 301,00). Não transforme `ProcessGlosaReturnService` em delta por glosa (a chave do upsert tem `return_id`); `process()` e o despacho ficam FORA da transação de `receive()` e `receive()` nunca roda dentro de transação que já segure lote/protocolo (deadlock). O arquivo em disco é nomeado pelo hash do conteúdo (`tiss/{clínica}/returns/{sha256}.xml`), nunca por texto do XML (um `numeroProtocolo` com `..` escreveria fora do prefixo da clínica). Limites: identidade por bytes exatos (reexportado com data/hora nova soma de novo); o arquivo NÃO é conferido com a operadora do convênio escolhido (importar pelo convênio errado grava retorno e protocolo na operadora errada e, se o `numeroLote` casar com um lote dela, mexe em lote/guias; os mesmos bytes sob outro convênio são OUTRO arquivo de propósito e não há tela para desfazer); retorno `processed` órfão não é reimportável; duplicatas já gravadas não são curadas (consulta no README do domínio Tiss).
13. Importação de exame externo (Gerenciador de Imagens): a origem é POR ARQUIVO — `files_source[i]` (`external_import` ou `browser_capture`), índice-alinhado com `files` (como `files_laterality`); ausente/vazio/inválido vale `external_import`, que NÃO entra no repasse. Só `browser_capture` conta como produção da clínica (`DoctorPayoutProductionService`) e só ele recebe `schedule_id` (vínculo ao agendamento atendido). O campo único `source` do lote (formulário antigo) é recusado com 422: uma foto da câmera no lote marcava o scan externo como captura (produção pagável), e desativar a captura de verdade depois não tirava o scan do repasse. Lotes importados antes desta regra com upload + câmera juntos ficaram todos como `browser_capture` — não há como separá-los depois.
14. Laudo de imagem para o médico solicitante (e-mail com link público): só sai com o prontuário dono ASSINADO (`signed_at` + `signature_hash`, não só `is_locked`) — `MedicalRecordDocumentation::isExternallyDeliverable()`, a MESMA exigência do compartilhamento com o paciente. Três pontos, sempre juntos: o envio (`EyeImageReportResendController`, único caminho de envio; 422 enquanto não assinado), a leitura pelo link (`ExternalMedicalReportController`; 404 enquanto não assinado) e a emissão (`EyeImageReportController::store`, que só GRAVA o destinatário e devolve `requesting_doctor_awaiting_signature`). O laudo nasce DENTRO de um prontuário ainda editável (`GuardedBySignedRecord` recusa documento novo em prontuário assinado), então mandar o link na emissão entregava a um terceiro um laudo que a clínica ainda podia alterar ou excluir; entregue ⇒ assinado ⇒ imutável (`destroy()`/`delete()` recusam). O 1º envio acontece na lista de Documentações do prontuário (botão habilitado só com `is_signed`). Quem criar outro canal de saída de documento clínico (e-mail, WhatsApp, link) herda a mesma regra. O que o laudo entregue imprime também é imutável: o resumo dos exames (`ReportExamSummary`) é CONGELADO na emissão em `metadata.exam_summary` — valores brutos (data ISO, código do olho), localizados só ao imprimir —, nunca remontado dos exames ao vivo (trocar a lateralidade, mesclar/dividir ou renomear o exame depois de assinar imprimia, por exemplo, OE ao lado da imagem OD já congelada; nem `clinical_revision` nem o hash da assinatura enxergam isso). Todo ponto de emissão que vincula exames (`syncExams`: laudo manual em `EyeImageReportController::store`, laudo de IA em `AiRunDocumentationService`) grava o retrato; documento emitido antes do congelamento cai no resumo ao vivo.
15. Banco de teste do módulo cirúrgico: `tests/Integration/*` e `RefreshDatabase` fazem `migrate:fresh` — duas sessões no mesmo banco colidem (deadlock/"relação não existe"). Esta frente usa o banco dedicado `easyeye_test_cirurgico` (a variável de ambiente `DB_DATABASE` é exportada por um script, não digitada no comando: o hook `guard-test-databases.py` bloqueia o nome no texto). Sem paralelismo de workers.
16. Sessão (`config/session.php` → `connection`): o mesmo nome significa coisas diferentes por driver — conexão REDIS (`config/database.php` → `redis`) no driver `redis`, conexão de BANCO (`connections`) no driver `database`. `SESSION_CONNECTION` manda nos dois; sem ela, só o driver `redis` ganha o padrão `REDIS_SESSION_CONNECTION` (`session`). Aplicar o padrão redis ao driver `database` apontava a sessão para uma conexão de banco inexistente ("Database connection [session] not configured") e derrubava toda requisição com sessão — coberto por `SessionCookieSecurityTest` (boot real em subprocesso).

<!-- rtk-instructions v2 -->
# RTK (Rust Token Killer) - Token-Optimized Commands

## Golden Rule

**Always prefix commands with `rtk`**. If RTK has a dedicated filter, it uses it. If not, it passes through unchanged. This means RTK is always safe to use.

**Important**: Even in command chains with `&&`, use `rtk`:
```bash
# ❌ Wrong
git add . && git commit -m "msg" && git push

# ✅ Correct
rtk git add . && rtk git commit -m "msg" && rtk git push
```

## RTK Commands by Workflow

### Build & Compile (80-90% savings)
```bash
rtk cargo build         # Cargo build output
rtk cargo check         # Cargo check output
rtk cargo clippy        # Clippy warnings grouped by file (80%)
rtk tsc                 # TypeScript errors grouped by file/code (83%)
rtk lint                # ESLint/Biome violations grouped (84%)
rtk prettier --check    # Files needing format only (70%)
rtk next build          # Next.js build with route metrics (87%)
```

### Test (60-99% savings)
```bash
rtk cargo test          # Cargo test failures only (90%)
rtk go test             # Go test failures only (90%)
rtk jest                # Jest failures only (99.5%)
rtk vitest              # Vitest failures only (99.5%)
rtk playwright test     # Playwright failures only (94%)
rtk pytest              # Python test failures only (90%)
rtk rake test           # Ruby test failures only (90%)
rtk rspec               # RSpec test failures only (60%)
rtk test <cmd>          # Generic test wrapper - failures only
```

### Git (59-80% savings)
```bash
rtk git status          # Compact status
rtk git log             # Compact log (works with all git flags)
rtk git diff            # Compact diff (80%)
rtk git show            # Compact show (80%)
rtk git add             # Ultra-compact confirmations (59%)
rtk git commit          # Ultra-compact confirmations (59%)
rtk git push            # Ultra-compact confirmations
rtk git pull            # Ultra-compact confirmations
rtk git branch          # Compact branch list
rtk git fetch           # Compact fetch
rtk git stash           # Compact stash
rtk git worktree        # Compact worktree
```

Note: Git passthrough works for ALL subcommands, even those not explicitly listed.

### GitHub (26-87% savings)
```bash
rtk gh pr view <num>    # Compact PR view (87%)
rtk gh pr checks        # Compact PR checks (79%)
rtk gh run list         # Compact workflow runs (82%)
rtk gh issue list       # Compact issue list (80%)
rtk gh api              # Compact API responses (26%)
```

### JavaScript/TypeScript Tooling (70-90% savings)
```bash
rtk pnpm list           # Compact dependency tree (70%)
rtk pnpm outdated       # Compact outdated packages (80%)
rtk pnpm install        # Compact install output (90%)
rtk npm run <script>    # Compact npm script output
rtk npx <cmd>           # Compact npx command output
rtk prisma              # Prisma without ASCII art (88%)
```

### Files & Search (60-75% savings)
```bash
rtk ls <path>           # Tree format, compact (65%)
rtk read <file>         # Code reading with filtering (60%)
rtk grep <pattern>      # Search grouped by file (75%). Format flags (-c, -l, -L, -o, -Z) run raw.
rtk find <pattern>      # Find grouped by directory (70%)
```

### Analysis & Debug (70-90% savings)
```bash
rtk err <cmd>           # Filter errors only from any command
rtk log <file>          # Deduplicated logs with counts
rtk json <file>         # JSON structure without values
rtk deps                # Dependency overview
rtk env                 # Environment variables compact
rtk summary <cmd>       # Smart summary of command output
rtk diff                # Ultra-compact diffs
```

### Infrastructure (85% savings)
```bash
rtk docker ps           # Compact container list
rtk docker images       # Compact image list
rtk docker logs <c>     # Deduplicated logs
rtk kubectl get         # Compact resource list
rtk kubectl logs        # Deduplicated pod logs
```

### Network (65-70% savings)
```bash
rtk curl <url>          # Compact HTTP responses (70%)
rtk wget <url>          # Compact download output (65%)
```

### Meta Commands
```bash
rtk gain                # View token savings statistics
rtk gain --history      # View command history with savings
rtk discover            # Analyze Claude Code sessions for missed RTK usage
rtk proxy <cmd>         # Run command without filtering (for debugging)
rtk init                # Add RTK instructions to CLAUDE.md
rtk init --global       # Add RTK to ~/.claude/CLAUDE.md
```

## Token Savings Overview

| Category | Commands | Typical Savings |
|----------|----------|-----------------|
| Tests | vitest, playwright, cargo test | 90-99% |
| Build | next, tsc, lint, prettier | 70-87% |
| Git | status, log, diff, add, commit | 59-80% |
| GitHub | gh pr, gh run, gh issue | 26-87% |
| Package Managers | pnpm, npm, npx | 70-90% |
| Files | ls, read, grep, find | 60-75% |
| Infrastructure | docker, kubectl | 85% |
| Network | curl, wget | 65-70% |

Overall average: **60-90% token reduction** on common development operations.
<!-- /rtk-instructions -->