# ADR 0002 — Validação clínica por Revisor externo e recall de pacientes crônicos

Data: 2026-10-13 · Status: aceito (Fase 6 do plano de melhorias; implementação por etapas)

Registra as decisões da Fase 6 ("Retenção de pacientes crônicos") que **não são óbvias da
leitura do código**. O plano de melhorias exige que *as regras de intervalo sejam validadas
por um médico antes de ir para produção*; este documento diz como o sistema **prova** quem
validou, quando e qual versão — e só então libera o uso. Termos em [`docs/glossario.md`](../glossario.md).

---

## 1. "Revisor Clínico", e não "médico parceiro"

### Contexto
O pedido original fala em "médico parceiro valida as regras". No código, `doctors.partner` e
o módulo Partner (`routes/portal.php`, comissões, indicação) já significam outra coisa:
parceiro comercial.

### Decisão
O papel é **Revisor Clínico** (`clinical_reviewers`) em todo o código, banco e telas. Ele **não
tem usuário** no sistema: age por um convite pessoal e temporário (item 5).

### Consequências
- ✓ Nenhuma colisão com `partner` (consultas, permissões, relatórios).
- ✗ Quem lê o pedido original precisa do glossário para ligar os dois nomes.

---

## 2. Duas fontes de vencimento

### Decisão
Um retorno vencido nasce de uma de duas fontes (`patient_recalls.source`):

- `doctor_order` — o retorno que o próprio médico pediu em `medical_records.follow_up_days`.
  É ato do médico, **não precisa de validação** de terceiros;
- `protocol` — a regra por condição (glaucoma, DMRI…). **Só roda com versão `released`**
  (validada, aceita e liberada — item 4).

### Consequências
- ✓ A lista de retornos vencidos pode ir a uma clínica piloto antes de o revisor terminar.
- ✓ Nenhuma regra clínica escrita pela EasyEye age sem validação.
- ✗ Antes da liberação do 1º protocolo, o recall só cobre o que o médico já pediu.

---

## 3. A unidade validada é a VERSÃO do protocolo

### Contexto
Validar "a regra do glaucoma" em abstrato não prova nada depois que o texto muda.

### Decisão
Cada versão tem conteúdo **congelado** e hash próprio. O convite de revisão referencia N
versões e um `set_hash` sobre elas. O revisor não define "faixa aprovada" à parte: pedir ajuste
gera **versão nova**, que ele reaprova vendo o diff. Os critérios de entrada (CID forte/fraco/
exclusão, medicação) fazem parte do conteúdo validado.

### Consequências
- ✓ "O que o revisor viu" = "o que o sistema usa" = "o que a clínica adota" (um só
  renderizador, `RecallRuleCard`).
- ✗ Mudar um intervalo, por menor que seja, reabre a validação daquela versão.

---

## 4. Dupla chave de identidade

### Contexto
O CRM digitado pelo revisor é **autodeclarado**; quem tem o link pode digitar qualquer um.

### Decisão
1. O revisor envia **parecer + atestado** (nome, CRM/UF, RQE, e-mail, declaração versionada,
   conflito de interesses);
2. a EasyEye **confere CRM/UF e RQE no portal do CFM** e registra o **aceite**
   (`clinical_review_acceptances`, com quem conferiu e o que conferiu).

Só versão `validated` **com aceite `accepted`** pode ser `released`. Atestados e aceites são
append-only.

### Consequências
- ✓ O link sozinho nunca basta para liberar uma regra clínica.
- ✗ A conferência no CFM é manual (não há API pública estável); fica registrada, não automatizada.

### Invariantes (definição de pronto da validação)
I1 nada ativa versão sem `released` + aceite `accepted` + decisão `approved` + hash igual ·
I2 conteúdo congelado não muda nem por SQL (trigger) · I3 o envio revalida a versão no momento
do envio · I4 um parecer por convite, uma decisão por item · I5 token nunca em claro,
comparado com `hash_equals` · I6 página pública sem dado de paciente e só com as versões do
convite · I7 quem/quando/versão/hash consultáveis no Manager e na tela de ativação da clínica ·
I8 nenhum seeder/migration cria versão além de `draft`.

---

## 5. Imutabilidade verificável

