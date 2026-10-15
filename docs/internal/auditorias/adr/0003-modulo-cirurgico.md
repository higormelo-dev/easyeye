# ADR 0003 — Módulo cirúrgico oftalmológico (Fase 4)

Status: aceito (15/10/2026). Origem: Plano de Melhorias EasyEye, Fase 4 (comparação com o iGUT Clínicas).
Escopo: pré-operatório → autorização → documentos → agenda cirúrgica → cirurgia e rastreabilidade → pós-operatório e alta → indicadores.

## 1. Contexto (o que o código tinha antes)

- `SurgeryType` é só um catálogo de 14 categorias; nada o referencia. `SurgerySchedulingDocService` gera receitas pré/pós de catarata como `MedicalRecordDocumentation` — presas ao prontuário (o guard do prontuário assinado recusa documento novo).
- Estoque: `entity_products.is_opm`/`requires_lot`, `stock_lots` (lote + validade), `stock_movements` (ledger imutável). **Não há série**, registro ANVISA, reserva, estorno de consumo, nem conferência de validade na baixa. A ponte de LIO (`IolLensStockBridgeService`) cria produto `is_opm` sem `requires_lot`.
- TISS: só `guiaConsulta` e `guiaSP-SADT`. O builder manda "todo o resto" para `appendSadtGuide` (tipo novo sairia como SP-SADT sem erro). A **solicitação de internação não viaja em `loteGuias`** no padrão 4.03: é `prestadorOperadora/solicitacaoProcedimento` (`SOLICITACAO_PROCEDIMENTOS`); o anexo de OPME avulso é `loteAnexos` (`ENVIO_ANEXO`).
- Agenda: `schedules` não tem duração; o conflito de sala é por igualdade exata de `date_time`, validado fora da transação e sem trava. Só existe `doctor_id` (sem equipe). Não existe hospital.
- A Fase 6 **não** criou `care_locations` (só `location_label` texto livre em `care_guidance_*`). `procedures.clinical_kind` é string livre com um único valor (`intravitreal_injection`).

## 2. Decisões

**D1. Cirurgia é entidade própria (`surgical_cases`), não linha de `schedules`.** Uma linha de `schedules` viraria "consulta" no WhatsApp de confirmação/pesquisa, item duplicado no repasse (`DoctorPayoutProductionService` conta todo Atendido), atendimento ambulatorial no BI e disparo do lock paciente/dia. Os **retornos** (D1/D7/D30) continuam `schedules` normais (`PostOpEvaluation`), apontando para `surgical_case_id`: são consultas de verdade e entram em repasse/WhatsApp/BI corretamente.

**D2. Hospital e sala são tabelas novas** (`surgery_facilities`, `surgery_rooms`). Sala própria pode apontar para um `ClinicResource` (reaproveita escala semanal e bloqueios); sala de hospital é só da cirurgia.

**D3. Conflito de sala/cirurgião/paciente é por INTERVALO** (`scheduled_start`–`scheduled_end`), decidido dentro da transação sob `pg_advisory_xact_lock` por recurso, em ordem crescente de chave. Sem `EXCLUDE USING gist` (exigiria `btree_gist` e privilégio no banco; o projeto já resolve concorrência por advisory lock + teste com processo filho). Bloqueios (`resource_blocks`) e escala (`resource_work_schedules`) da sala própria são checados pelo INTERVALO inteiro, não só pelo início.

**D4. Documentos fora do prontuário são retratos congelados** (molde `CareGuidanceIssue`): texto copiado, `content_hash = CanonicalJson::hashOf(...)`, linha imutável, PDF montado só do retrato, dedupe de clique duplo. Nunca `MedicalRecordDocumentation`. Orientações e colírios reaproveitam o `CareGuidance` (ganha `surgical_case_id` nullable em `care_guidance_issues`).

**D5. Rastreabilidade de implante é tabela filha congelada** (`surgical_implants`), gravada na MESMA transação da baixa de estoque: copia lote, validade e série (nunca referencia só o `stock_lot_id`, porque `ProductLotsController::update` edita lote/validade retroativamente). Lote e validade vigente são **obrigatórios e conferidos** na cirurgia (validade vencida nunca passa); série obrigatória para LIO; unicidade `(entity_product_id, serial_number)` entre implantes ativos.

**D6. Estoque ganha estorno e conferência de validade, sem tocar no que existe.** Novo `StockMovementType::ConsumptionReversal` (+1) com `stock_movements.reversal_of_id` único (idempotência do estorno), método `StockService::reverseConsumption()` sob a mesma trava produto → lote. Reserva rígida **não** entra: "LIO planejada" é demanda calculada (planejadas nos próximos dias × saldo), exibida como aviso — sem mudar o razão de estoque.

