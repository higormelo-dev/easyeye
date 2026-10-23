# Plano de Melhorias (iGUT Clínicas): o que foi implementado

Situação em 21/10/2026, no branch `desenv` (HEAD `5aeab203`).

O Plano de Melhorias EasyEye, de 06/10/2026, comparou 82 itens com o concorrente iGUT Clínicas e os organizou em 8 fases (0 a 7). Este documento confere, item por item, o que existe no código hoje.

**Como foi conferido:** leitura do código (rotas, controllers, serviços, migrations e telas Vue). Não foram rodados testes, não houve validação no navegador e não foi conferido se cada ambiente já aplicou as migrations. "Feito" quer dizer que o código está lá, não que foi testado de ponta a ponta.

**Legenda:** **Feito** (backend e tela) · **Parcial** (falta parte da entrega ou a tela) · **Não feito** (sem código).

## Resumo

| Fase | Feito | Parcial | Não feito |
|---|---|---|---|
| 0. Correções de base | 6 | 0 | 0 |
| 1. Agenda e atendimento | 11 | 0 | 0 |
| 2. Segurança clínica e documentos | 6 | 0 | 0 |
| 3. Assinatura ICP-Brasil e envio digital | 0 | 1 | 5 |
| 4. Módulo cirúrgico oftalmológico | 7 | 0 | 0 |
| 5. Laudos de exames | 7 | 0 | 0 |
| 6. Retenção de pacientes crônicos | 2 | 1 | 0 |
| 7. Comercial e suporte do SaaS | 3 | 2 | 1 |
| **Total (52 entregas)** | **42** | **4** | **6** |

A ICP-Brasil não tem nenhum código no `desenv`. Hoje o sistema assina só com uma marca interna (hash, em [Signable.php](../../../app/Traits/Signable.php)), que não vale como assinatura digital fora do sistema.

## O que falta

| # | Item | Fase | Situação | Onde mexer |
|---|---|---|---|---|
| 1 | Integração com provedor de certificado em nuvem credenciado pelo ITI | 3 | Não feito | — |
| 2 | Receita, atestado, laudo, relatório e pedido assinados em PAdES, com QR de validação | 3 | Não feito | [SurgeryDocumentIssueService.php](../../../app/Services/Surgery/SurgeryDocumentIssueService.php) já marca o ponto de troca no cirúrgico |
| 3 | Prontuário assinado com certificado ao finalizar, com selo | 3 | Não feito | Hoje existe só o botão "Assinar prontuário" com o hash interno |
| 4 | Aviso de médico sem certificado, com o caminho para emitir | 3 | Não feito | — |
| 5 | Receita de antimicrobiano em 2 vias (RDC 471/2021) e receita de controle especial | 3 | Não feito | [DocumentationType.php](../../../app/Enums/DocumentationType.php) não tem esses tipos. Não depende da ICP |
| 6 | Envio ao paciente por link com validade, WhatsApp e e-mail | 3 | Parcial | Só pelo Portal do Paciente com login ([PatientDocumentSharesController.php](../../../app/Http/Controllers/PatientDocumentSharesController.php)); o compartilhamento não tem validade e não há WhatsApp |
| 7 | Orientações por hospital | 6 | Parcial | Ligadas ao procedimento; o hospital é texto livre (`location_label` em [create_care_guidance_tables](../../../database/migrations/2026_10_22_000900_create_care_guidance_tables.php)) |
| 8 | Bandeira do cartão no recebimento feito pela Agenda | 7 | Parcial | Existe no Caixa; falta em [CashEntryModal.vue](../../../resources/js/Pages/Panel/Schedules/CashEntryModal.vue) e [ScheduleCashEntryRequest.php](../../../app/Http/Requests/Financial/ScheduleCashEntryRequest.php) |
| 9 | Chat de suporte dentro do painel | 7 | Parcial | Só o ícone [SupportButton.vue](../../../resources/js/Components/Panel/SupportButton.vue), que abre WhatsApp ou e-mail |
| 10 | Trilhas de treinamento por perfil | 7 | Não feito | O tour ([PanelTour.php](../../../app/Support/PanelTour.php)) cobre só o menu e o Dashboard |

## Fase 0: correções de base

| Entrega | Status |
|---|---|
| Convênio inativo recusado no agendamento (422) | Feito |
| Médico sem cadastro de médico não vê a agenda da clínica inteira | Feito |
| Duplicado barrado por nome + data de nascimento; homônimo passa com aviso | Feito |
| Excluir documentação do prontuário sem erro 500 (`deleted_by`) | Feito |
| Tela de bloqueio de sala e equipamento | Feito |
| Botão de declaração médica | Feito |

## Fase 1: agenda e atendimento

| Entrega | Status |
|---|---|
| Filtros por situação e contadores do dia | Feito |
| Filtro e coluna por especialidade e sala | Feito |
| Encaixe com limite por médico e turno, barrado no servidor | Feito |
| Calendário do mês colorido por vagas | Feito |
| Visão de semana e mês | Feito |
| PDF da agenda e ficha do paciente | Feito |
| WhatsApp na linha da agenda, respeitando quem respondeu SAIR | Feito |
| Série de sessões (numeração "3/10", cancelar e remarcar a série) | Feito |
| Pronto atendimento (fila com prioridade, médico chama) | Feito |
| Prontuário: confirmação ao finalizar, cronômetro e rascunho automático no servidor | Feito |
| Prontuário no menu, com busca, e relatório de primeira consulta | Feito |

