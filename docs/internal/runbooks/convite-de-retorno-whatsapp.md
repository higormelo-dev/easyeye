# Convite de retorno por WhatsApp — roteiro para ligar e operar

Decisões e motivos: [ADR 0002](../auditorias/adr/0002-validacao-clinica-e-recall.md), itens 9 e 13. Termos: [glossário](../glossario.md).
Texto do modelo da Meta e regras de envio: [integração WhatsApp](../integracoes/whatsapp-gupshup.md) (§6.1 e §7).

O convite lembra o paciente de **voltar** (consulta ou exame). Ele nunca diz **por quê**: o texto leva só o primeiro nome e a clínica.
Sai por WhatsApp para quem **autorizou** no cadastro; quem não autorizou (ou respondeu SAIR) fica só na **lista de ligação**.

## As três chaves

O envio só acontece com as **três** abertas (e o sistema reconfere no momento de enviar):

| # | Chave | Quem mexe | Onde |
|---|---|---|---|
| 1 | **Pausa global** | Admin do SaaS | Manager → WhatsApp → aba *Número do EasyEye* → *Convites de retorno* |
| 2 | **Convite de retorno liberado** da clínica | Admin do SaaS | Manager → WhatsApp → *Configurar* (clínica) → *Convite de retorno* (nasce **desligada**) |
| 3 | **Enviar convites de retorno por WhatsApp** | Administrador/médico da clínica | Retornos → *Configurar* → *Convite por WhatsApp* (nasce **desligada**) |

Além das chaves, a clínica precisa de: plano com o recurso de retornos, WhatsApp ativo (número próprio ou do EasyEye), assinatura em dia
e, para o envio **automático** de protocolo, o *contato automático* ligado na ativação do protocolo (o retorno pedido pelo médico só depende da chave 3).

## Antes de ligar a primeira clínica (uma vez)

1. **Aprovar o modelo na Meta**, em **cada número que envia** (o do EasyEye e os próprios): `easyeye_lembrete_retorno`, Utilidade, pt_BR,
   corpo/rodapé/botões exatamente como em §6.1 da integração. Se a Meta reclassificar para *Marketing*, **parar** e decidir (tarifa e consentimento diferentes).
2. Conferir o **webhook** do número (botões e SAIR dependem dele): Manager → WhatsApp → *Verificar app*.
3. Escolher a clínica **piloto** (uma só, com o recepcionista treinado) e deixar o teto diário de fábrica.

## Ligar uma clínica

1. Manager → WhatsApp → *Configurar* → ligar **Convite de retorno** → salvar.
2. Na clínica: *Retornos → Configurar → Convite por WhatsApp* → ligar, conferir janela (padrão 09:00–18:00, seg–sex), intervalo (30 dias)
   e teto. Ao ligar, o **ramp-up** recomeça: **5 convites/dia** na 1ª semana, 10 na 2ª, 20 na 3ª, depois só o teto da clínica.
3. Coletar a **autorização** dos pacientes: caixa *Autoriza lembretes de retorno por WhatsApp* na aba *Contato* do cadastro (precisa da marca
   *Este celular tem WhatsApp*). Sem ela o paciente aparece na lista, mas só para ligação.
4. Conferir **antes** de deixar o robô rodar: `php artisan recall:send-invites --entity=<id da clínica> --dry-run` lista quantos sairiam, sem gravar.
   A recepção também pode convidar **na mão** (botão *Enviar convite* da lista de Retornos, com a prévia do texto exato).

## No dia a dia

- `recall:send-invites` roda de hora em hora (minuto 05); `recall:requeue-pending` a cada 10 min (reenfileira o que ficou sem job e cancela o que
  não saiu em 3 dias). Os dois estão no agendador (`routes/console.php`).
- Na lista de Retornos: *Convite enviado/entregue/lido*, **Pediu para agendar** (sobe para o topo — a recepção só marca) e *Disse que já agendou*
  (conferir a agenda). A resposta vale 30 dias.
- Manager → WhatsApp → gaveta da clínica: **convites enviados/respondidos (30 dias)**.
- Um convite por paciente por dia; no máximo 3 automáticos por retorno por ciclo; recusas por regra não derrubam o comando (só erro inesperado).