**D7. Recurso de plano superior por chave de recurso** (`FeatureKey::HasSurgery` = `has_surgery`): Premium (e cortesia). Backfill dos planos existentes por migration, como `has_patient_recall`.

**D8. Papéis e gates.** `ManageSurgery` (admin, médico, secretária — pedido, autorização, agenda, documentos), `PerformSurgery` (ato médico: plano cirúrgico, descrição, avaliação pós-op, alta; exige `CurrentDoctor::activeFor`), `ViewSurgeryIndicators` (admin, financeiro, médico — o médico só vê os próprios números).

**D9. Autorização é um log de eventos imutável** (`surgical_authorization_events`); o estado corrente do pedido é desnormalizado em `surgical_cases` sob a trava da linha. Parcial lista os itens autorizados; negada exige motivo; número/senha/validade ficam no evento. Vencida é calculada pela validade (e por job diário que grava o evento `expired`).

**D10. Checklist de cirurgia segura (OMS) em 3 fases** — Entrada (Sign In), Pausa Cirúrgica (Time Out), Saída (Sign Out) — com itens oftalmológicos (olho marcado conferido com pulseira/termo, LIO conferida, alergias, jejum quando aplicável). Catálogo versionado em `config/surgery.php`; respostas gravadas com chave+versão+texto; fase fechada fica imutável com hash. **Registrar a cirurgia como realizada exige Pausa Cirúrgica fechada**; exceção só do cirurgião, com justificativa gravada.

**D11. TISS:** novos tipos `InpatientRequest` e `OpmeAttachment`; dispatch do builder passa a ser por `match` **com erro explícito** para tipo desconhecido (fim do "cai em SP-SADT"); builders próprios para os envelopes `SOLICITACAO_PROCEDIMENTOS` e `ENVIO_ANEXO`, validados contra o XSD 4.03.00; pré-validação por tipo; `TissGuideLink` passa a ligar internação ↔ anexo; retorno `autorizacaoServicos`/`senha` vira evento de autorização. Envio pelo transporte existente em serviço próprio e simples (uma transação por guia; sem lote), sem retentativa automática: timeout fica "envio em dúvida" e só reenvia por ação explícita. PDF imprimível da guia e do anexo (para portal da operadora).

**D12. Pós-operatório:** frequência `offsets` (dias a partir da cirurgia) na série da Agenda; retornos nascem `PostOpEvaluation`, sem sala cirúrgica, apontando para o caso. Avaliação estruturada (`surgical_postop_evaluations`: AV, PIO, reações, refração residual) por marco. Alta é transição do caso (exige avaliação D1 e a decisão do cirurgião) e emite documento congelado.

**D13. Indicadores** em `SurgeryIndicatorsService` (agregados, cache curto por clínica/período): taxa de autorização, tempo até a cirurgia, cancelamentos, motivos de negativa e **erro refracional previsto × obtido** por cirurgião (ME, MAE, % dentro de ±0,50 e ±1,00 D).

**D14. LGPD:** toda tabela nova com `patient_id` entra no `PatientDataExporter` (v6) e na classificação de `PatientDataCoverageTest`. Mensagem de WhatsApp da autorização é neutra (primeiro nome + clínica; nunca procedimento, olho ou motivo) e só sai com opt-in, kill-switch por clínica desligado de fábrica e revalidação no envio.

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

1. `pg_advisory_xact_lock` dos recursos da janela (sala, cirurgião, paciente), em ordem crescente de chave;
2. linha de `surgical_cases`;
3. filhos do caso (id crescente);
4. estoque: produto → lote (a ordem do `StockService`).

Marcar realizada trava caso → implantes → estoque. Reagendar toma os advisory locks ANTES da linha. A criação de retornos (Agenda) acontece FORA de qualquer trava do caso e usa a ordem da Agenda (cota → paciente/dia → linha).

## 6. Limites assumidos (não são gaps escondidos)

1. **Assinatura ICP-Brasil (Fase 3) não existe.** Documentos cirúrgicos usam o hash interno de conteúdo (como `CareGuidance`); o termo assinado pelo paciente entra como cópia digitalizada anexada. Quando a Fase 3 existir, o ponto de troca é `SurgeryDocumentIssueService`.
2. **O sistema não calcula a potência da LIO** (decisão do plano): registra modelo, potência, fórmula e refração-alvo informados pelo cirurgião.
3. **Guias de cobrança do hospital** (`guiaResumoInternacao`, honorários) ficam com o hospital; o EasyEye emite solicitação de internação e anexo de OPME e, quando a cirurgia é feita na clínica, as despesas de OPME utilizada no SP-SADT.
4. **Repasse do cirurgião por cirurgia** não faz parte da Fase 4.
5. Resposta da operadora que não vier no retorno do transporte é registrada pela secretária como evento (número, senha, validade).
6. Sem Chrome/E2E contra operadora real (só transporte simulado).