### Decisão
- Conteúdo em coluna `text` com **JSON canônico v1** (`App\Support\CanonicalJson`): chaves
  ordenadas por byte, NFC, só inteiros, sem espaço. Sem `jsonb` (reordena e normaliza números),
  sem float, sem `serialize()`.
- `content_hash = "1:" + sha256(domínio + "\n" + texto)`. O prefixo `1:` é a versão do
  algoritmo; mudar qualquer regra é versão nova, com vetores dourados novos
  (`tests/Fixtures/canonical-json`, gerados por implementação independente em Python).
- A verificação refaz o hash **do texto gravado**, sem recanonicalizar — uma diferença de
  versão do Unicode entre servidores não faz versão íntegra parecer adulterada.
- Trigger no PostgreSQL impede alterar conteúdo/hash/versão de versão não-rascunho e apagar;
  o comando noturno `recall:verify-integrity` **suspende** qualquer versão cujo hash não bata.
- As provas (atestado, aceite, evento de governança) são gravadas **dentro** da transação e
  falham alto — `AuditLogger` engole exceções, então não serve como prova.

### Consequências
- ✓ Adulterar por SQL é barrado (trigger) e, se contornado, é detectado e suspende o uso.
- ✗ Trigger só em PostgreSQL; nos demais drivers vale a verificação noturna.

---

## 6. O link do revisor

### Decisão
Convite persistido (hash do token, `set_hash`, expiração, revogação, uso). O token aparece só
no **1º salto**: troca por sessão e a URL fica limpa. **GET nunca consome** (scanner de e-mail
não gasta o convite); o envio do parecer é um POST único (transação com `FOR UPDATE` no
convite). PIN opcional por outro canal; reenvio rotaciona o token. Página `guest-app`
(`noindex`), `Referrer-Policy: no-referrer`, `Cache-Control: no-store`. **Sem dado de paciente**
(o impacto aparece só em contagens). Rascunho salvo no servidor, cifrado; nada em `localStorage`.
Token inválido, desconhecido, revogado ou expirado dá a mesma tela genérica e o mesmo tempo
(anti-enumeração).

---

## 7. Estado do recall derivado e persistido

### Decisão
`patient_recalls` é **derivado** (não é fonte): reavaliado por paciente sob
`pg_advisory_xact_lock`, alimentado por uma **outbox transacional** (`recall_dirty_patients`,
gravada na mesma transação que a mudança) e por uma conciliação noturna. O cálculo é um núcleo
puro (`RecallRuleEngine`), sem acesso a banco. Nada de varrer CID em JSON na requisição.

### Consequências
- ✓ A lista é um `SELECT` indexado; dá para recalcular tudo a qualquer hora.
- ✗ Há uma janela (≤ 1 min) entre o ato clínico e o recall refletir; a conciliação cobre o que o
  evento não pega (`saveQuietly` da assinatura, restauração de paciente, mudança de parâmetro).

---

## 8. Condição crônica: `active` × `suggested`

A condição nasce `active` se o CID **forte** estiver em prontuário **assinado** ou a medicação
for **confirmada**; senão `suggested` (não gera convite). O médico confirma, descarta ou dá
alta — ato clínico (gate `DecideRecall`). CID casa por **prefixo sem ponto**; categoria ampla só
sugere.

---

## 9. Convite por WhatsApp: consentimento explícito e SAIR

### Decisão
Só recebe quem marcou "lembretes de retorno" no cadastro (`ConsentType::CareReminders`); quem
não marcou fica **só na lista de ligação**. Texto **neutro** (primeiro nome + clínica; **nunca
a condição**). SAIR revoga. Intervalo mínimo entre convites, teto diário com ramp-up, janela de
horário, revalidação no envio, kill-switch em 3 níveis. `recall_enabled` nasce desligado; o
template precisa de aprovação manual na Meta antes de ligar.

### Consequências
- ✓ Convite não revela diagnóstico a quem lê a tela do celular.
- ✗ Menos pacientes alcançados do que com presunção de consentimento — escolha do dono.

---

## 10. Anti-VEGF e orientações

- O curso anti-VEGF é acoplado a `markDone` do procedimento, em **tabelas novas**: nada é
  gravado em `medical_record_procedures` além do que o ciclo de vida já permite (prontuário
  assinado é imutável).
