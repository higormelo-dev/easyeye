# Integração WhatsApp via Gupshup (API oficial da Meta)

> **Status:** proposta técnica — **nada implementado ainda**. Levantamento
> feito em **03/10/2026** a partir do código atual e da documentação oficial
> da Gupshup e da Meta (links em [Referências](#14-referências)).
>
> **Objetivo:** enviar pelo WhatsApp oficial (Gupshup como BSP) a confirmação
> de consulta, a pesquisa de satisfação e as próximas ações (lembrete de
> retorno, aviso de laudo pronto etc.), substituindo aos poucos a Z-API.
>
> **Modelo:** um **número do EasyEye** envia para todas as clínicas (padrão) e
> a clínica pode, se quiser, **conectar o próprio número** (seção 4).

## Sumário

1. [Resumo](#1-resumo)
2. [Situação atual (Z-API)](#2-situação-atual-z-api)
3. [O que muda com a API oficial](#3-o-que-muda-com-a-api-oficial)
4. [Modelo de números (padrão do EasyEye e número próprio)](#4-modelo-de-números)
5. [Passo a passo de cadastro (Meta, Gupshup e clínicas)](#5-passo-a-passo-de-cadastro-meta-gupshup-e-clínicas)
6. [Templates](#6-templates)
7. [Arquitetura proposta no EasyEye](#7-arquitetura-proposta-no-easyeye)
8. [Consentimento e descadastro (LGPD e Meta)](#8-consentimento-e-descadastro-lgpd-e-meta)
9. [Segurança](#9-segurança)
10. [Custos](#10-custos)
11. [Plano de implementação](#11-plano-de-implementação)
12. [Riscos e perguntas para a Gupshup](#12-riscos-e-perguntas-para-a-gupshup)
13. [Pendências no código atual](#13-pendências-no-código-atual)
14. [Referências](#14-referências)

---

## 1. Resumo

| | Hoje (Z-API) | Com Gupshup |
|---|---|---|
| Tipo de API | não oficial (sessão do WhatsApp Web) | oficial (Meta Cloud API, via Gupshup) |
| Mensagem iniciada pela clínica | texto livre | **template aprovado pela Meta** |
| Resposta do paciente | digita "1/2" ou "1 a 5" | **botões** ("Confirmar", "Cancelar", notas) |
| Ligação resposta → consulta | pelo telefone (mensagem mais recente) | **payload do botão** + id da mensagem respondida |
| Entregue / lido / falhou | não registra | **webhook de status** |
| Risco de bloqueio do número | existe (uso não oficial) | dentro da política da Meta |
| Custo | mensalidade da instância | **por template entregue** (seção 10) |

Decisões recomendadas:

- **Modelo misto:** um **número do EasyEye** envia para todas as clínicas
  (padrão) e a clínica pode **conectar o próprio número** — o sistema passa a
  usar o número dela automaticamente (seção 4).
- EasyEye entra como **ISV / Tech Provider** da Gupshup (é o que permite
  conectar os números das clínicas).
- Migração **gradual**, com a Z-API como alternativa até o fim.
- Confirmação e pesquisa como templates de **Utilidade** (sem conteúdo
  promocional), com botões cujo payload identifica a mensagem enviada.

---

## 2. Situação atual (Z-API)

Mapa do que existe hoje (para não perder comportamento na migração):

| Item | Onde | Comportamento |
|---|---|---|
| Cliente HTTP | `app/Services/WhatsApp/ZApiClient.php` | classe concreta, injetada direto em 5 pontos; sem interface |
| Regras e textos | `app/Services/WhatsApp/WhatsAppService.php` | textos fixos em português; parser de "1/2", "sim/não", "1 a 5" |
| Configuração | tabela `whatsapp_settings` | uma linha por clínica + uma **global** (`entity_id` nulo, padrão); credenciais cifradas (`encrypted:array`) no formato Z-API |
| Mensagens | tabela `whatsapp_messages` | `direction`, `kind` (confirmation/survey/ack/reply), `status`, `zapi_message_id`, `survey_score` |
| Confirmação | `whatsapp:send-confirmations` | de hora em hora, 08h–20h; consulta *Agendada* dentro de `confirmation_hours_before` (padrão 24h); uma vez por consulta |
| Pesquisa | `whatsapp:send-surveys` | de hora em hora, 09h–20h; consulta *Atendida* há `survey_delay_hours` (padrão 2h), no máximo 3 dias |
| Envio | `SendWhatsAppMessageJob` | fila, 3 tentativas, idempotente (índice único por consulta e tipo) |
| Webhook | `POST /api/whatsapp/webhooks/{token}` | token por configuração; só eventos `ReceivedCallback` de texto |
| Resposta | `ProcessWhatsAppInboundJob` | casa pelo telefone; confirmação vale 7 dias, pesquisa 14 dias |
| Código de verificação do cadastro | `SendPhoneVerificationCodeJob` | sempre pela instância global |
| Manager | `Manager → WhatsApp` | liga/desliga por clínica, horas de antecedência, atraso da pesquisa, credenciais Z-API, teste de conexão |
| Testes | `tests/Feature/WhatsApp/WhatsAppIntegrationTest.php` | 28 testes, todos com o driver *mock* |

Pontos acoplados à Z-API (precisam mudar): formato das credenciais
(`instance_id`, `instance_token`, `client_token`), coluna
`whatsapp_messages.zapi_message_id`, parsing do payload direto no
`WhatsAppWebhookController`, registro automático de webhook e teste de
conexão no Manager, e o modelo de **texto livre** em todas as mensagens.

---

## 3. O que muda com a API oficial

1. **Toda mensagem iniciada pela clínica precisa ser template aprovado** pela
   Meta, **no número que vai enviar**. Texto livre só é permitido dentro da
   **janela de atendimento de 24h**, que abre quando o paciente manda
   mensagem (inclusive ao clicar num botão).
2. **Botões de resposta rápida** devolvem um `payload` definido por nós a
   cada envio — a resposta chega já identificada, sem interpretar texto.
3. **Status de entrega** (`sent`, `delivered`, `read`, `failed`) chegam por
   webhook.
4. **Opt-in** do paciente é exigência da Meta (seção 8).
5. O formato de envio e de webhook passa a ser o da **Meta** (a Gupshup
   repassa no formato v3, "passthrough").

---

## 4. Modelo de números

**Modelo misto (recomendado):** um número do EasyEye atende todas as
clínicas por padrão, e cada clínica pode, se quiser, conectar o próprio
número. É a mesma lógica que já existe hoje com a Z-API
(`WhatsAppSetting::sendingCredentials()`: usa o número da clínica, se
configurado; senão, o número global do EasyEye).

Para ter as duas opções, o EasyEye precisa ser **parceiro ISV / Tech
Provider** da Gupshup — é o que permite conectar números das clínicas. O
número do EasyEye entra como mais um app na mesma conta de parceiro. (A
Gupshup separa *cliente direto* de *ISV / TP*: se quem recebe a mensagem
percebe que fala com o seu cliente — a clínica —, você é ISV/TP.)

| | Número do EasyEye (padrão) | Número próprio da clínica (opcional) |
|---|---|---|
| Quem o paciente vê | nome de exibição do EasyEye; a clínica vai no texto | nome da clínica |
| Consentimento do paciente | cita o EasyEye como remetente | cita a clínica |
| Limite de envio e qualidade | **compartilhados** por todas as clínicas | da clínica |
| Mensagem escrita pelo paciente | chega no número do EasyEye | chega na clínica |
| Custo | na conta do EasyEye (repassar) | da clínica ou repassado (contrato) |
| Templates | aprovados uma vez | aprovados no número da clínica |
| Cadastro da clínica | nenhum | link de *Embedded Signup* (seção 5.4) |

### 4.1 Regras da Meta para o número compartilhado

1. **Quem aparece é o EasyEye.** Cada conta do WhatsApp Business pertence a
   uma única empresa, e o modelo de "enviar em nome de outra empresa"
   (*On-Behalf-Of*) foi descontinuado pela Meta. O número compartilhado é
   do EasyEye avisando sobre consultas das clínicas — por isso **todo
   template precisa identificar a clínica no texto** ("Sua consulta na
   Clínica X…").
2. **Consentimento:** o texto aceito pelo paciente cita o EasyEye como
   remetente (seção 8).
3. **Limite de envio compartilhado:** a Meta limita quantos pacientes
   diferentes recebem mensagem **fora da janela de atendimento** em 24h.
   Começa em **250** e sobe para **2.000, 10.000, 100.000 e ilimitado**
   conforme verificação da empresa e qualidade. O limite é calculado no
   **portfólio** (vale para todos os números do EasyEye) e é **dividido entre
   todas as clínicas** do número compartilhado. Uma clínica que gere
   bloqueios ou denúncias derruba a qualidade de todas — monitorar por
   clínica e poder suspender a clínica do número compartilhado.
4. **Mensagem escrita pelo paciente** (ex.: "posso remarcar?") chega no
   número do EasyEye, não na clínica. Os botões funcionam normalmente (o
   payload identifica clínica e consulta). Para texto livre: resposta
   automática com o contato da clínica e, numa fase futura, caixa de
   mensagens da clínica dentro do sistema (seção 7.11).
5. **Custo:** tudo é cobrado na conta do EasyEye — repassar no plano
   (franquia de mensagens) ou cobrar por uso, com o consumo registrado por
   clínica (seção 10).
6. **Templates:** aprovados uma vez no número do EasyEye, com o nome da
   clínica como variável.

### 4.2 Número próprio da clínica

- O paciente vê o nome da clínica; limite, qualidade e custo são dela
  (cobrança pela Gupshup ou repassada, conforme o contrato).
- Os templates precisam ser aprovados no número dela — automatizar pela API.
- As conversas livres chegam na própria clínica, se ela mantiver o WhatsApp
  Business no celular junto com a API (uso simultâneo, *coexistence*, a
  confirmar com a Gupshup).
- Pode ser oferecido como recurso de plano superior.

### 4.3 Recomendação

1. Começar com **todas as clínicas no número do EasyEye** (um só cadastro,
   templates aprovados uma vez).
2. Oferecer **"usar meu próprio número"** como opção. Ao conectar, os envios
   novos passam a sair pelo número da clínica, sem perder o histórico nem as
   respostas a mensagens já enviadas pelo número do EasyEye (seção 7.11).

---

## 5. Passo a passo de cadastro (Meta, Gupshup e clínicas)

### 5.1 EasyEye na Meta

1. Business Manager do EasyEye em <https://business.facebook.com> com
   **verificação de empresa** concluída.
2. Criar um **app do tipo business** no App Dashboard da Meta: domínio,
   ícone, URLs de política de privacidade e termos de uso, categoria
   *Messaging*.
3. Adicionar o produto **WhatsApp** e aceitar os **termos de Tech Provider**
   da Meta.

### 5.2 EasyEye na Gupshup (parceiro)

1. Contato com a Gupshup (CSM) para criar a **solução conjunta** (*partner
   solution*) no App Dashboard da Meta e acertar contrato, SLA e cobrança.
2. Cadastro no **portal de parceiros** (<https://partner.gupshup.io>),
   informando a solução; aguardar aprovação.
3. Gerar **segredo de cliente** do parceiro (usado no login da Partner API —
   seção 7.4).

> A página antiga de onboarding de ISV da Gupshup deixou de ser mantida em
> 31/01/2025; a referência atual é a documentação de parceiros
> (<https://partner-docs.gupshup.io>). Confirmar os passos vigentes com o CSM.

### 5.3 Número padrão do EasyEye

1. Separar um **número dedicado** para os avisos (sem uso no WhatsApp do
   celular, ou com uso simultâneo confirmado com a Gupshup).
2. Criar o **app do EasyEye** no portal de parceiros e conectar o número.
3. Meta aprova o **nome de exibição** (ligado à marca EasyEye).
4. Confirmar a **verificação de empresa** (5.1) — sem ela o limite de envio
   fica na faixa inicial (seção 4.1).
5. Criar os **templates** (seção 6), com o nome da clínica como variável, e
   aguardar a aprovação.
6. Registrar o **webhook** do app (seção 7.6).
7. No Manager: configuração **global** com provedor Gupshup; mensagem de
   teste; ligar confirmação e pesquisa nas clínicas.

### 5.4 Clínica com número próprio (opcional)

1. EasyEye cria um **app** na Gupshup para a clínica (Partner API ou portal).
2. EasyEye gera o **link de Embedded Signup** do app
   (`GET /partner/app/{appId}/onboarding/embed/link`) — **vale 5 dias**.
3. A clínica abre o link, entra com a conta Meta dela e conecta o número.
   - Número já usado no WhatsApp Business do celular: confirmar com a
     Gupshup o uso simultâneo (*coexistence*).
4. Meta aprova o **nome de exibição** (o nome da clínica que o paciente vê).
5. EasyEye cria os **templates** no número da clínica (seção 6) e aguarda a
   aprovação.
6. EasyEye registra o **webhook** do app (seção 7.6).
7. No Manager: a clínica passa a usar o número próprio; enviar mensagem de
   teste. Sem número próprio, ela continua no número do EasyEye.

Checklist da clínica com número próprio: número conectado · nome aprovado ·
templates aprovados · webhook ativo · teste enviado e recebido · opt-in dos
pacientes (seção 8).

---

## 6. Templates

### 6.1 Regras da Meta que afetam o desenho

- **Categorias:** confirmação e lembrete de consulta são **Utilidade**.
  Pesquisa de satisfação também é **Utilidade**, desde que **ligada a um
  atendimento específico**. Qualquer conteúdo promocional ou de venda
  (oferta, desconto, convite para outro serviço) transforma o template em
  **Marketing** (mais caro e com regras de opt-in mais estritas).
- **Botões:** até **10 botões** por template; até **10 respostas rápidas**;
  rótulo com até **25 caracteres**. Com **mais de 3 botões**, o WhatsApp
  mostra 2 e agrupa o resto em **"Ver todas as opções"**.
- **Corpo:** até 1.024 caracteres; variáveis exigem exemplo na criação.
- **Aprovação por número:** cada número precisa ter os templates aprovados —
  uma vez no número do EasyEye e de novo em cada número próprio de clínica.
- **Clínica sempre no texto:** no número do EasyEye o template precisa dizer
  de qual clínica é a consulta (variável `{{2}}` nos modelos abaixo).
- **Descadastro:** rodapé com a instrução para parar de receber.
- **Dados sensíveis:** template não deve levar diagnóstico, exame ou qualquer
  dado clínico — só o necessário para a consulta (nome, data/hora, médico,
  clínica).

### 6.2 Catálogo inicial (pt_BR)

**`easyeye_confirmacao_consulta`** — Utilidade

```text
Olá, {{1}}! Sua consulta na {{2}} está marcada para {{3}} com {{4}}.
Podemos confirmar sua presença?
```

Rodapé: `Para não receber mais avisos, responda SAIR.`
Botões (resposta rápida): `Confirmar` · `Cancelar` · `Quero remarcar`
Payload por envio: `confirm:<id da mensagem>`, `cancel:<id>`, `reschedule:<id>`

**`easyeye_pesquisa_satisfacao`** — Utilidade (sem promoção)

```text
Olá, {{1}}! Obrigado por escolher a {{2}}. De 1 a 5, como você avalia seu
atendimento de {{3}}?
```

Rodapé: `Para não receber mais avisos, responda SAIR.`
Botões: `5 - Excelente` · `4 - Bom` · `3 - Regular` · `2 - Ruim` · `1 - Péssimo`
Payload: `survey:<id da mensagem>:<nota>`

> Com 5 botões o paciente vê 2 e toca em "Ver todas as opções". Se o produto
> preferir tudo à vista, usar 3 botões (`Ótimo`, `Regular`, `Ruim`) e ajustar
> a escala — decisão de produto, a escala atual é 1 a 5.

**Código de verificação do cadastro** — template de **Autenticação** (botão
de copiar código), enviado pelo número do EasyEye.

**Respostas depois do clique** ("Consulta confirmada ✅", "Obrigado pela
avaliação") vão como **texto livre**: o clique abre a janela de 24h, então não
precisam de template e não são cobradas.

### 6.3 Próximas ações (cada uma com seu template)

| Ação | Categoria provável | Observação |
|---|---|---|
| Lembrete de retorno | Utilidade (se ligado a um tratamento em curso) | revisar texto com a Meta |
| Laudo / documento pronto | Utilidade | enviar **link autenticado** do Portal do Paciente, nunca o documento ou dado clínico no corpo |
| Aniversário, campanhas | **Marketing** | opt-in específico de marketing |

---

## 7. Arquitetura proposta no EasyEye

### 7.1 Visão geral

```text
whatsapp:send-confirmations ─┐
whatsapp:send-surveys ───────┼─► WhatsAppService ─► SendWhatsAppMessageJob ─► WhatsAppProvider
(próximas ações) ────────────┘        (regras)            (fila)              ├─ ZApiProvider   (atual)
                                                                               └─ GupshupProvider (novo)

Gupshup ─► POST /api/whatsapp/gupshup/webhook ─► valida segredo ─► fila ─► ProcessGupshupEventJob
                                                                          ├─ botão/texto → regra (ScheduleService)
                                                                          └─ status → whatsapp_messages
```

### 7.2 Abstração de provedor

Interface `App\Services\WhatsApp\Contracts\WhatsAppProvider` (nome a definir):

- `sendTemplate(WhatsAppSetting, telefone, template, idioma, parâmetros, botões)` → id da mensagem
- `sendText(WhatsAppSetting, telefone, texto)` → id (só dentro da janela de 24h)
- `health(WhatsAppSetting)` → número conectado, qualidade, limite

Implementações: `ZApiProvider` (embrulha o `ZApiClient` atual, **sem mudar
comportamento**; template vira o texto equivalente) e `GupshupProvider`. O
provedor é o da configuração usada no envio — a global (número do EasyEye)
ou a da clínica com número próprio (seção 7.11). As regras
(`WhatsAppService`) passam a falar em "tipo de mensagem + dados", e cada
provedor traduz para o seu formato.

### 7.3 Modelo de dados

| Tabela | Mudança |
|---|---|
| `whatsapp_settings` | `provider` (`zapi` \| `gupshup`); credenciais por provedor (Gupshup: `app_id`, número de origem); `gupshup_app_id` em coluna própria (roteamento do webhook); segredo do webhook **cifrado**. A linha **global** é o número do EasyEye; a linha da clínica, o número próprio (opcional) |
| `whatsapp_messages` | `provider`; `sender_app_id` (por qual app saiu — EasyEye ou clínica); renomear `zapi_message_id` → `provider_message_id` (manter o índice único de entrada); `template` (nome usado); `delivered_at`, `read_at`, `failed_at`, `error_code` |
| `patient_consents` / `ConsentType` | novo tipo de consentimento de comunicação por WhatsApp (seção 8) |

Migração dos dados atuais: `provider = 'zapi'` em tudo que existe.

### 7.4 Autenticação na Gupshup

| Token | Como obter | Validade | Uso |
|---|---|---|---|
| Token de parceiro | `POST https://partner.gupshup.io/partner/account/login` (e-mail + segredo de cliente) | **24h** | APIs de gestão (criar app, link de Embedded Signup) |
| Token do app (EasyEye ou clínica) | `GET https://partner.gupshup.io/partner/app/{appId}/token` | idempotente (devolve o existente) | envio de mensagens, assinatura de webhook, templates |

- Tokens em **cache (Redis)**, renovados antes de expirar; nunca em log.
- E-mail e segredo do parceiro em `.env` / cofre do servidor (globais do
  EasyEye); `app_id` de cada app (EasyEye e clínicas) no banco (não é
  segredo).

### 7.5 Envio

```http
POST https://partner.gupshup.io/partner/app/{APP_ID}/v3/message
Authorization: {TOKEN_DO_APP}
Content-Type: application/json
```

```json
{
  "messaging_product": "whatsapp",
  "recipient_type": "individual",
  "to": "5511999998888",
  "type": "template",
  "template": {
    "name": "easyeye_confirmacao_consulta",
    "language": { "code": "pt_BR" },
    "components": [
      {
        "type": "body",
        "parameters": [
          { "type": "text", "text": "Maria" },
          { "type": "text", "text": "Clínica Visão" },
          { "type": "text", "text": "10/10 às 14h30" },
          { "type": "text", "text": "Dr. João" }
        ]
      },
      {
        "type": "button", "sub_type": "quick_reply", "index": "0",
        "parameters": [{ "type": "payload", "payload": "confirm:<id da mensagem>" }]
      },
      {
        "type": "button", "sub_type": "quick_reply", "index": "1",
        "parameters": [{ "type": "payload", "payload": "cancel:<id da mensagem>" }]
      }
    ]
  }
}
```

Resposta: `messages[0].id` → gravar em `whatsapp_messages.provider_message_id`.

Observações:

- `Authorization` leva o token **sem** "Bearer".
- O payload usa o **id interno da mensagem** (UUID, não adivinhável) — nunca
  o id da consulta ou do paciente.
- Datas e horários formatados no idioma e fuso da clínica.
- Fila dedicada para WhatsApp, respeitando a faixa de limite da Meta
  (calculada por portfólio e compartilhada no número do EasyEye — seção 4.1).

### 7.6 Webhook

**Registro** (uma assinatura por app — o do EasyEye e o de cada clínica com
número próprio):

```http
POST https://partner.gupshup.io/partner/app/{APP_ID}/subscription
Authorization: {TOKEN_DO_APP}
Content-Type: application/x-www-form-urlencoded

tag=easyeye-v3
url=https://easyeye.app/api/whatsapp/gupshup/webhook
version=3
modes=MESSAGE,SENT,DELIVERED,READ,FAILED,TEMPLATE
meta={"X-EasyEye-Webhook-Secret":"<segredo do app>"}
```

O campo `meta` vira **headers** em cada chamada da Gupshup — é como o EasyEye
autentica o webhook (a documentação não traz assinatura HMAC).

**Recebimento** (`POST /api/whatsapp/gupshup/webhook`):

1. Identificar o app pelo `gs_app_id` do payload (app do EasyEye ou de uma
   clínica — roteamento na seção 7.11).
2. Comparar o header `X-EasyEye-Webhook-Secret` com o segredo do app usando
   `hash_equals`; diferente → 404.
3. Gravar o evento bruto mínimo, colocar na fila e responder **200 na hora**.
4. Idempotência pelo id da mensagem/status (índice único).

**Clique no botão** (formato Meta v3; `gs_app_id` é acrescentado pela
Gupshup — conferir a posição exata no primeiro payload real):

```json
{
  "object": "whatsapp_business_account",
  "entry": [{
    "changes": [{
      "field": "messages",
      "value": {
        "messaging_product": "whatsapp",
        "contacts": [{ "profile": { "name": "Maria" }, "wa_id": "5511999998888" }],
        "messages": [{
          "from": "5511999998888",
          "id": "wamid.XXXX",
          "type": "button",
          "context": { "from": "551133334444", "id": "<id da mensagem enviada>" },
          "button": { "payload": "confirm:<id da mensagem>", "text": "Confirmar" }
        }]
      }
    }]
  }]
}
```

**Status** (`value.statuses[]`): `status` = `sent` | `delivered` | `read` |
`failed`; em falha, `errors[]` com código e descrição → gravar em
`whatsapp_messages` (`delivered_at`, `read_at`, `failed_at`, `error_code`).

### 7.7 Regras de negócio

| Evento | Validações | Efeito |
|---|---|---|
| `confirm:<id>` | mensagem existe, é confirmação, mesma clínica, telefone igual ao destinatário, dentro de 7 dias, consulta ainda *Agendada* | **`ScheduleService::changeSituation`** → *Confirmada* (log, notificações, sala de espera) + resposta "Consulta confirmada" |
| `cancel:<id>` | idem | *Cancelada* com motivo **traduzido** + resposta |
| `reschedule:<id>` | idem | avisa a secretaria (pendência na agenda) + resposta com orientação |
| `survey:<id>:<nota>` | mensagem de pesquisa, dentro de 14 dias, nota 1–5 | grava `survey_score` + agradecimento |
| texto "1/2", "sim/não" | mantém o parser atual como alternativa | idem acima, casando pela mensagem mais recente do telefone |
| "SAIR" ou "PARAR" | — | revoga o consentimento (seção 8) |
| status `failed` | — | marca falha com código; alerta se a taxa de falha da clínica subir |

Hoje a resposta via WhatsApp altera a consulta direto, **sem** passar pelo
`ScheduleService` (não dispara o e-mail de mudança que o painel dispara) — a
migração corrige isso.

### 7.8 Telefones

- Guardar e enviar em **E.164** (`+5511999998888`; no payload da Meta, só
  dígitos com DDI).
- O `wa_id` devolvido pela Meta pode não ter o 9º dígito em números
  brasileiros: casar pelo `context.id`/payload, e no fallback por texto
  comparar DDD + últimos 8 dígitos.
- Corrigir números estrangeiros (hoje o `+` é ignorado e o número é
  corrompido — seção 13).

### 7.9 Manager

**Global (número do EasyEye):** provedor; estado do número (conectado,
qualidade, faixa de limite); **estado dos templates** (aprovado, pendente,
rejeitado — via webhook `TEMPLATE`); envio de teste.

**Por clínica:** usa o número do EasyEye ou o **próprio**; botão **"Conectar
número próprio"** (gera e mostra o link de Embedded Signup); estado do número
e dos templates da clínica; **suspender a clínica do número compartilhado**;
os ajustes atuais (antecedência da confirmação, atraso da pesquisa).

Acesso só para admin do SaaS (como hoje), com auditoria.

### 7.10 Observabilidade

- Painel de uso (entregues, lidas, falhas, confirmações, cancelamentos, nota
  média) por clínica e período — mesmo padrão da tela *Uso de IA*.
- Sentry para falhas de envio e de webhook; alerta para taxa de falha alta e
  template rejeitado.

### 7.11 Número padrão × número próprio

**Envio:** a escolha do número segue a regra atual (`sendingCredentials()`):
app Gupshup da clínica, se conectado e operacional; senão, app do EasyEye
(configuração global). Cada mensagem grava **por qual app saiu**, usado no
roteamento das respostas, na auditoria e na cobrança por clínica.

**Roteamento do webhook** (pelo `gs_app_id`):

| Evento vindo de | Como achar a clínica |
|---|---|
| App da clínica | a clínica é a dona do app |
| App do EasyEye — clique em botão | pelo payload (id da mensagem → clínica e consulta) |
| App do EasyEye — status | pelo id da mensagem enviada |
| App do EasyEye — texto livre | pelas mensagens recentes enviadas àquele telefone; se o paciente tiver consultas em mais de uma clínica, resposta genérica pedindo para usar os botões ou falar com a clínica |

Hoje, no número global da Z-API, a resposta é ligada à clínica só pelo
telefone; com o payload dos botões passa a ser exata.

**Resposta automática no número do EasyEye** para texto livre fora dos
fluxos: *"Este número envia avisos da {clínica}. Para falar com a clínica:
{telefone}"* — texto livre dentro da janela, sem custo de template. Numa fase
futura, caixa de mensagens da clínica dentro do sistema.

**Troca de número:** quando a clínica conecta o número próprio, os envios
novos passam a sair por ele; respostas a mensagens antigas (enviadas pelo
número do EasyEye) continuam reconhecidas pelo payload.

**Limite e qualidade compartilhados:** acompanhar a faixa e a qualidade do
número do EasyEye e as falhas e bloqueios por clínica; poder **suspender
uma clínica** do número compartilhado sem afetar as outras.

---

## 8. Consentimento e descadastro (LGPD e Meta)

Exigências da Meta: o opt-in deve dizer claramente que a pessoa aceita
receber mensagens **daquela empresa** e citar o nome de quem envia; pode ser
coletado presencialmente, em formulário ou por telefone; e os pedidos de
descadastro devem ser respeitados.

Proposta:

1. Novo tipo em `ConsentType` para **comunicação por WhatsApp** (consultas e
   pesquisas), registrado em `patient_consents` com data, quem registrou, o
   meio (recepção, Portal do Paciente, agendamento online) e a **versão do
   texto** aceito.
2. **Texto que cobre os dois números**, para a clínica poder trocar de
   número sem pedir novo consentimento: *"Aceito receber pelo WhatsApp
   avisos sobre minhas consultas na {clínica}, enviados pela clínica ou pelo
   EasyEye, sistema usado por ela."*
3. Envio só para paciente com consentimento ativo **e** com o celular marcado
   como WhatsApp (`schedules.cellphone_whatsapp` / `people.whatsapp`, hoje
   ignorados).
4. Descadastro pelas palavras "SAIR" / "PARAR" (instrução no rodapé dos
   templates) → revoga o consentimento e confirma ao paciente.
5. Marketing (se um dia existir) com consentimento **separado**.
6. **Política de privacidade** (`docs/legal/privacy-policy.md`): citar Gupshup
   e Meta como suboperadores e a **transferência internacional** de dados
   (servidores fora do Brasil — LGPD art. 33); exigir **DPA** (acordo de
   tratamento de dados) da Gupshup.
7. Minimização: só nome, data/hora, médico e clínica nas mensagens; nada de
   dado clínico.

---

## 9. Segurança

- Segredo do parceiro no ambiente do servidor; tokens em cache, nunca em
  banco ou log; credenciais da clínica cifradas (como hoje).
- Webhook autenticado por segredo por app (`hash_equals`), com limite de
  requisições (como o atual `throttle:240,1`).
- Payload do botão com id interno não adivinhável e validação de telefone e
  clínica antes de agir.
- **Excluir credenciais e segredo do webhook da trilha de auditoria** (hoje
  vazam — seção 13).
- Mensagens de erro do provedor saneadas antes de mostrar ou gravar
  (`ProviderErrorSanitizer`).
- Isolamento entre clínicas: toda busca de mensagem por `entity_id` + app da
  clínica.

---

## 10. Custos

- Desde **01/07/2025** a Meta cobra **por template entregue**, conforme
  categoria (Marketing, Utilidade, Autenticação) e país do destinatário.
- **Grátis:** mensagens que não são template dentro da janela de 24h (as
  respostas "Consulta confirmada"), e templates de **Utilidade enviados com a
  janela aberta**.
- Empresas brasileiras elegíveis têm cobrança **em reais** desde
  **01/07/2026** (tabela de preços na página da Meta).
- Utilidade e Autenticação têm **faixas de volume** (preço cai com volume
  mensal somado de todas as contas do portfólio).
- A Gupshup cobra a taxa dela por mensagem — definir em contrato.

**Quem paga:**

- **Número do EasyEye:** todo o consumo é cobrado na conta do EasyEye.
  Repassar no plano (franquia mensal de mensagens) ou cobrar por uso; o
  consumo por clínica sai de `whatsapp_messages` (entregues por clínica e
  categoria). Vantagem: o volume de todas as clínicas soma para as faixas de
  preço de Utilidade.
- **Número próprio:** cobrança na conta da clínica (linha de crédito da
  Gupshup) ou centralizada no EasyEye e repassada — definir no contrato.

Estimativa mensal por clínica:
`(consultas agendadas × 1 confirmação + consultas atendidas × 1 pesquisa) ×
tarifa de Utilidade BR + taxa Gupshup`.

---

## 11. Plano de implementação

| Fase | Entrega | Critério de aceite |
|---|---|---|
| 0. Preparação | contas Meta e Gupshup (parceiro ISV/TP); **número do EasyEye** conectado e templates aprovados nele; **corrigir vazamento do segredo do webhook na auditoria e rotacionar os segredos** | templates aprovados; auditoria sem segredos |
| 1. Fundação | interface de provedor; `ZApiProvider` sem mudança de comportamento; migrations (`provider`, `provider_message_id`, status); `GupshupProvider` com envio de template e tokens em cache | testes atuais do WhatsApp passando; envio Gupshup testado com `Http::fake` |
| 2. Webhook | rota, segredo, parsing v3, botões, status, idempotência; confirmação/cancelamento via `ScheduleService`; textos em `lang/` (pt_BR e en) | clique em botão confirma a consulta certa; status gravados; teste de webhook forjado → 404 |
| 3. Consentimento e telefones | `ConsentType` novo, descadastro, E.164, 9º dígito | sem envio para quem não consentiu ou descadastrou |
| 4. Manager | configuração global (número do EasyEye: estado, faixa de limite, templates, teste); por clínica: ajustes atuais e suspensão do número compartilhado | número do EasyEye gerenciado sem acesso ao servidor |
| 5. Piloto e migração | 1–2 clínicas no número do EasyEye; monitorar falhas, qualidade e faixa de limite; migrar as demais; desligar Z-API | taxa de falha baixa e estável; nenhuma regressão de confirmação/pesquisa |
| 6. Número próprio | "usar meu próprio número": Embedded Signup, templates criados por API no número da clínica, roteamento por app | envios da clínica saem pelo número dela; respostas antigas continuam reconhecidas |
| 7. Próximas ações | lembrete de retorno, laudo pronto (link do portal), outras | um template por ação, aprovado |

---

## 12. Riscos e perguntas para a Gupshup

- Tarifa por mensagem da Gupshup sobre a da Meta; forma de cobrança (linha de
  crédito por clínica, carteira pré-paga) e faturamento em reais.
- *Coexistence*: clínica pode continuar usando o WhatsApp Business no celular
  com o mesmo número?
- Número de teste / sandbox para desenvolvimento.
- Política de **retentativa** e **timeout** do webhook; IPs de origem (para
  liberar no servidor, se necessário); suporte a assinatura HMAC.
- Criação e acompanhamento de **templates por API** em cada app.
- Limites de envio (faixas da Meta, calculadas por portfólio) e alertas de
  qualidade; faixa inicial do número do EasyEye e o que acelera a subida.
- Cobrança dos números próprios: linha de crédito por clínica ou
  centralizada no EasyEye; relatório de consumo por app.
- **DPA** e local de processamento dos dados (LGPD).
- SLA e canal de suporte.

---

## 13. Pendências no código atual

Encontradas no levantamento — independem da Gupshup:

1. **Segurança:** `WhatsAppSetting` usa `Auditable` sem lista de exclusão — o
   **segredo do webhook (`webhook_token`) vai em texto puro para
   `audit_logs`** e as credenciais vão cifradas. Corrigir com exclusão desses
   campos na auditoria e **gerar novos tokens** (os antigos já estão na trilha).
2. Resposta via WhatsApp muda a consulta direto, sem `ScheduleService` (sem o
   e-mail de mudança do painel).
3. Textos das mensagens e do motivo de cancelamento fixos em português; não
   existe `lang/en/whatsapp.php`.
4. Sem consentimento nem descadastro para WhatsApp; flags de "celular é
   WhatsApp" ignoradas.
5. `normalizePhone` ignora o `+` de números estrangeiros (corrompe o número)
   e não reconcilia o 9º dígito.
6. Aviso de modo *mock* no Manager não aparece quando `WHATSAPP_DRIVER` está
   vazio (o envio continua simulado).
7. Fila `WHATSAPP_QUEUE` diferente de `default`/`high` não é consumida pelo
   worker atual; o job do código de verificação ignora essa configuração.
8. Sem teste do caminho HTTP real do `ZApiClient` (`Http::fake`).

---

## 14. Referências

Gupshup:

- [Visão geral da plataforma](https://docs.gupshup.io/docs/overview)
- [Cliente direto × ISV / TP](https://docs.gupshup.io/page/am-i-a-direct-customer-isv-or-tp)
- [Onboarding de ISV via Tech Provider da Meta (versão antiga)](https://docs.gupshup.io/docs/gupshup-isv-onboarding-based-on-metas-tech-provider-program)
- [Ecossistema de parceiros](https://partner-docs.gupshup.io/docs/gupshup-partner-eco-system)
- [Token de parceiro (login)](https://partner-docs.gupshup.io/reference/post_partner-account-login)
- [Token do app](https://partner-docs.gupshup.io/reference/get_partner-app-appid-token)
- [Link de Embedded Signup](https://partner-docs.gupshup.io/reference/get_partner-app-appid-onboarding-embed-link)
- [Template com botões (v3)](https://partner-docs.gupshup.io/reference/post_partner-app-appid-v3-message-10)
- [Enviar com ID de template](https://partner-docs.gupshup.io/reference/post_partner-app-appid-template-msg)
- [Gestão de assinaturas de webhook](https://partner-docs.gupshup.io/docs/set-callback-url-1)
- [API de assinatura v3](https://partner-docs.gupshup.io/reference/setsubscription-api-v3)
- [Eventos de mensagem](https://partner-docs.gupshup.io/docs/message-events)
- [Eventos de entrada v3](https://partner-docs.gupshup.io/docs/passthrough-v3-incoming-events)

Meta:

- [Mensagens — Cloud API](https://developers.facebook.com/docs/whatsapp/cloud-api/reference/messages/)
- [Webhooks](https://developers.facebook.com/documentation/business-messaging/whatsapp/webhooks/overview/)
- [Componentes de template (botões e limites)](https://developers.facebook.com/docs/whatsapp/business-management-api/message-templates/components)
- [Categorias de template](https://developers.facebook.com/docs/whatsapp/updates-to-pricing/new-template-guidelines)
- [Preços](https://developers.facebook.com/docs/whatsapp/pricing)
- [Opt-in](https://developers.facebook.com/docs/whatsapp/overview/getting-opt-in)
- [Limites de envio](https://developers.facebook.com/docs/whatsapp/messaging-limits/)
- [Contas do WhatsApp Business (WABA)](https://developers.facebook.com/docs/whatsapp/overview/business-accounts)
- [Números comerciais](https://developers.facebook.com/documentation/business-messaging/whatsapp/business-phone-numbers/phone-numbers)
- [Política e aplicação contra spam](https://developers.facebook.com/documentation/business-messaging/whatsapp/policy-enforcement)
- [Política de mensagens do WhatsApp Business](https://business.whatsapp.com/policy)
