# Glossário do produto

Termos que aparecem no código, nas telas e nos documentos e que têm significado próprio no
EasyEye. Decisões e motivos: [`docs/adr/`](adr/).

## Fase 6 — retenção de pacientes crônicos e validação clínica

**Aceite (conferência CFM)** — registro, feito por uma pessoa da EasyEye, de que o CRM/UF e o
RQE declarados no atestado do Revisor foram **conferidos no portal do CFM**
(`clinical_review_acceptances`). Sem aceite `accepted`, nenhuma versão validada pode ser
liberada. É a segunda chave da identidade do revisor (ADR 0002, item 4).

**AO / OD / OE** — ambos os olhos / olho direito / olho esquerdo (em inglês OU / OD / OS). No
banco: `patient_exams.laterality` (1 = OD, 2 = OE, 0 ou nulo = AO) e
`medical_record_procedures.eye` (`right|left|both`). `App\Enums\Eye` traduz entre eles. **AO
cobre qualquer olho** na regra de recall (ADR 0002, item 11).

**Atestado** — declaração que o Revisor assina ao enviar o parecer: nome, CRM/UF, RQE, e-mail,
texto versionado da declaração, conflito de interesses e se autoriza mostrar a identidade às
clínicas. Append-only (`clinical_review_attestations`).

**Freio de mão (suspensão)** — `released | superseded → suspended`: a versão sai de uso **na hora**, em todas as clínicas,
com motivo registrado; reversível por **Retomar** (que refaz a integridade). Aposentar (`retired`) é o definitivo.

**Autorização de lembretes (`care_reminders`)** — consentimento do paciente (LGPD art. 7, I) para receber lembretes de retorno por WhatsApp,
marcado no cadastro e registrado em `patient_consents`. Sem ela o paciente não recebe convite (só ligação). SAIR no WhatsApp a retira; VOLTAR
não a devolve (ADR 0002, item 13).

**Chaves do convite (kill-switch)** — as três que precisam estar abertas para o convite por WhatsApp sair: a **pausa global** do EasyEye, a chave
da clínica no **Manager** (`recall_enabled`) e a chave da própria clínica nas configurações dos retornos. Todas nascem desligadas.

**Condição do paciente** (`patient_conditions`) — quadro crônico de um olho que dispara recall:
glaucoma, DMRI, retinopatia diabética, ceratocone, uso de hidroxicloroquina. Estados:
`suggested` (detectada, ainda não confirmada — não gera convite), `active`, `discharged`
(alta), `rejected` (descartada).

**Convite de revisão** — link pessoal, temporário e de uso único enviado ao Revisor por e-mail,
referindo um **conjunto** de versões de protocolo (`set_hash`). Estados: `pending → submitted →
accepted | refused`; `pending → expired | revoked`.

**Curso anti-VEGF** (`intravitreal_courses`) — tratamento de um olho com injeções intravítreas
(regime de ataque, PRN ou intervalo fixo); cada injeção é uma **aplicação** com lote e
autorização.

**Hash do conteúdo** — `1:` + sha256 do JSON canônico da versão, com domínio
(`easyeye.recall.protocol.v1`). Identifica exatamente o que o Revisor viu
(`App\Support\CanonicalJson`).

**Liberar (release)** — passar uma versão validada e aceita a `released`: a partir daí as
clínicas podem adotá-la. Só uma versão por protocolo fica `released`.

**Onda de convite** — o convite de retorno por WhatsApp de **um paciente** em um ciclo
(`recall_invites`, chave `wave_key`), que pode cobrir várias tarefas vencidas de uma vez.

**Orientação** — texto (ou PDF anexo) entregue ao paciente por procedimento e local
(colírios, repouso, sinais de alerta). Tem tabelas próprias; a emissão é congelada.

**Perdido de vista** (`lost`) — retorno vencido há mais de `lost_after_days` (padrão 180):
sai do convite automático e entra no tratamento de busca ativa.

**Protocolo de retorno** (`recall_protocols`) — a regra de acompanhamento de uma condição:
critérios de entrada (CID forte/fraco/exclusão, medicações) e **tarefas** (consulta, exame,
curso de tratamento) com intervalo padrão, mínimo e máximo.

**Recall** — lembrar o paciente de voltar. No sistema: `patient_recalls`, derivado e
reavaliável a qualquer momento (ADR 0002, item 7).

**Retorno do médico** (`doctor_order`) — o retorno que o médico pediu no prontuário
(`follow_up_days`). Fonte de vencimento que **não** depende de protocolo validado.

**Revisor Clínico** — oftalmologista externo (com RQE) que valida as regras de intervalo antes
de irem a produção. Não tem usuário no sistema: age por convite. **Não confundir com "médico
parceiro"/`doctors.partner`/módulo Partner**, que é parceiro comercial (ADR 0002, item 1).

**Versão do protocolo** (`recall_protocol_versions`) — conteúdo congelado de um protocolo.
Estados: `draft → in_review → validated → released → superseded`; `in_review → declined |
withdrawn`; `released ⇄ suspended`; qualquer → `retired`. `superseded` continua válida para quem
já a adotou; correção clínica = **suspender** a antiga.

**Revalidação** — prazo (`review_due_at`, 24 meses por padrão) depois do qual uma versão liberada deve ser revisada de novo.
É aviso no Manager; não bloqueia o uso.

**Verificação de integridade** — `recall:verify-integrity`, diária (05:15): refaz o hash do texto gravado de cada versão em uso
ou em revisão. Divergência suspende (ou retira) a versão e faz o comando falhar.

**Vencimento** — datas persistidas de uma tarefa: `invite_from` (começa a poder convidar),
`due_at` (devido), `overdue_from` (vencido, após a tolerância) e `lost_from` (perdido de vista).

**NFS-e** — Nota Fiscal de Serviço Eletrônica. No EasyEye só a dona do SaaS emite (via Focus NFe) para cada fatura paga de uma clínica; padrão **municipal** (`/v2/nfse`) ou **nacional** (`/v2/nfsen`, DPS).

**DPS** — Declaração de Prestação de Serviço: o pedido de emissão do padrão nacional. Leva `serie_dps` e `numero_dps`, reservados pelo EasyEye sob trava e congelados na nota.

**Prestador / tomador** — prestador é a empresa dona do SaaS (global, configurada em Manager → Nota fiscal); tomador é a clínica que pagou a fatura (dados congelados na nota em `payer_snapshot`).

**Ref (NFS-e)** — identificador da nota na Focus: UUID da linha `nfse_documents` sem hífens, em maiúsculas; nasce uma vez e vale no envio, na consulta, no cancelamento e no e-mail. Reemissão depois de erro cria linha e ref novas.

**Nota "ocupando" a fatura** — nota a caminho (`pending_send`, `processando_autorizacao`) ou valendo (`autorizado`). No máximo uma por fatura (índice único parcial); erro, falha de envio e cancelada não ocupam.
