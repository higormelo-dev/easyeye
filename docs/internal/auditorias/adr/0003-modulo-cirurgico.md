# ADR 0003 — Módulo cirúrgico oftalmológico (Fase 4)

Status: aceito (15/10/2026). Origem: Plano de Melhorias EasyEye, Fase 4 (comparação com o iGUT Clínicas).
Escopo: pré-operatório → autorização → documentos → agenda cirúrgica → cirurgia e rastreabilidade → pós-operatório e alta → indicadores.

## 1. Contexto (o que o código tinha antes)

- `SurgeryType` é só um catálogo de 14 categorias; nada o referencia. `SurgerySchedulingDocService` gera receitas pré/pós de catarata como `MedicalRecordDocumentation` — presas ao prontuário (o guard do prontuário assinado recusa documento novo).
- Estoque: `entity_products.is_opm`/`requires_lot`, `stock_lots` (lote + validade), `stock_movements` (ledger imutável). **Não há série**, registro ANVISA, reserva, estorno de consumo, nem conferência de validade na baixa. A ponte de LIO (`IolLensStockBridgeService`) cria produto `is_opm` sem `requires_lot`.
- TISS: só `guiaConsulta` e `guiaSP-SADT`. O builder manda "todo o resto" para `appendSadtGuide` (tipo novo sairia como SP-SADT sem erro). A **solicitação de internação não viaja em `loteGuias`** no padrão 4.03: é `prestadorOperadora/solicitacaoProcedimento` (`SOLICITACAO_PROCEDIMENTOS`); o anexo de OPME avulso é `loteAnexos` (`ENVIO_ANEXO`).
- Agenda: `schedules` não tem duração; o conflito de sala é por igualdade exata de `date_time`, validado fora da transação e sem trava. Só existe `doctor_id` (sem equipe). Não existe hospital.
- Este módulo vive no branch `feature/surgical`, a partir do commit `0485d67f`: **não depende da Fase 6** (recall, intravitreal, `CareGuidance`, série aberta, exportador v5). Tudo que o ADR chama de "molde `CareGuidance`" foi implementado aqui por conta própria (`surgery_document_issues`) e a unificação com a Fase 6 fica como refatoração posterior, sem mudar o contrato dos dados. A Fase 6 também não tem `care_locations`: hospital é conceito novo.

## 2. Decisões

**D1. Cirurgia é entidade própria (`surgical_cases`), não linha de `schedules`.** Uma linha de `schedules` viraria "consulta" no WhatsApp de confirmação/pesquisa, item duplicado no repasse (`DoctorPayoutProductionService` conta todo Atendido), atendimento ambulatorial no BI e disparo do lock paciente/dia. Os **retornos** (D1/D7/D30) continuam `schedules` normais (`PostOpEvaluation`), apontando para `surgical_case_id`: são consultas de verdade e entram em repasse/WhatsApp/BI corretamente.

**D2. Hospital e sala são tabelas novas** (`surgery_facilities`, `surgery_rooms`). Sala própria pode apontar para um `ClinicResource` (reaproveita escala semanal e bloqueios); sala de hospital é só da cirurgia.

**D3. Conflito de sala/cirurgião/paciente é por INTERVALO** (`scheduled_start`–`scheduled_end`), decidido dentro da transação sob `pg_advisory_xact_lock` por recurso, em ordem crescente de chave. Sem `EXCLUDE USING gist` (exigiria `btree_gist` e privilégio no banco; o projeto já resolve concorrência por advisory lock + teste com processo filho). Bloqueios (`resource_blocks`) e escala (`resource_work_schedules`) da sala própria são checados pelo INTERVALO inteiro, não só pelo início.

**D4. Documentos fora do prontuário são retratos congelados** (`surgery_document_issues`): texto copiado, `content_hash` = SHA-256 do JSON canônico (chaves ordenadas, `App\Support\Surgery\CanonicalHash`), linha imutável (o model recusa alterar conteúdo), PDF montado só do retrato, dedupe de clique duplo (mesmo tipo + mesmo hash do caso em 10 minutos devolve a emissão existente). Nunca `MedicalRecordDocumentation` (o guard do prontuário assinado recusa documento novo). Tipos: TCLE, orientação pré-operatória, termo/etiqueta do implante, orientação/colírios pós-operatórios e alta. Reemissão não edita: cria nova linha e marca a anterior `superseded_by_id`.