## Pausar (kill-switch)

- **Tudo, na hora:** Manager → *Convites de retorno* → *Pausar todos os convites* (motivo ≥ 10 caracteres; a tela mostra **quantas clínicas e retornos**
  são afetados). O que já estava na fila é **cancelado** no momento do envio (`suppressed`, `error_code = recall_paused_global`). Fica na auditoria.
- **Uma clínica:** desligar a chave 2 (Manager) ou a 3 (a clínica). Idem para a fila.
- **Só um protocolo:** desligar o *contato automático* ou suspender a versão (freio de mão do protocolo) — retorno de regra suspensa não é convidado.
- **Só uma tarefa opcional** (ex.: OCT de nervo óptico): o médico a desliga em Retornos → *Configurar*; o convite para de sair **na hora**, inclusive o que já estava
  na fila (`recall_nothing_to_invite`), embora o retorno em si só feche na próxima reavaliação do paciente (poucos minutos). O mesmo vale para o **curso de
  injeções** concluído, interrompido ou pausado pelo médico: não se convida para um tratamento que acabou.
- *Liberar* tira só a pausa: cada clínica segue com as próprias chaves.

## SAIR, VOLTAR e a autorização

- **SAIR** (ou PARAR/STOP…) descadastra o número **e retira a autorização de lembretes** de quem tem aquele celular — no app próprio, só os pacientes da
  clínica; no número do EasyEye, só as clínicas que enviam por ele (as **sem app próprio**) — quem manda pelo próprio app não recebeu o SAIR e a autorização dele segue.
- **VOLTAR** reativa o número, **não** devolve a autorização: só um novo consentimento no cadastro.
- O descadastro que vem da própria Meta (usuário bloqueou/optou por sair) tem o mesmo efeito, com o motivo registrado.

## Problemas comuns

| Sintoma | Causa provável | O que fazer |
|---|---|---|
| Botão *Enviar convite* não aparece | Alguma das 3 chaves fechada, retorno agendado/adiado/dispensado, paciente sem autorização, com SAIR ou bloqueio de contato | Ver o motivo na prévia (quando o botão existe) ou conferir as chaves; paciente sem autorização → ligar |
| Prévia: "limite diário atingido" | Teto do dia (inclui o ramp-up) | Esperar o dia seguinte; conferir *Configurar → Convite por WhatsApp* (mostra o teto de hoje e quantos saíram) |
| Prévia: "recebeu convite há pouco tempo" | Intervalo mínimo entre convites do mesmo paciente (30 dias por padrão, piso 7) | Contatar por telefone e registrar o contato |
| Convite `suppressed` com `recall_*` | Algo mudou entre enfileirar e enviar (consentimento, SAIR, pausa, plano sem o recurso de retorno — `recall_feature_off` —, retorno tratado, regra suspensa) — é o comportamento esperado | Nada a fazer; o código diz o motivo (`recall_no_consent`, `recall_opted_out`, `recall_paused_global`, `recall_nothing_to_invite`…) |
| Convite `pending` parado | Automático fora da janela de horário (volta quando abrir) ou fila parada | `recall:requeue-pending` reenfileira; passa de 3 dias, é cancelado (`recall_expired`) |
| Convite `skipped` | Falha transitória do WhatsApp (saldo da carteira, token…) ou acesso da clínica bloqueado | Resolver o alerta do WhatsApp; a rodada seguinte cria nova onda no dia seguinte |
| Muitos `failed` | Número com problema de qualidade na Meta | **Pausar tudo** e investigar antes de religar |

## O que o sistema **não** faz

- Não convida quem não autorizou (nem "presume" consentimento) e nunca cita condição, exame ou diagnóstico na mensagem.
- Não agenda sozinho: o botão *Quero agendar* avisa a recepção, que marca o horário.
- Não conversa em texto livre: só os dois botões do convite têm efeito (digitar "sim" não agenda nem confirma nada).
- Não substitui a busca ativa: retorno atrasado demais (padrão 180 dias) e paciente sem autorização seguem para a ligação.
