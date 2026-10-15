# Validação clínica dos protocolos de retorno — roteiro para a equipe EasyEye

Para quem opera o **Manager** (admin do SaaS). Decisões e motivos: [`docs/adr/0002`](../adr/0002-validacao-clinica-e-recall.md).
Termos: [`docs/glossario.md`](../glossario.md).

Regra do produto: **nenhuma regra de intervalo que a EasyEye escreveu chega a uma clínica sem a validação de um oftalmologista
externo (o Revisor Clínico) e sem a conferência dele no portal do CFM**. O sistema só *prova* quem validou, quando e qual
versão; ele não torna a regra clinicamente correta. Os intervalos que vêm no sistema são **proposta inicial, não validada**.

## O que existe

| Onde | Para quê |
|---|---|
| Manager → **Protocolos de retorno** | As regras por condição e as versões de cada uma (rascunho → congelar → liberar). |
| Manager → **Revisões clínicas** | Cadastro dos Revisores, convites, e a **conferência do parecer** (aceite). |
| `/revisao-clinica` (público) | Onde o Revisor lê as regras, decide e se identifica. Sem login. |

## Antes de começar (uma vez)

1. **Escolher o Revisor** — oftalmologista com **RQE**, que aceite o encargo. Contrato/sigilo e o prazo de **revalidação**
   (padrão 24 meses, `config/recall.php`) são combinados **fora do sistema**.
2. **Confirmar com o jurídico** o prazo de guarda do atestado (o plano adota: enquanto a versão existir + 5 anos).
3. Conferir que o **e-mail transacional** funciona no ambiente (o convite sai por e-mail) e que o agendador roda
   (`recall:verify-integrity` às 05:15 e `clinical-review:expire` às 03:20).

## Passo a passo

### 1. Cadastrar o Revisor
Revisões clínicas → aba **Revisores** → *Novo Revisor*. Nome, e-mail, **CRM (número e UF)**, **RQE**. O telefone serve para
passar o código de acesso. CRM e RQE são comparados **normalizados** (`12.345` = `012345`); duplicidade é recusada.

### 2. Revisar e congelar a versão
Protocolos de retorno → o protocolo → a versão em **rascunho** → conferir regra/justificativa/referências → **Congelar para
revisão**. Depois de congelada o conteúdo **não muda mais** (nem por SQL: trigger no banco). Para mudar, abra uma nova versão.

### 3. Convidar
Revisões clínicas → *Novo convite*: escolha o Revisor e as versões congeladas (um parecer cobre o **conjunto** escolhido).
- **Prazo do link**: 7 a 30 dias.
- **Código de acesso (opcional)**: 2ª barreira. Gerado pelo sistema, aparece **uma vez** — passe ao Revisor **por outro
  canal** (telefone, mensagem). Nunca no e-mail.
- O link **só vai por e-mail**; o Manager nunca o mostra. *Reenviar* troca o token e o link anterior morre.
- *E-mail não saiu*: o convite fica criado como "Não enviado"; use **Reenviar** depois de verificar o envio.

### 4. O Revisor responde
Ele abre o link (sem login), lê cada regra (a mesma tela que a clínica verá), decide por versão — **Aprovo / Aprovo com
ajustes / Não aprovo** (justificativa obrigatória se não aprovar) — digita a **própria identificação** (CRM/UF, RQE, e-mail) e
concorda com a declaração. O envio é **único**. Você recebe o parecer na lista (situação "Parecer recebido") e ele, um recibo
por e-mail com um código de verificação.

> A página **não mostra dado de paciente**. O CRM/RQE do cadastro **não** vem preenchido: o Revisor declara de próprio punho,
> e o sistema compara com o cadastro (divergência é sinalizada).

### 5. Conferir no CFM (o aceite)
Na linha do convite: **Conferir**. A tela mostra o que ele **declarou**, o cadastro lado a lado, as divergências e as decisões.
1. Abra o portal do **CFM** e confira o **CRM/UF** e o **RQE** *declarados* (não os do cadastro).
2. Marque as duas conferências. Sem as duas **não há aceite**.
3. **Aceitar**: as versões que ele aprovou viram *Validada*; as que ele pediu ajuste ou não aprovou viram *Recusada pelo Revisor*.
4. **Recusar** (não conseguiu confirmar): nota obrigatória; as versões **continuam em revisão** e podem ir a outro convite.
- Divergência declarado × cadastro: aceitar exige **nota** (ex.: "cadastro estava errado; conferido como declarado").
- O aceite é **append-only**: não se edita nem se apaga.