**D5. Rastreabilidade de implante é tabela filha congelada** (`surgical_implants`), gravada na MESMA transação da baixa de estoque: copia lote, validade e série (nunca referencia só o `stock_lot_id`, porque `ProductLotsController::update` edita lote/validade retroativamente). Lote e validade vigente são **obrigatórios e conferidos** na cirurgia (validade vencida nunca passa); série obrigatória para LIO; unicidade `(entity_product_id, serial_number)` entre implantes ativos.

**D6. Estoque ganha estorno e conferência de validade, sem tocar no que existe.** Novo `StockMovementType::ConsumptionReversal` (+1) com `stock_movements.reversal_of_id` único (idempotência do estorno), método `StockService::reverseConsumption()` sob a mesma trava produto → lote. Reserva rígida **não** entra: "LIO planejada" é demanda calculada (planejadas nos próximos dias × saldo), exibida como aviso — sem mudar o razão de estoque.

**D7. Recurso de plano superior por chave de recurso** (`FeatureKey::HasSurgery` = `has_surgery`): Premium (e cortesia). Backfill dos planos existentes por migration, como `has_patient_recall`.

**D8. Papéis e gates.** `ManageSurgery` (admin, médico, secretária — pedido, autorização, agenda, documentos), `PerformSurgery` (ato médico: plano cirúrgico, descrição, avaliação pós-op, alta; exige `CurrentDoctor::activeFor`), `ViewSurgeryIndicators` (admin, financeiro, médico — o médico só vê os próprios números).

**D9. Autorização é um log de eventos imutável** (`surgical_authorization_events`); o estado corrente do pedido é desnormalizado em `surgical_cases` sob a trava da linha. Parcial lista os itens autorizados; negada exige motivo; número/senha/validade ficam no evento. Vencida é calculada pela validade (e por job diário que grava o evento `expired`). **A resposta vale pelos itens que cobre** (`scope`): a resposta de UMA guia (internação = procedimentos; anexo de OPME = materiais) só autoriza os itens dela, e o caso só deixa "aguardando" quando não sobra item sem resposta — antes disso a resposta entra no log como anotação com número, validade e itens (o aviso ao paciente e os indicadores contam o desfecho do caso uma vez). Número e senha do caso somam os das respostas da rodada; vale a MENOR validade. Rodada nova (`request()`, ou renovação de autorização vencida) zera as respostas anteriores. Registro manual sem escopo cobre o que ainda não foi respondido.

**D10. Checklist de cirurgia segura (OMS) em 3 fases** — Entrada (Sign In), Pausa Cirúrgica (Time Out), Saída (Sign Out) — com itens oftalmológicos (olho marcado conferido com pulseira/termo, LIO conferida, alergias, jejum quando aplicável). Catálogo versionado em `config/surgery.php`; respostas gravadas com chave+versão+texto; fase fechada fica imutável com hash. **Registrar a cirurgia como realizada exige Pausa Cirúrgica fechada**; exceção só do cirurgião, com justificativa gravada.

**D11. TISS:** novos tipos `InpatientRequest` e `OpmeAttachment`; dispatch do builder passa a ser por `match` **com erro explícito** para tipo desconhecido (fim do "cai em SP-SADT"); builders próprios para os envelopes `SOLICITACAO_PROCEDIMENTOS` e `ENVIO_ANEXO`, validados contra o XSD 4.03.00; pré-validação por tipo; `TissGuideLink` passa a ligar internação ↔ anexo; retorno `autorizacaoServicos`/`senha` vira evento de autorização. Envio pelo transporte existente em serviço próprio e simples (uma transação por guia; sem lote), sem retentativa automática: timeout fica "envio em dúvida" e só reenvia por ação explícita. PDF imprimível da guia e do anexo (para portal da operadora).

**D12. Pós-operatório:** frequência `offsets` (dias a partir da cirurgia) na série da Agenda (implementada aqui, em `ScheduleSeriesService`); retornos nascem `PostOpEvaluation`, sem sala cirúrgica, apontando para o caso. Avaliação estruturada (`surgical_postop_evaluations`: AV, PIO, reações, refração residual) por marco. Alta é transição do caso (exige avaliação D1 e a decisão do cirurgião) e emite documento congelado.

**D13. Indicadores** em `SurgeryIndicatorsService` (agregados, cache curto por clínica/período): taxa de autorização, tempo até a cirurgia, cancelamentos, motivos de negativa e **erro refracional previsto × obtido** por cirurgião (ME, MAE, % dentro de ±0,50 e ±1,00 D).