- Orientações por procedimento/hospital têm **tabelas próprias**, não `MedicalRecordDocumentation`
  (o guard do prontuário assinado bloquearia emitir orientação depois da assinatura). A emissão
  é congelada, como no laudo de imagem.

---

## 11. AO cobre qualquer olho (leitura branda)

O produto trata lateralidade `0`/nula como **AO** (ambos/não informado). Para o recall, um ato
registrado em AO cobre a exigência de qualquer olho (`Eye::covers`). Consequência assumida: um
exame que ninguém marcou como OD/OE pode suprimir o lembrete do olho que de fato não foi
examinado. Alternativa mais rígida (AO não cobre olho específico) geraria lembretes falsos para
todo exame bilateral — decisão a revisar com o revisor clínico.

---

## 12. O aceite e a vida da versão

### Decisão
- **Aceite aceito**: o convite vira `accepted`; cada versão que o Revisor **aprovou** (e que segue em revisão, com o mesmo
  hash que ele viu) vira `validated` apontando o aceite; as que ele pediu ajuste ou **não aprovou** viram `declined` (a EasyEye
  prepara a próxima versão). Versão que saiu de revisão ou teve o hash trocado depois do parecer é **ignorada** (registrada no
  evento), nunca validada nem recusada em nome do Revisor.
- **Aceite recusado**: o convite vira `refused`; as versões **continuam em revisão** (a identidade não foi confirmada, nada vale).
- Aceitar exige **as duas conferências** (CRM/UF e RQE); recusar, e aceitar com divergência entre o declarado e o cadastro,
  exigem **nota**. O que se confere no CFM é o **declarado**, nunca o cadastro.
- **Liberar** só parte de `validated` e confere a prova **de novo** (I1): aceite `accepted` + decisão `approved` sobre ESTA
  versão + mesmo hash + conteúdo íntegro. A liberada anterior passa a `superseded`. Fica um prazo de **revalidação**
  (`config/recall.php`, 24 meses): é aviso, não bloqueio.
- **Retomar** devolve a versão ao estado de antes da suspensão, mas refaz a integridade e, se uma mais nova foi liberada
  nesse meio tempo, volta como `superseded` — nunca dois `released`.
- **Verificação noturna** (`recall:verify-integrity`): hash divergente em versão em uso → `suspended`; em revisão/validada →
  `withdrawn`; o comando **falha** (monitoramento) e grava `integrity_failed` uma vez por suspensão.
- A página pública do Revisor **não traz** CRM/RQE/e-mail do cadastro: ele DECLARA, e a EasyEye compara. Preencher de antemão
  deixaria quem recebesse o link encaminhado assinar como o convidado sem digitar nada.

### Ordem de locks (global)
protocolo → versões (id crescente) → revisor → convite → atestado. Liberar/suspender/retomar/aposentar/retirar travam o protocolo
e **todas** as versões dele; aceitar e enviar o parecer travam as versões do convite e depois o convite; criar convite trava as
versões e depois o Revisor. Todo evento de governança é gravado na mesma transação e **falha alto**.

### Consequências
- ✓ Nenhum caminho libera uma regra sem a prova completa, e a prova é refeita no último passo.
- ✓ Uma regra em dúvida sai de uso na hora, para todas as clínicas, com motivo registrado.
- ✗ A conferência no CFM segue manual (decisão já assumida no item 4).
- ✗ O prazo de revalidação só avisa; revalidar é decisão operacional da EasyEye.

---

## 13. O convite de retorno por WhatsApp: três chaves, ritmo e revalidação

### Decisão
- **Três chaves, todas desligadas de fábrica** (kill-switch em níveis): a **pausa global** do EasyEye (Manager; `system_settings`, lida sem cache),
  a **chave da clínica no Manager** (`whatsapp_settings.recall_enabled` — só depois de o modelo estar aprovado na Meta naquele número) e a
  **chave da própria clínica** (`recall_clinic_settings.whatsapp_invites_enabled`). Para protocolo, o envio **automático** ainda exige o
  `auto_contact` da adoção (ato do médico); o convite que uma pessoa manda pela lista de Retornos não depende dele.