### 6. Liberar
Protocolos de retorno → a versão *Validada* → **Liberar às clínicas**. O sistema confere de novo a prova (aceite do CFM +
decisão "aprovo" + mesmo hash + conteúdo íntegro). A versão liberada anterior vira **Substituída** (continua valendo para quem
já a adotou). Fica registrado o **prazo de revalidação**; quando perto de vencer o Manager avisa (não bloqueia o uso).

### 7. Se o Revisor pediu ajuste
A versão fica *Recusada pelo Revisor*. Abra **Nova versão** a partir dela, escreva *o que mudou*, congele e convide de novo —
o Revisor vê o **diff** contra a versão anterior.

## Freio de mão

| Situação | Ação | Efeito |
|---|---|---|
| Dúvida clínica numa regra **em uso** | **Suspender** (motivo ≥ 20 caracteres) | Sai de uso **na hora** em todas as clínicas. Reversível. |
| Passou a dúvida | **Retomar** | Volta ao estado de antes (se uma mais nova foi liberada nesse meio tempo, volta como *Substituída*). Refaz a verificação de integridade. |
| Regra errada, sem volta | **Aposentar** (motivo ≥ 20) | Definitivo. |
| Achou erro **antes** de liberar | **Retirar da revisão** (motivo ≥ 10) | Convites pendentes dela são revogados. |

Cada ação grava um **evento de governança** (quem, quando, motivo) na mesma transação; se o evento não grava, a ação não vale.

## Alarme de integridade (`recall:verify-integrity`)

Todo dia às 05:15 o sistema refaz o hash do **texto gravado** de cada versão em uso ou em revisão. Se **divergir**:
- liberada/substituída → **suspensa** na hora (motivo "Verificação de integridade…"); em revisão/validada → **retirada**;
- grava o evento `integrity_failed` (hash gravado × real) e o **comando falha** (Sentry + status do agendador).

**O que fazer:** tratar como incidente. Descobrir como o texto/hash foi alterado (acesso direto ao banco? restauração de backup?),
corrigir a causa, e só então **Retomar** (a retomada recusa versão com hash divergente). Não existe "consertar o hash": versão
adulterada é aposentada e a regra volta pelo caminho normal (nova versão → revisão → aceite → liberação).

## Problemas comuns

| Sintoma | Causa provável | O que fazer |
|---|---|---|
| Revisor diz que o link "não está disponível" | Prazo vencido, link substituído por um **Reenviar**, convite revogado, ou parecer já enviado. A página **não diz qual** (de propósito). | Conferir a situação na lista; *Reenviar* ou criar novo convite. |
| Pede código de acesso e erra | 5 erros travam o convite por 15 min (até o código certo é recusado nesse intervalo). | Esperar 15 min, ou *Reenviar* (zera as tentativas) e conferir o código. |
| Não aparece "Conferir" | O convite ainda não foi respondido (ou já foi conferido: o botão vira "Ver parecer"). | Aguardar o parecer. |
| *Liberar* recusado: "falta a prova" | O aceite não é `accepted`, ou a decisão do Revisor não foi "aprovo", ou o hash difere. | Ver a aba **Validação** da versão. |
| Duas pessoas conferindo ao mesmo tempo | Só um aceite vale; o outro recebe "não está mais aguardando conferência". | Atualizar a página. |
| Convite duplicado recusado | Já existe convite **pendente** do mesmo Revisor para o mesmo conjunto. | Reenviar o existente. |

## O que o sistema **não** faz

- Não confirma que a pessoa do link é quem diz ser: por isso a conferência **humana** no CFM (item 5) é obrigatória.
- Não decide a conduta do médico: o recall lembra o paciente de voltar; não prescreve.
- Não automatiza a consulta ao CFM (não há API pública estável): fica **registrado** quem conferiu, quando e o quê.
- Não valida a regra clinicamente: registra **quem** a aprovou.