**D14. LGPD:** toda tabela nova com `patient_id` entra no `PatientDataExporter` (próxima versão do contrato, a partir da versão vigente neste branch) e na classificação do teste de cobertura. Mensagem de WhatsApp da autorização é neutra (primeiro nome + clínica; nunca procedimento, olho ou motivo) e só sai com opt-in, kill-switch por clínica desligado de fábrica e revalidação no envio.

## 3. Modelo de dados (resumo)

| Tabela | Papel |
|---|---|
| `surgery_facilities` | hospital / centro cirúrgico / clínica própria (CNES, código na operadora) |
| `surgery_rooms` | sala por local; `clinic_resource_id` opcional |
| `surgical_cases` | o caso (paciente, cirurgião, olho, tipo, técnica, anestesia, regime, local, sala, janela, status, CIDs, retrato de segurança clínica, estado de autorização) |
| `surgical_case_procedures` | procedimentos TUSS com quantidade e via de acesso |
| `surgical_case_materials` | OPME solicitada (produto, ANVISA, fabricante, ref., justificativa) |
| `surgical_case_iol_plans` | LIO planejada (modelo, potência, fórmula usada, refração-alvo; **não calcula**) |
| `surgical_case_exams` | biometria e exames vinculados (`patient_exam_id`, mesma clínica/paciente/olho) |
| `surgical_authorization_events` | histórico imutável da autorização |
| `surgical_team_members` | cirurgião, auxiliar, anestesista, instrumentador, circulante |
| `surgical_checklist_entries` / `surgical_checklist_phases` | respostas e fechamento por fase |
| `surgical_descriptions` (+ `surgical_description_addenda`) | descrição cirúrgica rascunho → finalizada (hash), correção só por adendo |
| `surgical_implants` | implante congelado (lote, série, validade, ANVISA, potência, movimento de estoque) |
| `surgical_postop_evaluations` | avaliação estruturada por marco |
| `surgery_document_issues` | TCLE, termo/etiqueta do implante, alta (retrato congelado + hash) |
| `surgery_consent_templates` | modelos de TCLE por tipo de cirurgia |
| `tiss_guides` (`inpatient_request`, `opme_attachment`) | guias novas; `tiss_guide_links` |

## 4. Máquina de estados do caso

`draft → awaiting_authorization → authorized | partially_authorized | denied`; `authorized/partially_authorized → scheduled → performed → discharged`; `cancelled` e `suspended` a partir de qualquer estado não final; `denied → awaiting_authorization` (novo pedido/recurso, gera evento). Cada transição: trava a linha do caso (`FOR UPDATE`), relê, valida o estado ATUAL (nunca o da instância carregada), grava `status_history`, tudo na mesma transação.

## 5. Ordem de locks (qualquer código novo segue)

1. `pg_advisory_xact_lock` dos recursos da janela (sala, médicos da equipe, paciente), em ordem crescente de chave;
2. linhas dos médicos da cirurgia: `FOR NO KEY UPDATE`, id crescente (`SurgeryScheduleService::lockDoctorRows`);
3. linha de `surgical_cases`;
4. filhos do caso (id crescente);
5. estoque: produto → lote (a ordem do `StockService`).

Marcar realizada trava caso → implantes → estoque. Reagendar toma os advisory locks ANTES da linha. A criação de retornos (Agenda) acontece FORA de qualquer trava do caso e usa a ordem da Agenda (cota → paciente/dia → linha).

**Lado da Agenda de consultas.** Todo caminho que grava um horário de médico toma `FOR SHARE` na linha dele (`SurgeryScheduleService::lockDoctorWindows`, via `ScheduleService::assertDoctorFreeOfSurgery`) ANTES da cota do encaixe, do paciente/dia e da linha do agendamento, e confere a cirurgia sob essa trava: criar, editar, remarcar (um e em lote) e reativar (`changeSituation()`, Cancelado/Faltou/Atendido → ativa). Consultas do mesmo médico não se bloqueiam entre si (`FOR SHARE` × `FOR SHARE`); esperam e são esperadas só por quem agenda cirurgia dele. Não há ciclo porque o agendamento cirúrgico só LÊ `schedules`, sem trava. Na reativação, se a instância estava velha e a reativação só se revela depois da releitura sob a trava da linha, a trava do médico sai depois da linha — também sem ciclo, pelo mesmo motivo.

## 6. Limites assumidos (não são gaps escondidos)