## Fase 2: segurança clínica e documentos

| Entrega | Status |
|---|---|
| Alergias do paciente, com alerta na agenda, no prontuário e no receituário | Feito |
| Alertas de medicação de risco ocular (tansulosina, anticoagulantes, hidroxicloroquina, corticoides e outros) | Feito |
| Uso de lente de contato na anamnese | Feito |
| Atestados: acompanhante, comparecimento, aptidão visual, CID só com autorização do paciente | Feito |
| Kits de exames por convênio (TUSS, CID, data alternativa) | Feito |
| Data alternativa em receita, relatório e pedido; "salvar como modelo" | Feito |

## Fase 3: assinatura ICP-Brasil e envio digital

| Entrega | Status |
|---|---|
| Integração com certificado em nuvem de provedor credenciado pelo ITI | Não feito |
| Assinar receita, atestado, laudo, relatório e pedido em PDF (PAdES), com QR de validação | Não feito |
| Assinar o prontuário ao finalizar e mostrar o selo | Não feito: existe só a assinatura interna (hash) |
| Aviso de médico sem certificado, com o caminho para emitir | Não feito |
| Envio por link com validade, WhatsApp e e-mail, com download registrado | Parcial: só pelo Portal do Paciente com login; o acesso é registrado |
| Receita de antimicrobiano em 2 vias e receita de controle especial | Não feito |

## Fase 4: módulo cirúrgico oftalmológico

| Entrega | Status |
|---|---|
| Pré-operatório (olho, TUSS, técnica, anestesia, CIDs, alergias, biometria, LIO planejada) | Feito |
| Autorização com histórico de eventos, número e validade | Feito |
| Guia de internação e anexo de OPME no TISS, termo de consentimento, orientações pré-operatórias | Feito (sem assinatura ICP) |
| Agenda cirúrgica por sala, hospital e cirurgião, com checklist de cirurgia segura | Feito |
| Descrição cirúrgica, baixa da LIO com lote, série e validade, etiqueta do implante (RDC 556/2021) | Feito |
| Pós-operatório D1, D7 e D30, esquema de colírios, avaliação (AV, PIO) e alta | Feito |
| Indicadores: taxa de autorização, tempo até a cirurgia, erro refracional por cirurgião | Feito |

## Fase 5: laudos de exames

| Entrega | Status |
|---|---|
| Ditado por voz (transcrição no servidor) | Feito |
| Pedal USB (teclas configuráveis) | Feito |
| Foto e vídeo curto da lâmpada de fenda pela câmera | Feito |
| Ordem e legenda das imagens; 1, 2, 4 ou 6 por folha, com OD e OE lado a lado | Feito |
| Indicação clínica, observações e data alternativa | Feito |
| Técnico prepara, médico libera e assina | Feito (assinatura interna, não ICP) |
| Envio do laudo ao médico solicitante | Feito (só por e-mail) |

## Fase 6: retenção de pacientes crônicos

| Entrega | Status |
|---|---|
| Recall por condição (glaucoma, DMRI, retinopatia diabética, ceratocone, hidroxicloroquina), com convite por WhatsApp | Feito |
| Séries de anti-VEGF com autorização e lote por aplicação | Feito |
| Protocolos e orientações por procedimento e hospital | Parcial: sem vínculo com o hospital |

Os 5 protocolos de recall nascem como rascunho (via seeder). Enquanto não forem revisados e ativados no Manager, nenhum convite de retorno é enviado.

## Fase 7: comercial e suporte do SaaS

| Entrega | Status |
|---|---|
| NFS-e (Focus NFe), emitida pelo SaaS para a clínica | Feito |
| Contrato de convênio com vencimento e alerta | Feito: o alerta é um card no Dashboard (admin e financeiro), não um aviso ao entrar; não bloqueia nada |
| Bandeira do cartão no recebimento particular | Parcial: existe no Caixa, falta no recebimento da Agenda |
| Chat de suporte e WhatsApp dentro do painel | Parcial: só o ícone que abre WhatsApp ou e-mail |
| Página "Novidades" | Feito (ainda não cita os recursos da Fase 6) |
| Trilhas de treinamento por perfil | Não feito |

## Fora do escopo por decisão

- Teleconsulta e videochamada (descartadas em 18/10/2026).
- Cálculo de potência de LIO dentro do sistema (risco clínico e regulatório).
- Triagem com classificação de risco completa no pronto atendimento (fica a fila com prioridade).
- Pacote financeiro de sessões (fica a série sem cobrança de pacote).
- IMC e checklist genérico de exames laboratoriais (só dentro do kit pré-operatório).

## Pendência antes da Fase 3

O provedor escolhido para a assinatura é o Assinadoc Saúde. A documentação pública dele não menciona ICP-Brasil, PAdES nem certificado em nuvem (VIDaaS, BirdID). Confirmar com o fornecedor antes de planejar a fase.