- **Uma única porta** (`RecallInviteGuard`): canal (as chaves, número que envia, assinatura em dia) → paciente (cadastro ativo, sem bloqueio,
  **autorização `care_reminders`**, celular móvel marcado como WhatsApp, sem SAIR naquele número) → retornos (em aberto, sem agendamento, na janela de
  convite, **regra em uso e íntegra — I3**, teto por ciclo) → ritmo. A prévia, o clique, a rodada automática e o job leem a mesma decisão.
- **O envio revalida** (`stillEligible`): o que mudou entre enfileirar e enviar (consentimento retirado, SAIR, pausa, retorno tratado, versão suspensa,
  telefone trocado) vira `suppressed` com `error_code = recall_<motivo>` e **não sai**. Automático fora da janela fica `pending` (o `recall:requeue-pending`
  traz de volta e cancela após 3 dias). `skipped` ficou para falha transitória do WhatsApp; nenhum dos dois conta nos tetos.
- **Ritmo** (protege o número, que é o mesmo da confirmação de consulta): janela de horário e dias, intervalo mínimo entre convites do paciente (piso 7
  dias), teto de convites automáticos por retorno no ciclo e **teto diário com ramp-up** (5/10/20 por dia nas 3 primeiras semanas). Os tetos são decididos
  **sob a trava da clínica** (`pg_advisory_xact_lock`), antes da trava do paciente; uma **onda por paciente por dia** (`wave_key` único por clínica) vale para
  a rodada e para o clique ao mesmo tempo.
- **A mensagem é neutra**: primeiro nome e clínica. Nunca condição, exame ou motivo. Um teste varre o texto e as variáveis.
- **Consentimento explícito** (`ConsentType::CareReminders`, base legal consentimento): caixa no cadastro (mesmo cadastro da Agenda), registrada com quem
  coletou, canal, IP e versão do texto. **SAIR retira a autorização** (no número próprio, só da clínica; no do EasyEye, de todas que enviam por ele);
  **VOLTAR só reativa o número**. Sem autorização o paciente fica só na lista de ligação.
- **Resposta**: botões `recall_book` / `recall_done` gravam a resposta na onda e um evento em cada retorno coberto; "Quero agendar" sobe o paciente na
  lista por 30 dias. Texto livre e citação **não decidem nada**.

### Consequências
- ✓ O envio automático só acontece com a prova completa, e a prova é refeita no último passo (a pausa e o SAIR valem para o que já estava na fila).
- ✓ A clínica entende por que saem poucos convites no começo (a tela mostra o teto de hoje) e por que um paciente não pode ser convidado (a prévia lista).
- ✗ O SAIR no número do EasyEye atinge todas as clínicas que enviam por ele, e a revogação casa o **celular** (não a pessoa): familiares que compartilham
  o aparelho perdem a autorização juntos.
- ✗ A aprovação do modelo na Meta é manual e demora; enquanto não sai, a chave 2 fica desligada.
- ✗ Não há reconciliação com a Meta de quem reclamou/bloqueou o número fora do SAIR (o webhook de falha `opted_out` é o único sinal).

## 14. O curso de injeções intravítreas (anti-VEGF)

### Decisão
- Um **curso por olho** (`intravitreal_courses`) com uma **aplicação** por injeção (`intravitreal_applications`); o curso nasce ao solicitar o
  procedimento e a aplicação entra pelo `markDone`, **dentro da transação dele** (prontuário de origem → procedimento → paciente → curso →
  aplicação). Aplicação, curso e procedimento comitam juntos ou nenhum comita: nunca existe "procedimento executado" sem lote.
- **Lote e validade são obrigatórios** e validade vencida nunca passa (sem exceção: risco ao paciente); vindo do estoque, número e validade são os do lote.
- **Autorização do convênio por aplicação.** Exigida e não aprovada (ou vencida) só passa com o aviso explícito (`authorization_override`) e a
  justificativa, ambos gravados.
- **O médico decide o intervalo** a cada aplicação (2 a 16 semanas, "trate e estenda") ou encerra o curso com motivo; a próxima aplicação planejada
  é o retorno do curso na lista de Retornos (prazos próprios, mais curtos que o de consulta). A EasyEye não sugere intervalo.