1. **Assinatura ICP-Brasil (Fase 3) não existe.** Documentos cirúrgicos usam o hash interno de conteúdo (como `CareGuidance`); o termo assinado pelo paciente entra como cópia digitalizada anexada. Quando a Fase 3 existir, o ponto de troca é `SurgeryDocumentIssueService`.
2. **O sistema não calcula a potência da LIO** (decisão do plano): registra modelo, potência, fórmula e refração-alvo informados pelo cirurgião.
3. **Guias de cobrança do hospital** (`guiaResumoInternacao`, honorários) ficam com o hospital; o EasyEye emite solicitação de internação e anexo de OPME e, quando a cirurgia é feita na clínica, as despesas de OPME utilizada no SP-SADT.
4. **Repasse do cirurgião por cirurgia** não faz parte da Fase 4.
5. Resposta da operadora que não vier no retorno do transporte é registrada pela secretária como evento (número, senha, validade).
6. Sem Chrome/E2E contra operadora real (só transporte simulado).

## 7. Contratos entre módulos (implementação)

Cada etapa vive em arquivos próprios e só se encontra com as outras por estes pontos:

| Ponto | Onde | Quem usa |
|---|---|---|
| Abas da tela do caso | `config/surgery.php` → `show_sections` (classe que implementa `Sections\SurgeryShowSection`) + `resources/js/Components/Surgery/Tabs/<Key>Tab.vue` (carregado por `import.meta.glob`) | todas |
| Rotas de cada etapa | `routes/surgery/<modulo>.php` (incluído por `routes/surgery.php`, dentro de `panel.surgery.`) | todas |
| Textos | `lang/{pt_BR,en}/surgery_<modulo>.php` (núcleo em `surgery.php` / `surgery_enums.php`) | todas |
| Transição de estado do caso | `SurgicalCaseService::lock()` + `changeStatus()` dentro da transação do chamador | agenda, cirurgia, alta |
| Documento congelado | `SurgeryDocumentIssueService::issue()` / `pdf()` / `attachSignedCopy()` | documentos, cirurgia (termo do implante), pós-op (alta/orientações) |
| Condições para "realizada" | `config('surgery.perform_guards')` → classes `Guards\SurgeryPerformGuard` | checklist (Pausa Cirúrgica), autorização válida |
| Resposta da operadora | `SurgeryAuthorizationService::record()` (`channel = tiss`, `tiss_guide_id`) | TISS |
| Evento "autorização registrada" | `Events\Surgery\AuthorizationEventRecorded` (depois do commit) | aviso ao paciente, indicadores |
| Evento "cirurgia realizada" | `Events\Surgery\SurgeryPerformed` (depois do commit, fora das travas do caso) | pós-operatório (cria retornos D1/D7/D30) |
| Erros de regra | `Exceptions\Surgery\SurgeryRuleException` (409 estado / 422 dado; mensagem já traduzida) | todas |

Migrations: núcleo `2026_10_25_*`; agenda/checklist `2026_10_26_*`; documentos `2026_10_27_*`; TISS `2026_10_28_*`; cirurgia/implante `2026_10_29_*`; pós-operatório/indicadores `2026_10_30_*`.

## 8. Desvios do desenho original e correções da revisão (registro)

O que a implementação decidiu diferente do §2–§5 ou aprendeu na revisão adversarial; quem mexe nessas áreas parte daqui.

