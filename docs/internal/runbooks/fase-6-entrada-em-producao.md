# Fase 6 — checklist de entrada em produção

Retornos de pacientes crônicos, validação clínica, convite por WhatsApp, curso de injeções intravítreas (anti-VEGF), série aberta
da Agenda e orientações pós-procedimento. Decisões e motivos: [ADR 0002](../auditorias/adr/0002-validacao-clinica-e-recall.md).
Operação de cada parte: [validação clínica](validacao-clinica-protocolos-de-retorno.md) · [convite por WhatsApp](convite-de-retorno-whatsapp.md).

**Princípio de entrada:** tudo nasce desligado ou sem regra. Subir o código não envia WhatsApp, não gera retorno por protocolo e
não muda nenhuma tela de quem não tem o recurso. Cada degrau abaixo é uma decisão separada, com quem decide e como voltar atrás.

## 1. Deploy (uma vez, nesta ordem)

1. `php artisan migrate --force` — 10 migrations `2026_10_22_*`. Em PostgreSQL elas criam a trava que congela o conteúdo das versões
   de protocolo; em outro banco vale só a verificação noturna.
   - `…_backfill_patient_recall_feature` liga `has_patient_recall` em **Pro** e **Premium**, desliga nos demais e nunca sobrescreve valor já gravado.
   - `…_fill_exam_type_canonical_keys` preenche a chave canônica dos tipos de exame existentes (base do mapeamento de exames do recall).
   - `…_add_recall_support_indexes` cria 2 índices (`medical_records` por paciente/data e `schedules` por clínica/paciente/data) com `CREATE INDEX CONCURRENTLY` em PostgreSQL: não trava a escrita, mas em tabela grande demora — rode fora do pico.
2. `php artisan db:seed --class=RecallProtocolDraftsSeeder --force` e `--class=ClinicalReviewerAttestationSeeder --force` — ambos idempotentes.
   O primeiro cria **só rascunhos** (nenhuma regra clínica entra em uso sem o Revisor).
3. `php artisan config:cache && php artisan queue:restart` (o agendador já traz os comandos `recall:*` e `clinical-review:expire`).
4. Conferir `php artisan schedule:list`: `recall:evaluate-dirty` (1 min), `recall:reconcile` (02:30), `recall:verify-integrity` (05:15),
   `recall:send-invites` (de hora em hora), `recall:requeue-pending` (10 min), `clinical-review:expire` (03:20).
5. Fila `database` com worker de `default`; os comandos acima não precisam de fila nova. Reverb não muda.

## 2. Verificação logo após o deploy

| O que | Como | Esperado |
|---|---|---|
| Integridade dos protocolos | `php artisan recall:verify-integrity` | sai 0 (sem versão suspensa/retirada) |
| Conciliação sem efeito colateral | `php artisan recall:reconcile --entity=<id da clínica piloto>` | sem erro; a lista de Retornos da clínica piloto mostra só "retorno pedido pelo médico" |
| Convite não sai sozinho | `php artisan recall:send-invites --dry-run` | zero convites (chaves desligadas) |
| Menu | clínica Pro/Premium vê **Retornos**; plano sem o recurso não vê | conforme `has_patient_recall` |
| Textos | `./vendor/bin/pest tests/Unit/Recall tests/Unit/ClinicalReview tests/Unit/CareGuidanceTextsTest.php` | verde (pt_BR e en) |

## 3. Degraus de liberação (cada um é decisão de uma pessoa)

| # | Degrau | Quem decide | Pré-requisito | Como voltar |
|---|---|---|---|---|
| 1 | Recurso `has_patient_recall` no plano | dono do SaaS (Manager → Planos) | — | desligar o recurso no plano |
| 2 | Protocolo **liberado** (versão `released`) | dono do SaaS, depois do aceite do Revisor | Revisor convidado, parecer enviado, CRM/UF e RQE conferidos no CFM | suspender/retirar a versão no Manager |
| 3 | Clínica **adota** o protocolo | médico da clínica (Retornos → Configurar) | passo 2 | desativar o protocolo na tela de ativação |
| 4 | Convite por WhatsApp — chave 2 (Manager, clínica) | dono do SaaS | modelo `easyeye_lembrete_retorno` **aprovado na Meta no número que envia** | desligar a chave |
| 5 | Convite por WhatsApp — chave 3 (clínica) e *contato automático* | administrador/médico da clínica | passos 3 e 4 | desligar a chave |
| 6 | Pausa global (chave 1) | dono do SaaS | — | fica **sempre** disponível: é o botão de emergência |

Sem os degraus 2 e 3 o recall só mostra o retorno que o médico pediu no prontuário e o curso de injeções; isso já funciona e não envia nada.

## 4. Pendências que NÃO são de código (bloqueiam o degrau indicado)

- **Degrau 2:** Revisor Clínico real — convite, parecer e conferência manual no portal do CFM. Os intervalos do seeder são **proposta não validada**.
- **Degrau 4:** aprovação do modelo na Meta, número a número. Se a Meta reclassificar para *Marketing*, parar e decidir.
- **Antes de ligar para uma clínica de verdade:** revisão jurídica da Política de Privacidade (a versão 1.0 em `resources/legal/` cobre lembretes "quando habilitado
  pela clínica", mas **não menciona o Revisor Clínico** — dados de nome, CRM/RQE, e-mail e IP do parecer — nem o registro do consentimento de lembretes de retorno).
  Publicar texto novo é versão nova em `term_versions` (`PrivacyPolicySeeder` preserva o que existe); não foi publicada sem aval jurídico.
- **Decisão do dono (ADR 0002 item 11):** "AO" cobre qualquer olho. Um exame sem lateralidade pode suprimir o lembrete do olho não examinado. Rever com o Revisor.
- **Piloto:** uma clínica, 5 convites/dia no 1º dia (o ramp-up já faz isso), acompanhar respostas e SAIR por uma semana antes da próxima.

## 5. Monitoramento

- Falha de `recall:verify-integrity` ou `recall:reconcile` = alerta (o comando **falha de propósito** para o monitoramento ver). `recall:verify-integrity` falho significa
  versão suspensa/retirada: consultar o Manager → Protocolos de retorno (governança da versão) antes de qualquer outra coisa.
- Fila de convites: mensagens `pending` há mais de 5 min são reenfileiradas e, após 3 dias, canceladas (`recall:requeue-pending`).
- Convites `suppressed` com `error_code = recall_<motivo>` são esperados (consentimento retirado, SAIR, pausa, regra suspensa); número alto de um só motivo merece olhar.
- Sentry está ligado em `production` e `testing`: exceções dos serviços de recall chegam lá.

## 6. Limites assumidos (não esconder do cliente)

- A conferência do Revisor no CFM é humana; o link sozinho nunca libera regra.
- SAIR casa o **celular**, não a pessoa: familiares que dividem o aparelho perdem a autorização juntos; no número do EasyEye atinge todas as clínicas que enviam por ele.
- Não há reconciliação com a Meta de quem bloqueou o número fora do SAIR (o webhook de falha `opted_out` é o único sinal).
- A lista de Retornos reflete o ato clínico em até ~1 minuto (outbox); a conciliação noturna cobre `saveQuietly`, importação e prazos que passaram com o relógio.
- O recall **lembra**, não decide conduta; o intervalo de cada injeção é do médico (2–16 semanas).
- Sala e janela de bloqueio para a aplicação intravítrea ficaram **fora de propósito** desta fase.
- Exportação LGPD: o formato é a versão 5; pacientes exportados antes não ganham as seções novas retroativamente.