- "Feito fora" **não** serve para aplicação de curso: o intervalo é decisão do médico e só o prontuário (uma nova aplicação) re-ancora o retorno (`RecallActionException::notForCourse`).
- Tudo sob a trava do **paciente** do recall (`RecallLocks::patient`, a mesma do avaliador): dois cliques nunca abrem dois cursos do mesmo olho.
- A **série aberta** da Agenda (`every_weeks`, `session_total` nulo, `addSession`) é o agendamento do tratamento; segue a ordem de locks de `store()`.
- **Quem agenda a próxima.** Mesma regra da Agenda: o perfil médico só agenda a partir de uma âncora da **própria** agenda (a sessão herda o médico da âncora; outra agenda recusa com `other_doctor_calendar`, sem tocar a série dela) e o paciente é avisado da criação (`NotifyScheduleChangeJob`, só depois do commit).
- **A reserva é do paciente e a data é a dela.** A reserva da aplicação (`schedule_id`) só vale se existir, não estiver cancelada/faltada e for do **mesmo paciente** do curso (a recepção pode passar o agendamento a outra pessoa sem cancelá-lo). Enquanto vale, a data da próxima aplicação — no painel e no prazo do retorno — é a da reserva: remarcar na Agenda muda só o agendamento, e o `planned_for`/`next_due_on` gravados só valem sem reserva viva. É derivado na leitura de propósito: um observador de agendamento escreveria no curso com a linha da Agenda já travada, na ordem de locks errada.
- Gerir o curso (agendar a próxima, encerrar) não depende de o prontuário de origem estar aberto: o curso não é do prontuário e os endpoints não olham a assinatura.

### Consequências
- ✓ Prontuário assinado continua imutável: nada novo é gravado em `medical_record_procedures`.
- ✓ Erro de lote/validade/autorização aparece na hora da baixa, não no faturamento.
- ✓ O curso aceita os esquemas `loading_tae` (ataque + trate e estenda), `prn` e `fixed`; o sistema registra o esquema escolhido, não o recomenda.
- ✗ Sala e janela de bloqueio da aplicação ficaram fora desta fase.

---

## 15. Orientações pós-procedimento

### Decisão
- Modelos da clínica (`care_guidance_templates`: texto, local, colírios, até 5 PDFs de 5 MB, com cabeçalho `%PDF-` conferido) e **emissões congeladas**
  (`care_guidance_issues`: retrato do conteúdo + cópia dos PDFs + hash). Editar ou apagar o modelo nunca muda o entregue.
- Tabelas próprias, **não** `MedicalRecordDocumentation`: o guard do prontuário assinado impediria emitir a orientação depois da assinatura.
- Clique duplo devolve a emissão que já existe (mesmo conteúdo, mesmo procedimento, poucos minutos).
- Emitir é ato do médico (`IssueReport`); a recepção vê o que foi entregue e imprime o PDF.

---

## 16. Exportação LGPD verificada pelo esquema

### Decisão
`PatientDataExporter` passa à **versão 5** (retornos e convites, condições, curso intravítreo, orientações e pronto atendimento) e ganha um teste que varre o
**esquema do banco**: toda tabela com chave estrangeira para `patients` precisa estar coberta por uma seção do arquivo, excluída por um motivo que a própria
exportação declara (`export_scope.not_included`) ou registrada como lacuna com o porquê. Tabela nova sem classificação reprova o teste.

### Consequências
- ✓ A exportação deixa de ficar para trás quando uma fase cria tabela (foi assim que o recall e o convite quase ficaram de fora).
- ✗ O teste prova que a tabela foi **classificada**, não que a seção exporta todas as colunas; o conteúdo das seções novas tem teste próprio.

## O que este desenho NÃO prova

- Não prova que a pessoa por trás do link é quem diz ser — para isso existe a conferência no
  CFM (item 4), que é humana.
- Não torna a regra clinicamente correta: registra **quem** a aprovou. Os intervalos que a
  EasyEye propõe como ponto de partida (`config`/seeder de rascunhos) são **proposta não
  validada** até a liberação.
- Não substitui o julgamento do médico assistente: o recall lembra, não decide conduta.