1. **Estoque.** O consumo recusa lote vencido (`ExpiredLotException`; data do uso, não "hoje"). O estorno é `ConsumptionReversal` (um por consumo, índice único em `reversal_of_id`) e devolve o produto pelo custo médio que o consumo gravou; o lote **não muda de custo** no estorno — o `unit_cost` do consumo é a média do PRODUTO, e ponderá-lo na média do lote o distorcia (9 un. a 100 + 1 estornada a 150 = 105). Implante de consignação/externo usa `stock_source = external` (registro e rastreio sem baixa). `requires_serial` existe no produto; a importação CSV de produtos ainda não o conhece.
2. **TISS.** O TUSS do material fica em `metadata` dos itens da guia (não há coluna em `surgical_case_materials`). Envio simples com "dúvida" e upload de resposta que exige número de guia seguem as regras de entrega em dúvida do domínio Tiss.
3. **Agenda cirúrgica.** `windowsByDoctor` entrega as janelas como `ScheduleBlock` não persistido; a grade mensal as desconta pelo INTERVALO da consulta (início + intervalo do médico), como o seletor de horários e `surgeryOverlapError()`. Bloqueio comum continua valendo pelo início. Atendimento do pronto atendimento fica fora da conferência (nasce fora da grade, também na reativação).
4. **Realizada.** `ConsentSignedGuard` está registrado em `perform_guards` (TCLE com cópia assinada, ou justificativa do cirurgião). `plan_deviation_reason` guarda a justificativa de LIO diferente da planejada. O evento `SurgeryPerformed` leva só o `caseId`. Fechar o checklist é ato do médico da equipe.
5. **Horário.** `Support\Surgery\LocalTime::parse()` converte o instante UTC ("Z") do navegador para o fuso da aplicação antes de gravar/comparar.
6. **LGPD.** Exportador próprio (`SurgeryDataExporter`, `COVERED_TABLES`). A Fase 6 usa a versão 5 do `PatientDataExporter`; com o merge de `desenv` (15/10) o formato ficou na **versão 6** (5 = recall, 6 = cirurgias) e `PatientDataCoverageTest` espera 6.
7. **WhatsApp.** Aviso neutro, desligado de fábrica por clínica, revalidado no envio; o template Meta `easyeye_cirurgia_aviso` precisa de aprovação antes de ligar.
8. **Sessão.** `config/session.php` → `connection` só aplica o padrão Redis (`REDIS_SESSION_CONNECTION`) ao driver `redis`; `SESSION_CONNECTION` manda sempre. O padrão aplicado ao driver `database` apontava para uma conexão de banco inexistente.
9. **Revisão Codex (15/10).** Reativação de consulta e remarcação em lote passaram a conferir a cirurgia sob a trava (§5); estorno sem ponderar o lote (item 1); calendário mensal por intervalo (item 3); sessão (item 8). Cada correção tem teste e foi verificada com mutante.
10. **Revisão Codex (15/10, 2ª rodada).**
    - *Resposta de autorização por guia* (D9): o envelope do anexo de OPME (`recebimentoAnexo/…/anexoOPME`) é lido pelo parser; a aprovação da internação não autoriza mais os materiais nem tira o caso de "aguardando" (o anexo de OPME continuava sem poder ser enviado). A fixture do OPME nos testes valida contra o XSD oficial.
    - *Termo de consentimento* cobre o pedido que descreve (`ConsentBasis`): olho, procedimentos, cirurgião e a anestesia (se o termo a afirmava). Se mudam depois da emissão, o termo fica "desatualizado" (`ConsentSignedGuard::state`) e a cópia assinada deixa de valer até reemitir e assinar de novo; data, sala, título e LIO planejada não invalidam. A prévia devolve o retrato do pedido (`basis_hash`) e a emissão recusa (409) se o pedido mudou desde então.
    - *Orientações pós-operatórias*: a trava de alergia vale para o documento inteiro (colírios com posologia, duração e desmame, e o texto livre), mesmo sem colírio estruturado.
    - *Edição do caso*: a regra única é `SurgicalCaseStatus::allowsAnyChange()` (não final e não realizado) para o botão do cabeçalho, a aba Pedido e o serviço; o pedido (`allowsRequestChanges()`) trava antes, a equipe e a parte clínica não.
    - *Retorno pós-operatório remarcado pela Agenda* (cria agendamento novo e cancela o antigo): o novo herda o `surgical_case_id` e o retorno passa a apontar para ele na mesma transação (`PostOpReturnService::followReschedule`); a alta que corre junto relê o retorno e cancela o agendamento vigente. O desvio do dia-alvo usa o horário vivo do agendamento.
    - *LIO planejada* tem um plano por olho: `distinct` no formulário e guarda no serviço, antes de apagar os planos antigos (a falha era uma exceção do índice único). *DescriptionPanel* guarda a revisão devolvida pelo último salvar (a finalização recusada deixava a tela com a revisão velha e todo salvar dava 409). Atalho do retorno usa `#tab=postop`, o contrato da tela do caso.
11. **Junção com a Fase 6 (merge de `desenv`, 15/10).** Convivem sem se pisar: gates `ManageSurgery`/`PerformSurgery`/`ViewSurgeryIndicators` e `OperateRecall`/`DecideRecall`; recursos de plano `has_surgery` e `has_patient_recall`; templates WhatsApp `easyeye_cirurgia_aviso` e `easyeye_lembrete_retorno` (ambos pedem aprovação na Meta); a série da Agenda aceita a frequência `offsets` (retornos pós-operatórios) ao lado de `every_weeks`/série aberta. A documentação passou para `docs/internal/` (este ADR, o runbook de entrada em produção do módulo e os manuais).
