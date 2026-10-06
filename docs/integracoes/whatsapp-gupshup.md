# Integração WhatsApp via Gupshup (API oficial da Meta)

> **Status: implementado (05/10/2026).** A Z-API foi removida do sistema:
> cliente, webhook, telas, variáveis `ZAPI_*` e credenciais no banco. O
> WhatsApp agora sai **só** pela Cloud API da Meta, com a Gupshup como BSP
> (Partner API). Sem conta Gupshup, o sistema fica em **modo simulação**
> (`WHATSAPP_DRIVER=mock`): nada sai e nada quebra.
>
> Documentação oficial conferida em **05/10/2026** — links em cada seção e em
> [Referências](#13-referências). Onde a proposta anterior (03/10) divergia da
> documentação, valeu a documentação ([§3](#3-divergências-entre-a-proposta-e-a-documentação-oficial)).

## Sumário

1. [Resumo](#1-resumo)
2. [Como ficou no código](#2-como-ficou-no-código)
3. [Divergências entre a proposta e a documentação oficial](#3-divergências-entre-a-proposta-e-a-documentação-oficial)
4. [Modelo de números (EasyEye + próprio)](#4-modelo-de-números)
5. [Passo a passo de configuração](#5-passo-a-passo-de-configuração)
6. [Templates para cadastrar e aprovar](#6-templates-para-cadastrar-e-aprovar)
7. [Regras de envio, resposta e descadastro](#7-regras-de-envio-resposta-e-descadastro)
8. [LGPD](#8-lgpd)
9. [Segurança](#9-segurança)
10. [Custos](#10-custos)
11. [Próximas fases (fora deste escopo)](#11-próximas-fases-fora-deste-escopo)
12. [Pendências antigas resolvidas](#12-pendências-antigas-resolvidas)
13. [Referências](#13-referências)
14. [Transição Z-API → Gupshup](#14-transição-z-api--gupshup)
15. [Limitações conhecidas](#15-limitações-conhecidas)
16. [Revisão de 05/10 — correções](#16-revisão-de-0510--correções)
17. [Revisão final de 05/10 — correções](#17-revisão-final-de-0510--correções)

---

## 1. Resumo

| | Antes (Z-API) | Agora (Gupshup) |
|---|---|---|
| Tipo de API | não oficial (sessão do WhatsApp Web) | oficial (Cloud API da Meta, via Gupshup — envio v3 "passthrough") |
| Mensagem iniciada pelo sistema | texto livre | **template aprovado pela Meta** |
| Resposta do paciente | digitava "1/2" ou "1 a 5" | **botões** (Confirmar/Cancelar, notas 5..1); texto continua aceito |
| Ligação resposta → consulta | só pelo telefone | **payload do botão** (id da mensagem) → `context.id` → telefone |
| Entregue / lido / falhou | não registrava | **webhook de status** (`delivered_at`, `read_at`, `failed_at`, `error_code`) |
| Quem recebe | qualquer celular do agendamento | só celular **marcado como WhatsApp** e que não respondeu **SAIR** |
| Código do cadastro e avisos do SaaS | texto livre, sem trilha | templates (autenticação / utilidade), registrados em `whatsapp_messages` |

Decisões do dono aplicadas:

- **Partner API** (`partner.gupshup.io`), configurável; segredos do parceiro **só no `.env`**; `app_id` e segredo do webhook no banco (o segredo cifrado).
- **Número do EasyEye + próprio opcional**: app global atende todas as clínicas; o manager pode associar um app próprio a uma clínica (App ID informado à mão). Precedência: próprio, senão global.
- **Opt-in** = marcação "WhatsApp" do agendamento (`schedules.cellphone_whatsapp`) ou do cadastro (`people.whatsapp`) + **SAIR/PARAR/STOP** descadastra e **VOLTAR/ATIVAR** reativa, por número que envia.
- Embedded Signup automático: **fora de escopo** ([§11](#11-próximas-fases-fora-deste-escopo)).

---

## 2. Como ficou no código

```text
whatsapp:send-confirmations ─┐                                         ┌─ GupshupProvider (driver gupshup)
whatsapp:send-surveys ───────┼─► WhatsAppService ─► SendWhatsAppMessageJob ─► WhatsAppProvider
cadastro (código) ───────────┤        (regras)          (fila, retry só       └─ MockWhatsAppProvider (mock/vazio)
avisos do SaaS (régua) ──────┘                           em erro transitório)

Gupshup ─► POST /api/whatsapp/gupshup/webhook/{token} ─► token + header de segredo + gs_app_id
              │ (200 vazio na hora)                       ├─ mensagem → ProcessWhatsAppInboundJob → WhatsAppService::handleInbound
              │                                           └─ status   → ProcessWhatsAppStatusJob
```

| Peça | Arquivo |
|---|---|
| Contrato do provedor | `app/Services/WhatsApp/Contracts/WhatsAppProvider.php` (`sendTemplate`, `sendSessionText`, `health`, `subscribeWebhook`) |
| Gupshup | `app/Services/WhatsApp/Providers/GupshupProvider.php` (envio v3, saúde, assinatura, classificação de erros) |
| Tokens | `app/Services/WhatsApp/Providers/GupshupAuth.php` (token universal ou e-mail + client secret; cache cifrado com id/validade; um worker gera por vez — `Cache::lock`; 401 relê o cache e revoga o recusado; 409 revoga os órfãos do ambiente) |
| Alertas | `app/Services/WhatsApp/WhatsAppAlerts.php` → `Log::critical` + e-mail ao admin/dono do SaaS (`WhatsAppOperationalAlertNotification`, no máx. 1/h por tipo) |
| Simulação | `app/Services/WhatsApp/Providers/MockWhatsAppProvider.php` |
| Binding | `AppServiceProvider` — `config('whatsapp.driver') === 'gupshup'` → Gupshup; qualquer outro valor (inclusive vazio) → mock |
| Templates | `config/whatsapp.php` (`templates`: nome na Meta, categoria, ordem das variáveis, botões) + `WhatsAppTemplates` / `TemplateMessage` |
| Regras | `app/Services/WhatsApp/WhatsAppService.php` (telefone, marcação, SAIR/VOLTAR, resposta, ScheduleService) |
| Webhook | `app/Http/Controllers/Api/GupshupWebhookController.php`, rota `whatsapp.gupshup.webhook` (`throttle:whatsapp-webhook`: por token da URL, `WHATSAPP_WEBHOOK_RATE_LIMIT`/min) |
| Jobs | `SendWhatsAppMessageJob`, `ProcessWhatsAppInboundJob`, `ProcessWhatsAppStatusJob`, `SendPhoneVerificationCodeJob` (template de autenticação, payload cifrado), `SendSaasWhatsAppNoticeJob` (templates do SaaS) |
| Dados | migration `2026_10_08_000000_migrate_whatsapp_to_gupshup.php` (**PostgreSQL** — ou SQLite; MySQL/MariaDB/SQL Server não: índices parciais, como a `2026_08_18_200000` que criou as tabelas; tokens e limpeza da auditoria são feitos em PHP); models `WhatsAppSetting`, `WhatsAppMessage`, `WhatsAppOptOut` |
| Manutenção | `whatsapp:sweep-stuck` (a cada 10 min): `sending` parado há mais de `WHATSAPP_SENDING_STUCK_MINUTES` (15) vira `failed`/`unknown_delivery` + alerta |
| Manager | `Manager\WhatsAppController` + `resources/js/Pages/Panel/Manager/WhatsApp/Index.vue`; textos em `lang/{pt_BR,en}/whatsapp.php` |
| Testes | `tests/Feature/WhatsApp/{WhatsAppIntegrationTest,GupshupProviderTest}.php`, `PhoneVerificationTest`, `DunningWhatsAppTest`, `TrialEndingNoticesTest`, `ManagerChargeNoticeTest`, `BlockedClinicOutsidePanelTest`, `tests/JavaScript/Panel/Manager/WhatsAppIndex.test.js` |

**Banco (migration):**

- `whatsapp_settings`: `provider`, `app_id` (único entre as configurações), `webhook_secret` (cifrado), `subscription_id`, `webhook_subscribed_at`. Saíram `credentials` e `instance_id` da Z-API (coluna apagada — nenhum token velho fica). Todos os `webhook_token` foram **trocados** (os antigos tinham vazado na auditoria). `webhook_token`/`webhook_secret` estão em `$auditExclude`, e a migration limpa o que já estava em `audit_logs`.
- `whatsapp_messages`: `zapi_message_id` → `provider_message_id` (dados preservados; índice único de entrada refeito), `whatsapp_setting_id` (por qual app saiu/chegou), `sender_app_id`, `template`, `wa_message_id` (wamid da Meta, chega no status), `error_code`, `delivered_at`, `read_at`, `failed_at`. Novos tipos: `verification` (código do cadastro, mascarado) e `saas_notice`; novo status `suppressed` (descadastrado).
- `whatsapp_opt_outs`: (`whatsapp_setting_id`, `phone`) único; `source` = `keyword` (SAIR) ou `provider` (Gupshup recusou com 1012).

---

## 3. Divergências entre a proposta e a documentação oficial

| # | Proposta (03/10) | Documentação oficial (05/10/2026) | O que foi feito |
|---|---|---|---|
| 1 | Login do parceiro com "e-mail + segredo de cliente" | A página do login usa o campo `secret` no exemplo; a especificação OpenAPI da mesma página e a página "Generate Secret and Token" falam em `password` ([login](https://partner-docs.gupshup.io/reference/post_partner-account-login), [secret](https://partner-docs.gupshup.io/docs/generate-secret-and-token)) | Envia **os dois campos** com o client secret |
| 2 | Token de parceiro (24h) + token do app (longo) | Desde set/2026 o padrão é **Universal Token (UT)** + **Universal App Token (UAT)**; o token de app do portal **para de funcionar em 01/04/2027** e o login por senha do portal é descontinuado em **31/10/2026** ([guia UT/UAT](https://partner-docs.gupshup.io/docs/partner-authentication-guide-universal-tokens-ut-overview), [changelog set/2026](https://partner-docs.gupshup.io/changelog/september-2026)) | Dois modos: **universal** (recomendado: `GUPSHUP_UNIVERSAL_TOKEN` gera UAT de 6h por app — [mint UAT](https://partner-docs.gupshup.io/reference/mintuniversalapptoken), nome único, máx. 3 ativos por app) e **partner_token** (e-mail + client secret) |
| 3 | `Authorization: {token}` sem "Bearer" | Confirmado para o token de app ([envio v3](https://partner-docs.gupshup.io/reference/post_partner-app-appid-v3-message-10)). O UT é enviado como `Bearer` ao gerar o UAT; a documentação não mostra exemplo de envio de mensagem com UAT | Token de app: sem "Bearer". UAT: `Bearer {UAT}` — **conferir no sandbox** ao ligar o modo universal |
| 4 | `messages[0].id` = id da Meta | É o **id da Gupshup** (`GUPSHUP_MESSAGE_ID`). Os status trazem `gs_id` (Gupshup), `id` e `meta_msg_id` (wamid) ([eventos v3](https://partner-docs.gupshup.io/docs/passthrough-v3-incoming-events), [BSUID](https://partner-docs.gupshup.io/docs/bsuid)) | `provider_message_id` = id Gupshup; `wa_message_id` preenchido pelo status; a resposta casa `context.id` com os dois |
| 5 | Falha em `statuses[].errors[]` (formato Meta) | O exemplo v3 da Gupshup traz `code` e `reason` no próprio status ([eventos v3](https://partner-docs.gupshup.io/docs/passthrough-v3-incoming-events)) | Lê os dois formatos |
| 6 | Modos `MESSAGE,SENT,DELIVERED,READ,FAILED,TEMPLATE` | A lista de restrições da página não cita `MESSAGE`, mas a seção "Modes" da mesma página documenta `MESSAGE` (mensagens recebidas). `TEMPLATE` não é aceito no update v3 ([set](https://partner-docs.gupshup.io/reference/setsubscription-api-v3), [update](https://partner-docs.gupshup.io/reference/put_partner-app-appid-subscription-subscriptionid)) | `MESSAGE,SENT,DELIVERED,READ,FAILED` (`GUPSHUP_SUBSCRIPTION_MODES`); estado de template fica para a próxima fase |
| 7 | Webhook sem token na URL, app pelo `gs_app_id`, segredo no `meta` | Confirmado: `meta` vira header em cada chamada ("can be used for authentication"); não há assinatura HMAC | **Defesa em camadas:** token na URL + header `X-EasyEye-Webhook-Secret` (`hash_equals`) + `gs_app_id` igual ao app; qualquer divergência → 404 |
| 8 | "200 na hora" | Resposta **2xx vazia em até 10 s**, senão a Gupshup reenvia; ideal < 1 s ([key points](https://partner-docs.gupshup.io/docs/webhook-key-points)) | 200 com corpo vazio; trabalho na fila; idempotência pelo id |
| 9 | Registro da assinatura ao salvar | Confirmado; limite de **5 chamadas/min por app** em `/subscription` ([rate limits](https://partner-docs.gupshup.io/docs/partner-rate-limits)) | Ao salvar app novo (ou "Registrar de novo"): lista → apaga só a da tag `easyeye-v3` → cria de novo |
| 10 | Pré-requisitos não citados | O envio v3 exige app na **Meta Cloud** e **"Callback Billing" habilitado** (senão 400 "Callback Billing must be enabled"); parceiro pré-pago pode precisar converter a carteira ([passthrough](https://partner-docs.gupshup.io/docs/whatsapp-passthrough-apis-for-partners)) | Incluído no checklist ([§5](#5-passo-a-passo-de-configuração)) |
| 11 | Usuário sempre com telefone | A Meta liberou **BSUID** (usuário que oculta o número) no Brasil em set/2026: o webhook pode vir com `from_user_id` ([BSUID](https://partner-docs.gupshup.io/docs/bsuid)) | Mensagem sem telefone é ignorada (limitação registrada) |
| 12 | Respostas dentro da janela "grátis" | Desde **01/10/2026** mensagens de serviço são cobradas (1.000 grátis/mês por número) e template de utilidade com a janela aberta também ([changelog out/2026](https://partner-docs.gupshup.io/changelog/october-2026)) | Atualizado em [§10](#10-custos) |
| 13 | Consentimento como novo `ConsentType` | — (decisão do dono) | Marcação "WhatsApp" + lista de descadastro por número |
| 14 | Botão "Quero remarcar" | — (decisão do dono: Confirmar/Cancelar) | Não implementado |

Limites de botões confirmados na Meta ([componentes](https://developers.facebook.com/docs/whatsapp/business-management-api/message-templates/components)): até 10 botões, até 10 de resposta rápida, até 2 de URL; rótulo com até 25 caracteres; rodapé até 60; corpo até 1.024; URL com **uma** variável no fim; com mais de 3 botões o WhatsApp mostra 2 e agrupa o resto em "Ver todas as opções".

---

## 4. Modelo de números

| | Número do EasyEye (padrão) | Número próprio da clínica (opcional) |
|---|---|---|
| Quem o paciente vê | nome de exibição do EasyEye; o template cita a clínica | nome da clínica |
| Como liga | Manager → WhatsApp → "Número do EasyEye" → App ID | Manager → WhatsApp → clínica → "Número próprio" → App ID |
| Templates | aprovados uma vez | aprovados no número da clínica |
| Descadastro (SAIR) | vale para o número do EasyEye | vale para o número da clínica |
| Código do cadastro e avisos do SaaS | sempre por aqui | nunca |

`WhatsAppSetting::sendingSetting()`: app próprio, se cadastrado; senão o global (se ativo). Cada mensagem grava por qual app saiu (`whatsapp_setting_id`), e a resposta só mexe em mensagens que saíram pelo app que a recebeu — trocar a clínica de número não quebra respostas a mensagens antigas.

Regras da Meta para o número compartilhado (limite de envio por portfólio, qualidade compartilhada, conversas livres chegando no número do EasyEye): ver a versão anterior deste documento no histórico do git (§4.1) — continuam valendo.

---

## 5. Passo a passo de configuração

### 5.1 Conta de parceiro

1. Business Manager do EasyEye com **verificação de empresa** (<https://business.facebook.com>).
2. Cadastro no portal de parceiros (<https://partner.gupshup.io>) e aceite dos **termos de uso de parceiro** (sem o aceite, criação/vínculo de app falha desde 01/09/2026 — [changelog ago/2026](https://partner-docs.gupshup.io/changelog/august-2026)).
3. **Settings → API client details → Create client secret** ([guia](https://partner-docs.gupshup.io/docs/generate-secret-and-token)).
4. **Recomendado:** Settings → *Universal Token Management* → **Generate Universal Token** (24h a 60 dias; anote, aparece uma vez) ([guia UT](https://partner-docs.gupshup.io/docs/partner-authentication-guide-universal-tokens-ut-overview)). **Rotação (≤ 60 dias):** gere o novo token ANTES de o atual vencer, troque `GUPSHUP_UNIVERSAL_TOKEN` (e `GUPSHUP_UNIVERSAL_TOKEN_EXPIRES_AT`), rode `php artisan config:cache` e reinicie o worker; só então revogue o antigo no portal. O manager mostra um aviso amarelo quando faltam `GUPSHUP_UNIVERSAL_TOKEN_WARN_DAYS` dias (padrão 10) e vermelho quando venceu — a data vem do `.env` ou, sem ela, do `exp` do próprio token (JWT). Token universal recusado gera alerta por e-mail ao admin/dono do SaaS.
5. Pedir ao suporte/CSM: **Callback Billing** habilitado nos apps e carteira compatível (pós-paga, se for o caso) — exigência do envio v3.

### 5.2 App e número do EasyEye

1. Criar o app no portal de parceiros, **na Meta Cloud**, e conectar o número dedicado do EasyEye.
2. Meta aprova o **nome de exibição**.
3. Confirmar que o app está **vinculado ao parceiro** (exigido pelo token universal — "Get Partner Apps"/"Link App with Partner").
4. Cadastrar e aguardar a aprovação dos **templates** do [§6](#6-templates-para-cadastrar-e-aprovar).

### 5.3 Servidor (`.env`)

```dotenv
WHATSAPP_DRIVER=gupshup
GUPSHUP_UNIVERSAL_TOKEN=...            # recomendado
GUPSHUP_UNIVERSAL_TOKEN_EXPIRES_AT=    # AAAA-MM-DD (opcional; aviso de rotação no manager)
GUPSHUP_APP_TOKEN_HOURS=6              # validade do token de app (2 a 24h; máx. 3 ativos por app)
WHATSAPP_WEBHOOK_RATE_LIMIT=1200       # chamadas/min do webhook por token da URL
WHATSAPP_WEBHOOK_UNKNOWN_RATE_LIMIT=60 # chamadas/min para token inexistente (balde único)
GUPSHUP_TOKEN_LOCK_SECONDS=120         # validade do lock de geração do token (mín. 120)
WHATSAPP_SENDING_STUCK_MINUTES=15      # "sending" parado há mais que isso → failed unknown_delivery
WHATSAPP_MOCK_REQUIRES_CODE=false      # só testes automatizados (phpunit.xml) — ver §9
# ou, no modo antigo (até 31/03/2027):
# GUPSHUP_PARTNER_EMAIL=...
# GUPSHUP_PARTNER_SECRET=...
GUPSHUP_AUTH_MODE=                     # vazio = escolhe sozinho
WHATSAPP_TEMPLATE_LANGUAGES=pt_BR      # acrescente ,en quando aprovar os templates em inglês
WHATSAPP_QUEUE=default                 # outra fila precisa entrar no worker (--queue=whatsapp,default)
```

Depois: `php artisan migrate` (apaga as credenciais da Z-API, troca os tokens de URL, passa as pendentes da Z-API para `skipped` e limpa o hash do código de verificação de `audit_logs`), `php artisan config:cache` (ou `config:clear`) e reiniciar o worker da fila.

**Fila:** os jobs de envio têm `$timeout` de 150 s (cobre o lock de 120 s do token + o envio). O `retry_after` da conexão da fila (`REDIS_QUEUE_RETRY_AFTER`/`DB_QUEUE_RETRY_AFTER`, padrão 90) precisa ser **maior** que o maior `$timeout` — o `docs/infra/reverb-ambiente-teste.md` já recomenda `960` (importações têm 900 s). Com 90, um envio demorado é entregue a outro worker no meio.

**Um app Gupshup por ambiente:** homologação e produção **não** podem usar o mesmo app (App ID) — cada ambiente gera os próprios tokens de app (UAT) e a Gupshup aceita no máximo **3 ativos por app**; dois ambientes no mesmo app disputam esse limite (e o webhook do app só aponta para um servidor). Use um app (e número) de teste na homologação.

**Cache compartilhado obrigatório com o modo universal:** os tokens de app ficam no cache (`CACHE_STORE=redis` em produção). Cache por servidor (file/array) com mais de um servidor faz cada um gerar o seu token e a limpeza do 409 revogar o do outro.

### 5.4 Manager

1. **Manager → WhatsApp → Número do EasyEye:** informar o **App ID**, deixar ativo e salvar. O webhook é registrado sozinho (badge "Registrado"); se falhar, conferir o `.env` e usar **"Registrar de novo"**.
2. **Verificar app** (saúde pela API da Gupshup) e **Enviar mensagem de teste** para um celular seu (usa o template `easyeye_teste_conexao`).
3. Em cada clínica: ligar a integração, confirmação e pesquisa; opcionalmente o App ID do número próprio dela (mesmos passos 5.2 no número da clínica).

### 5.5 Conferência antes de abrir para as clínicas

- Mandar a confirmação para um agendamento de teste (celular marcado como WhatsApp) e tocar em **Confirmar**: a consulta vira *Confirmada* e chega "Presença confirmada!".
- Responder **SAIR** e conferir que o próximo envio fica `suppressed`; responder **VOLTAR**.
- Conferir `delivered_at`/`read_at` em `whatsapp_messages`.
- Opcional: liberar só os IPs da Gupshup no servidor ([lista oficial](https://partner-docs.gupshup.io/docs/gupshup-ip-allowlisting)).

---

## 6. Templates para cadastrar e aprovar

Cadastrar **exatamente** com estes nomes (são os de `config/whatsapp.php`), idioma **Portuguese (BR) — `pt_BR`**, variáveis posicionais `{{1}}`, `{{2}}`... na ordem abaixo e botões **na ordem listada** (o índice do botão é usado no envio). A Meta exige exemplo para cada variável. Nada de conteúdo promocional (vira Marketing).

### 6.1 Pacientes (sempre pt_BR)

**`easyeye_confirmacao_consulta`** — Utilidade

```text
Olá, {{1}}! Lembrete de {{2}}: sua consulta está marcada para {{3}} com {{4}}. Podemos confirmar sua presença?
```

- Rodapé: `Para não receber mais avisos, responda SAIR.`
- Botões (resposta rápida): `Confirmar` · `Cancelar`
- Exemplo: `Maria` · `Clínica Visão` · `10/10/2026 às 14:30` · `Dr. João Silva`
- Payload enviado: `confirm:<id da mensagem>` / `cancel:<id da mensagem>`

**`easyeye_pesquisa_satisfacao`** — Utilidade (ligada ao atendimento)

```text
Olá, {{1}}! Esperamos que seu atendimento em {{2}} tenha sido bom. De 1 a 5, como você avalia a sua experiência?
```

- Rodapé: `Para não receber mais avisos, responda SAIR.`
- Botões (resposta rápida): `5 - Excelente` · `4 - Bom` · `3 - Regular` · `2 - Ruim` · `1 - Péssimo` (o WhatsApp mostra 2 e "Ver todas as opções")
- Exemplo: `Maria` · `Clínica Visão`
- Payload enviado: `survey:<id da mensagem>:<nota>`

### 6.2 Cadastro e teste

**`easyeye_codigo_verificacao`** — **Autenticação** (pt_BR; en opcional)

- Texto padrão da Meta (`*{{1}}* é seu código de verificação.`), com **"Adicionar recomendação de segurança"** e **validade de 10 minutos**.
- Botão: **Copiar código** (*copy code*).
- Exemplo: `482913`. Envio conforme [template de autenticação v3](https://partner-docs.gupshup.io/reference/post_partner-app-appid-v3-message-9) (código no corpo e no botão, `sub_type: url`, índice 0).

**`easyeye_teste_conexao`** — Utilidade

```text
Este é um teste de envio do {{1}}. Se você recebeu, o WhatsApp está configurado corretamente.
```

- Exemplo: `EasyEye`. Sem botões. (Usado só pelo botão "Enviar mensagem de teste" do manager; se a Meta reclassificar, pode ser removido sem afetar o resto.)

### 6.3 Avisos do SaaS à clínica (Utilidade, pt_BR; en opcional)

Todos com **um botão URL** `Abrir o EasyEye` com **URL dinâmica** `https://<domínio de produção>/{{1}}` (o sistema manda o caminho, ex.: `panel/my-subscription?invoice=<id>`). Exemplo da URL: `https://easyeye.app/panel/my-subscription?invoice=9b2f8f7e-1111-4222-8333-944455556666`.

| Nome | Corpo | Exemplo |
|---|---|---|
| `easyeye_cobranca_lembrete` | `EasyEye: a assinatura de {{1}} ({{2}}) vence em {{3}}. Você pode pagar pelo painel, em Minha assinatura.` | Clínica Visão · R$ 299,90 · 08/10/2026 |
| `easyeye_cobranca_lembrete_cartao` | `EasyEye: a assinatura de {{1}} ({{2}}) será cobrada no cartão final {{3}} em {{4}}. Para trocar o cartão ou pagar de outra forma, acesse Minha assinatura.` | Clínica Visão · R$ 299,90 · 4242 · 08/10/2026 |
| `easyeye_cobranca_vencida` | `EasyEye: não identificamos o pagamento de {{1}} da assinatura de {{2}} (vencimento {{3}}). Em {{4}}, IA e financeiro serão bloqueados. Pague pelo painel.` | R$ 299,90 · Clínica Visão · 08/10/2026 · 11/10/2026 |
| `easyeye_cobranca_acesso_limitado` | `EasyEye: o pagamento de {{1}} da assinatura de {{2}} segue em aberto e IA e financeiro estão bloqueados. Em {{3}}, o acesso ao painel será suspenso. Pague pelo painel.` | R$ 299,90 · Clínica Visão · 15/10/2026 |
| `easyeye_assinatura_encerrada` | `EasyEye: a assinatura de {{1}} foi encerrada por falta de pagamento. Para voltar a usar, contrate em Minha assinatura.` | Clínica Visão |
| `easyeye_primeira_cobranca_vencida` | `EasyEye: a 1ª cobrança da assinatura de {{1}} ({{2}}) venceu em {{3}} e o acesso foi suspenso. Ele volta assim que o pagamento for confirmado. Pague pelo painel.` | Clínica Visão · R$ 299,90 · 08/10/2026 |
| `easyeye_contratacao_cancelada` | `EasyEye: a contratação de {{1}} foi cancelada porque a 1ª cobrança não foi paga. Para contratar de novo, acesse Minha assinatura.` | Clínica Visão |
| `easyeye_teste_termina_em_dias` | `EasyEye: o teste grátis de {{1}} termina em {{2}} dias ({{3}}). Para continuar sem interrupção, contrate pelo painel.` | Clínica Visão · 3 · 13/10/2026 |
| `easyeye_teste_termina_amanha` | `EasyEye: o teste grátis de {{1}} termina amanhã ({{2}}). Contrate pelo painel para não perder o acesso.` | Clínica Visão · 13/10/2026 |
| `easyeye_teste_termina_hoje` | `EasyEye: o teste grátis de {{1}} termina hoje ({{2}}). Contrate pelo painel para continuar.` | Clínica Visão · 13/10/2026 |
| `easyeye_cobranca_enviada` | `EasyEye: há uma cobrança da assinatura de {{1}} (plano {{2}}) de {{3}}, vencimento {{4}}. Pague pelo painel.` | Clínica Visão · Premium · R$ 299,90 · 08/10/2026 |
| `easyeye_cobranca_troca_plano` | `EasyEye: a troca de {{1}} para o plano {{2}} foi solicitada. Pague a diferença de {{3}} (vencimento {{4}}) pelo painel.` | Clínica Visão · Premium · R$ 120,00 · 08/10/2026 |

Versões em inglês (opcionais — só usadas com `WHATSAPP_TEMPLATE_LANGUAGES=pt_BR,en` e destinatário com o sistema em inglês): mesmos nomes, idioma `en`, texto de `lang/en/whatsapp.php` (`templates.*`) trocando `:nome` por `{{n}}` na mesma ordem.

> Mudou um texto? Mude **na Meta**, em `lang/{pt_BR,en}/whatsapp.php` (`templates.*`, histórico do sistema) e aqui. Mudou a ordem das variáveis? Mude `config/whatsapp.php` (`templates.*.body`).

---

## 7. Regras de envio, resposta e descadastro

| Regra | Onde |
|---|---|
| Só envia para celular marcado como WhatsApp no agendamento (`cellphone_whatsapp`) ou no cadastro do paciente (`people.whatsapp`) | `WhatsAppService::resolveSchedulePhone` |
| Telefone: DDI 55; celular antigo sem o 9º dígito ganha o 9 (o `wa_id` da Meta pode vir sem ele); estrangeiro precisa de `+` | `WhatsAppService::normalizePhone` |
| SAIR/PARAR/STOP/DESCADASTRAR descadastra do número que recebeu; VOLTAR/ATIVAR reativa; confirmação ao paciente nos dois casos | `WhatsAppService::handleInbound` |
| Descadastrado não recebe nada daquele número: na criação não gera mensagem; no job vira `suppressed` (não volta à fila); avisos do SaaS também respeitam (o e-mail segue) | `queue`, `SendWhatsAppMessageJob`, `SendSaasWhatsAppNoticeJob` |
| Código do cadastro **ignora** o descadastro (é o próprio usuário que pede) | `SendPhoneVerificationCodeJob` |
| Resposta: payload do botão → `context.id` → texto livre; sempre só mensagens que saíram pelo app que recebeu e para o mesmo telefone. **Cada etapa decide sozinha — nunca "cai" na seguinte** | `WhatsAppService::handleInbound` |
| **Botão** com payload nosso (`confirm:`/`cancel:`/`survey:<id>`): vale só a mensagem do botão, em **qualquer status** (inclusive `failed`/`unknown_delivery`, `sending`, `suppressed` — o clique prova a entrega); só o tipo precisa bater. Mensagem não encontrada ou de tipo incompatível → nada muda, "toque no botão" | `handleInbound` |
| **Citação** (`context.id`): vale só a confirmação/pesquisa citada. Citou outra coisa (resposta automática, aviso de outra consulta, mensagem que o sistema não conhece) → nada muda, "toque no botão" | `handleInbound` |
| **Texto livre** (sem botão nem citação) só vale com **uma única** mensagem (confirmação ou pesquisa) aguardando resposta daquele telefone naquele número, dentro da validade (7 dias confirmação, 14 pesquisa), **se ela for a última coisa que saiu desse número para esse telefone** (nenhuma resposta automática/aviso de OUTRA consulta depois dela — no mesmo segundo também conta como "depois"; a resposta automática da própria consulta, ex. "não entendi", não atrapalha) e só com resposta **exata** — ver tabela abaixo. Senão nada muda e o paciente recebe "toque no botão da mensagem que quer responder"; texto não reconhecido com uma candidata recebe "não entendi" | `handleInbound`, `parseChoice`, `parseScore` |
| **Consulta remarcada:** a confirmação guarda a data/hora da consulta (`payload.schedule_at`). Ao mudar `date_time` (qualquer caminho pelo model — edição, remarcação em lote), a confirmação antiga vira `skipped` (`error_code = rescheduled`) e o comando manda **outra com a nova data, reaproveitando a mesma linha** (se a consulta seguir Agendada e na janela). Resposta ao aviso antigo (ou com a data mudada por fora do model) não confirma nem cancela: "esta consulta foi remarcada, aguarde a nova mensagem" | `Schedule::booted`, `WhatsAppMessage::supersedeConfirmationsOf`, `applyConfirmationReply` |
| Descadastrado (SAIR) naquele número: o efeito de um clique/resposta ainda vale, mas a **resposta automática** só sai para VOLTAR/ATIVAR e para o próprio SAIR | `handleInbound` |
| Consulta **excluída** (soft delete) ou **inativa** não é confirmada/cancelada pelo WhatsApp | `applyConfirmationReply` |
| Confirmar/Cancelar passa pelo `ScheduleService::changeSituation` (linha travada, log com `entity_user_id` nulo, `confirmed_at`/motivo traduzido, cache da sala de espera) e dispara o mesmo e-mail ao paciente que o painel; nunca sobrescreve consulta que já saiu de *Agendada* | `applyConfirmationReply` |
| Respostas automáticas são texto de sessão e só saem com a janela de 24h aberta | `ProcessWhatsAppInboundJob` |
| Erro transitório (5xx, 429, falha de conexão **antes** de enviar, Meta 2/4/80007/130429/131000/131016/131056, Gupshup 500/4001/4002/1003) → nova tentativa da fila; permanente (template inexistente 132001, parâmetros 132000, número sem WhatsApp 1002, 4xx) → `failed` na hora com `error_code`; Gupshup 1012 (número descadastrado) → entra na lista de supressão | `GupshupProvider::failure/classifyCode`, jobs |
| **Falha transitória esgotada** (saldo da carteira 1003, token universal recusado, limite de UATs, 5xx, conexão, Meta 131000/130429...): a confirmação/pesquisa fica `skipped` **com o `error_code`** e o comando a reenfileira na próxima rodada — não se perde. Vale também para o status `failed` assíncrono com código transitório. Saldo, token e UATs geram alerta crítico | `SendWhatsAppMessageJob::failed`, `ProcessWhatsAppStatusJob` |
| **Autenticação antes da reserva:** o token do app é obtido (cache; gerar um novo espera o lock de outro worker) **antes** de a linha virar `sending` — demora ou falha ali deixa a linha `pending` (nada saiu). Lock da geração do token: 120 s | jobs de envio, `WhatsAppProvider::authorize`, `GupshupAuth` |
| **Presas em `sending`** (worker morreu no meio da chamada): o `failed()` dos jobs fecha a linha (`sending` → `failed`/`unknown_delivery`; `pending` → `failed` com o último erro) e o `whatsapp:sweep-stuck` (a cada 10 min) pega o que sobrar, com alerta. **Não reenvia** (pode ter saído — confirmação/pesquisa em dobro). Envio com sucesso limpa `failed_at`/`error`/`error_code` de tentativa anterior | jobs, `SweepStuckWhatsAppMessagesCommand` |
| **Sem duplicidade:** templates a paciente e avisos do SaaS gravam `sending` **antes** da chamada; job que encontra `sending` não reenvia (`failed`, `unknown_delivery`, para conferir); timeout de leitura / conexão caída depois de enviar → `unknown_delivery`, sem nova tentativa; 2xx sem id → `sent` com `error_code = no_message_id`. Código do cadastro pode tentar de novo (código repetido não faz mal), mas não se a tentativa anterior já tiver o id | jobs, `GupshupProvider::send` |
| Status de mensagem desconhecida (outro sistema/console no mesmo app) é descartado com log de debug; só volta à fila se houver envio daquele app em `sending` (corrida do id) | `ProcessWhatsAppStatusJob` |
| Clínica bloqueada (`ClinicServiceGate`): nada automático ao paciente (`skipped`, volta sozinho); avisos do SaaS seguem | sem mudança |
| Janelas: confirmações 08–20h, pesquisas 09–20h, avisos do SaaS 08–20h; código sem janela | sem mudança |

**Respostas em texto aceitas** (comparação exata, sem acento, maiúsculas nem pontuação — `lang/{pt_BR,en}/whatsapp.php`, `replies`; vale pt_BR e en):

| Ação | Aceito |
|---|---|
| Confirmar | `1`, `sim`, `confirmar`, `confirmo`, `confirmado`, `yes`, `confirm`, `confirmed` |
| Cancelar | `2`, `não`/`nao`, `cancelar`, `cancelo`, `cancelado`, `no`, `cancel`, `cancelled` |
| Nota da pesquisa | `1` a `5` ou o rótulo do botão (`5 - Excelente`, `4 - Bom`, `3 - Regular`, `2 - Ruim`, `1 - Péssimo`) |
| Descadastrar / voltar | `SAIR`, `PARAR`, `STOP`, `DESCADASTRAR`, `UNSUBSCRIBE` / `VOLTAR`, `ATIVAR`, `START` (`config/whatsapp.php`) |

"Sim, mas posso chegar mais tarde?", "Não entendi, é amanhã?" ou "Cancelaram a outra?" **não** mudam nada.

**Impacto da regra de opt-in medido no banco de dev (05/10/2026, consulta só leitura):** 18.925 consultas futuras *Agendadas* em 6 clínicas; todas têm celular, 15.918 têm celular marcado como WhatsApp e **3.007 (15,9%) deixam de receber** a confirmação até a recepção marcar. Pacientes: 2.204, 2.178 com celular e **1.955 sem a marcação no cadastro** (a maioria tem a marcação só no agendamento). O rótulo do checkbox do agendamento passou a dizer que é ele que libera a confirmação.

---

## 8. LGPD

- **Papéis:** a clínica é controladora; o EasyEye, operador; **Gupshup** (os IPs oficiais de webhook ficam na AWS dos EUA, da Índia e da Alemanha — confirmar o local de tratamento no DPA) e **Meta** (WhatsApp Business Platform, EUA) são **suboperadores** contratados pelo EasyEye.
- **Transferência internacional (art. 33):** os dois tratam fora do Brasil → contrato/DPA com **cláusulas-padrão da ANPD** (Resolução CD/ANPD nº 19/2024) com a Gupshup; termos de tratamento de dados do WhatsApp Business com a Meta. O sistema não guarda os contratos (mesmo critério de `docs/legal/ai-providers-lgpd.md`).
- **Minimização:** templates só com primeiro nome, clínica, data/hora e médico; nada de dado clínico. Avisos do SaaS sem dado de paciente. Código de verificação nunca em claro (hash no banco; payload do job cifrado; histórico mascarado).
- **Opt-in/opt-out:** marcação "WhatsApp" no agendamento/cadastro + SAIR/VOLTAR com confirmação.
- **Export do titular:** `PatientDataExporter` continua levando `whatsapp_messages` da consulta, agora com entrega/leitura e com as respostas do paciente (que passaram a ganhar o `schedule_id`), e o bloco `whatsapp_opt_outs` (descadastros dos celulares do paciente — cadastro e agendamentos — no número da clínica e no do EasyEye: `sender`, `phone`, `source`, `opted_out_at`).
- **SAIR no número do EasyEye vale para TODAS as clínicas que usam esse número** (o descadastro é por número que envia, não por clínica). No painel, a agenda (detalhes da consulta) e o cadastro do paciente (detalhes) mostram o selo "Descadastrado do WhatsApp".
- **Código de verificação:** no banco só o HMAC-SHA256 com a `APP_KEY` (antes sha256 puro, reversível por força bruta com 10⁶ tentativas); fora de `audit_logs` (`User::$auditExclude`) e a migration `2026_10_08_000100` limpou o que já estava lá (só linhas do User, só as chaves `phone_verification_code/_expires_at/_attempts`). Códigos pendentes no formato antigo continuam aceitos até expirar (10 min) — comparar os dois formatos não enfraquece o novo.
- **Política de privacidade:** o texto vigente (`resources/legal/privacy-policy-1.0.txt`) fala de WhatsApp de forma genérica e **não citava a Z-API** — **não foi alterado de propósito** (depende de revisão jurídica). Pendência registrada para o jurídico: nomear Gupshup e Meta como suboperadores, citar a transferência internacional e o descadastro por número compartilhado numa nova `TermVersion`.

---

## 9. Segurança

- Credenciais do parceiro só no `.env`; tokens do parceiro/app só no cache, **cifrados** (`Crypt`), nunca em banco, log ou resposta; renovação automática em 401 (uma vez, sem laço).
- Webhook: token aleatório na URL + header de segredo (`hash_equals`) + `gs_app_id`; 404 genérico; limite por **token da URL** (RateLimiter `whatsapp-webhook`, `WHATSAPP_WEBHOOK_RATE_LIMIT`/min — o IP não serve, com `trustProxies '*'` ele vem do `X-Forwarded-For`, forjável); token **inexistente** cai num balde **global** (`whatsapp-webhook:unknown`, `WHATSAPP_WEBHOOK_UNKNOWN_RATE_LIMIT`/min) — tokens aleatórios não ganham um balde cada; 429 depois disso; idempotência por id (mensagem sem id: hash de telefone + timestamp + conteúdo, prefixo `noid:`); 200 imediato.
- Gate do cadastro (`EnsurePhoneVerified`): libera quando não há como entregar o código —
  - app global ausente/inativo; driver `gupshup` sem credenciais do parceiro ou com o **token universal vencido** (`GUPSHUP_UNIVERSAL_TOKEN_EXPIRES_AT` ou o `exp` do JWT);
  - **alerta recente de canal fora do ar** (token universal recusado, limite de UATs, saldo da carteira): marca de 15 min, renovada a cada alerta (token recusado vale para todos os apps; UATs/saldo, para o app do alerta);
  - driver `mock`: **só exige o código em `APP_ENV=local` ou com `WHATSAPP_MOCK_REQUIRES_CODE=true`** (testes automatizados — `phpunit.xml`); a homologação roda `APP_ENV=testing` e, com mock, **não** prende ninguém; produção com mock nunca exige;
  - último envio do código ao usuário `failed` por falha de **configuração** — **lista branca** (`PhoneVerificationService::CONFIGURATION_FAILURES`: `missing_app`, `missing_partner_credentials`, `auth_failed`, Gupshup 1001/1004/1005/1009/4003/4004/4005, Meta 0/3/10/190/368/131005/131008/131030/131031/131037/131042/131045/131051/133010/135000 e templates 132000/132001/132005/132007/132012/132015/132016 — [Gupshup](https://partner-docs.gupshup.io/docs/error-codes), [Meta](https://developers.facebook.com/docs/whatsapp/cloud-api/support/error-codes)) ou com as tentativas **esgotadas** por token universal recusado / limite de UATs / saldo — alerta ao time.

  Código desconhecido ou genérico (ex.: `http_400`) **não** libera. Falha do lado do número do usuário (sem WhatsApp 1002, sem opt-in 1006/1008, descadastrado 1012, parâmetro inválido 131009, não entregável 131026/131049) **não** libera: ele corrige o número.
- Segredo do webhook cifrado no banco, novo a cada app associado; `webhook_token`/`webhook_secret` fora da auditoria (e limpos do que já havia vazado); nunca enviados ao front.
- Payload dos botões = UUID da mensagem (não adivinhável), conferido contra o app que recebeu, o telefone e o tipo da mensagem.
- Manager só para Admin do SaaS (Gate `SaasAdminPanel`); auditoria `manager.whatsapp_*` sem segredo; teste/registro com `throttle:manager-destructive`.

---

## 10. Custos

- A Meta cobra **por template entregue** (Marketing, Utilidade, Autenticação; preço por país; faixas de volume em Utilidade/Autenticação).
- **Mudou em 01/10/2026:** mensagens de **serviço** (as respostas automáticas) passaram a ser cobradas, com **1.000 grátis por mês por número**; template de Utilidade enviado com a janela aberta também passou a ser cobrado ([changelog out/2026](https://partner-docs.gupshup.io/changelog/october-2026)).
- A Gupshup cobra a taxa dela por mensagem (contrato).
- Consumo por clínica: `whatsapp_messages` por `entity_id`, `template` e `delivered_at`; avisos do SaaS e códigos ficam sem clínica (custo do EasyEye).

---

## 11. Próximas fases (fora deste escopo)

1. **Embedded Signup** para a clínica conectar o próprio número sozinha (`GET /partner/app/{appId}/onboarding/embed/link`, válido 5 dias — [link](https://partner-docs.gupshup.io/reference/get_partner-app-appid-onboarding-embed-link)), criação do app e dos templates por API.
2. Estado dos templates no manager (eventos `TEMPLATE` — exige assinatura própria, não aceita no update v3).
3. Caixa de mensagens da clínica para texto livre do paciente; resposta automática com o contato da clínica.
4. Botão "Quero remarcar"; lembrete de retorno; laudo pronto (link do Portal do Paciente).
5. Suporte a BSUID (usuário que oculta o número).
6. Suspender uma clínica do número compartilhado e painel de qualidade/faixa de limite.

---

## 12. Pendências antigas resolvidas

| Pendência (doc de 03/10) | Situação |
|---|---|
| `webhook_token` em texto puro em `audit_logs` | `$auditExclude` + limpeza dos registros + tokens trocados na migration |
| Resposta via WhatsApp fora do `ScheduleService` | Passa por `changeSituation` (com trava da linha) + e-mail do painel |
| Textos fixos em português, sem `lang/en/whatsapp.php` | Templates, respostas e manager em `lang/{pt_BR,en}/whatsapp.php` |
| Sem consentimento; marcação "WhatsApp" ignorada | Marcação respeitada + SAIR/VOLTAR |
| `normalizePhone` quebrava `+` e o 9º dígito | Corrigido |
| Aviso de simulação ausente com driver vazio | Driver vazio = mock, e o manager avisa |
| Fila do WhatsApp ignorada pelo código | Todos os jobs usam `whatsapp.queue` (o worker precisa da fila, se não for `default`) |
| Sem teste do caminho HTTP | `GupshupProviderTest` com `Http::fake` + `preventStrayRequests` |

---

## 13. Referências

Gupshup (Partner API — conferidas em 05/10/2026):

- [Índice da documentação de parceiros](https://partner-docs.gupshup.io/llms.txt) · [docs.gupshup.io](https://docs.gupshup.io/)
- [Token de parceiro (login)](https://partner-docs.gupshup.io/reference/post_partner-account-login) · [Client secret](https://partner-docs.gupshup.io/docs/generate-secret-and-token) · [Token do app](https://partner-docs.gupshup.io/reference/get_partner-app-appid-token)
- [Universal Token / Universal App Token](https://partner-docs.gupshup.io/docs/partner-authentication-guide-universal-tokens-ut-overview) · [Gerar UAT](https://partner-docs.gupshup.io/reference/mintuniversalapptoken) · [Listar UATs](https://partner-docs.gupshup.io/reference/listuniversalapptokens) · [Revogar UAT](https://partner-docs.gupshup.io/reference/revokeuniversalapptoken) · [Gerar UT](https://partner-docs.gupshup.io/reference/mintuniversaltoken)
- [Passthrough v3 (pré-requisitos)](https://partner-docs.gupshup.io/docs/whatsapp-passthrough-apis-for-partners)
- [Template com botões v3](https://partner-docs.gupshup.io/reference/post_partner-app-appid-v3-message-10) · [Template de autenticação v3](https://partner-docs.gupshup.io/reference/post_partner-app-appid-v3-message-9) · [Template de texto v3](https://partner-docs.gupshup.io/reference/post_partner-app-appid-v3-message-14) · [Texto de sessão v3](https://partner-docs.gupshup.io/reference/post_partner-app-appid-v3-text-message)
- [Assinatura v3](https://partner-docs.gupshup.io/reference/setsubscription-api-v3) · [Listar](https://partner-docs.gupshup.io/reference/get_partner-app-appid-subscription) · [Atualizar](https://partner-docs.gupshup.io/reference/put_partner-app-appid-subscription-subscriptionid) · [Apagar](https://partner-docs.gupshup.io/reference/delete_partner-app-appid-subscription-subscriptionid)
- [Eventos v3](https://partner-docs.gupshup.io/docs/passthrough-v3-incoming-events) · [Gestão de assinaturas](https://partner-docs.gupshup.io/docs/set-callback-url-1) · [Eventos de mensagem](https://partner-docs.gupshup.io/docs/message-events) · [Webhook key points](https://partner-docs.gupshup.io/docs/webhook-key-points) · [IPs](https://partner-docs.gupshup.io/docs/gupshup-ip-allowlisting)
- [Saúde do app](https://partner-docs.gupshup.io/reference/get_partner-app-appid-health) · [Limites de chamadas](https://partner-docs.gupshup.io/docs/partner-rate-limits) · [Códigos de erro](https://partner-docs.gupshup.io/docs/error-codes) · [BSUID](https://partner-docs.gupshup.io/docs/bsuid)
- Changelog: [ago/2026](https://partner-docs.gupshup.io/changelog/august-2026) · [set/2026](https://partner-docs.gupshup.io/changelog/september-2026) · [out/2026](https://partner-docs.gupshup.io/changelog/october-2026)
- [Link de Embedded Signup](https://partner-docs.gupshup.io/reference/get_partner-app-appid-onboarding-embed-link)

Meta:

- [Componentes de template (botões e limites)](https://developers.facebook.com/docs/whatsapp/business-management-api/message-templates/components)
- [Webhook — clique em botão](https://developers.facebook.com/documentation/business-messaging/whatsapp/webhooks/reference/messages/button)
- [Códigos de erro da Cloud API](https://developers.facebook.com/docs/whatsapp/cloud-api/support/error-codes)
- [Templates de autenticação](https://developers.facebook.com/docs/whatsapp/business-management-api/authentication-templates)
- [Preços](https://developers.facebook.com/docs/whatsapp/pricing) · [Opt-in](https://developers.facebook.com/docs/whatsapp/overview/getting-opt-in) · [Limites de envio](https://developers.facebook.com/docs/whatsapp/messaging-limits/) · [Política do WhatsApp Business](https://business.whatsapp.com/policy)

---

## 14. Transição Z-API → Gupshup

- **Pendentes no deploy:** confirmações/pesquisas da Z-API ainda `pending` (texto livre, sem template) viram `skipped` (`error_code = zapi_legacy`) na migration; o comando da próxima rodada reaproveita a mesma linha com o template (se a consulta ainda estiver na janela). Sem isso, falhariam no envio pela Gupshup.
- **Respostas a mensagens `sent` da era Z-API não casam:** essas linhas não têm `whatsapp_setting_id` (por qual app saíram) e vieram de outro número; resposta de paciente a elas chega pelo número novo e **nada muda na consulta**: se citar a mensagem antiga (`context.id` — caso o número tenha sido migrado para a Gupshup), o paciente recebe "toque no botão"; texto livre sem citação só vale para uma confirmação **nova** (da Gupshup) que seja a última mensagem enviada a ele. Decisão: **não** casar pelo telefone para essas legadas (o número que enviou é outro e o paciente pode ter consultas em mais de uma clínica) — a recepção confirma manualmente as poucas que estiverem no ar no dia do deploy.
- **Ordem do deploy:** cadastrar o app global no manager logo depois do `migrate` (o comando só reenfileira com algum app operacional).

## 15. Limitações conhecidas

- **BSUID** (usuário que oculta o número): o webhook pode vir sem `from` (só `from_user_id`) — a mensagem é ignorada (sem telefone não há como casar). Próxima fase.
- **Números estrangeiros com dígito extra:** Argentina (`54 9 ...`) e México (`52 1 ...`) — o `wa_id` devolvido pela Meta pode não ter o dígito que o cadastro tem (ou vice-versa); só o 9º dígito do Brasil é normalizado. Resposta desses pacientes pode não casar por telefone (o botão casa pelo payload, que não depende do número).
- **Status desconhecidos** (mensagens enviadas pelo console da Gupshup ou outro sistema no mesmo app) são descartados.
- **Ack de entrega das respostas automáticas** pode se perder na corrida do id (são gravadas depois do envio) — irrelevante para o fluxo.
- Política de privacidade: ver [§8](#8-lgpd) (sem alteração; revisão jurídica pendente).

## 16. Revisão de 05/10 — correções

| # | Achado | Correção | Teste (`tests/Feature/WhatsApp/WhatsAppReviewFixesTest.php`) |
|---|---|---|---|
| 1 | Texto livre ("Não entendi…") cancelava consulta de outra clínica no número global | Só respostas exatas e só com candidata única; senão "toque no botão" | bloco 1 |
| 2 | UAT podia estourar o limite de 3 ativos (409 permanente) | `Cache::lock`, reaproveita do cache, 401 relê o cache e revoga o recusado, 409 revoga órfãos do ambiente e vira transitório + alerta, piso de 2h, aviso de validade do UT no manager | bloco 2 |
| 3 | Consulta excluída confirmada pelo WhatsApp | Remove só o `EntityScope` (mantém o soft delete) e exige `active` | bloco 3 |
| 4 | Hash do código em `audit_logs` | `$auditExclude`, migration de limpeza, HMAC com `APP_KEY` (antigo aceito até expirar) | bloco 4 |
| 5 | Cadastro preso quando o WhatsApp não entrega | `channelAvailable()` + falha permanente de configuração libera o gate (com alerta) | bloco 5 |
| 6 | Duplicidade em timeout / 2xx sem id | estado `sending`, `unknown_delivery`, `no_message_id`, `send_key` nos jobs do SaaS e do código | bloco 6 |
| 7 | Pendentes da Z-API falhavam no deploy | migration → `skipped` + reaproveitamento pelo comando | bloco 7 |
| 8 | Descadastrado ainda recebia respostas automáticas | só VOLTAR/SAIR respondem; descadastro no export LGPD; selo na agenda/cadastro | bloco 8 |
| 9 | 4001/1003 tratados como permanentes | 4001/4002/1003 transitórios; 1003 com alerta e sem perder a mensagem | bloco 9 |
| 10 | Throttle do webhook por IP forjável | RateLimiter por token da URL | bloco 10 |
| 11 | Restos (validação Z-API, teste do manager sem `isClient`, status desconhecido re-enfileirado, entrada sem id) | removidos/corrigidos | bloco 11 |

## 17. Revisão final de 05/10 — correções

Testes: `tests/Feature/WhatsApp/WhatsAppFinalReviewFixesTest.php` (um bloco por achado; P1–P7 do revisor viraram testes) e `tests/Feature/Subscriptions/SeedCredentialsTest.php` (senhas dos seeders).

| # | Achado | Correção |
|---|---|---|
| 1 | Botão de mensagem não encontrada (ex.: `failed`/`unknown_delivery`) caía no texto livre e cancelava a consulta de outra clínica | Botão com payload nosso só casa com a própria mensagem (qualquer status, tipo compatível); não achou → "toque no botão", nada muda |
| 2 | Texto livre com candidata única pegava a consulta errada ("Não" depois da resposta de outra consulta; citação da resposta automática) | Citação só vale para a confirmação/pesquisa citada; texto livre só se a candidata for a última mensagem enviada àquele telefone por aquele número |
| 3 | Remarcação mantinha a confirmação velha válida | `payload.schedule_at`; remarcar → `skipped`/`rescheduled` + reenvio com a nova data na mesma linha; resposta à antiga → "consulta remarcada" |
| 4 | Token universal vencido / saldo / limite de UATs prendiam o cadastro | `channelAvailable()` falso com UT vencido e por 15 min após esses alertas; tentativas esgotadas com esses códigos liberam o gate |
| 5 | Confirmação/pesquisa com falha transitória esgotada se perdia | Transitória → `skipped` com o `error_code` (job e webhook de status) — o comando reenvia |
| 6/7 | Mock na homologação (`APP_ENV=testing`) prendia o cadastro | Mock só exige código em `local` ou com `WHATSAPP_MOCK_REQUIRES_CODE=true` (phpunit) |
| 10 | Gate liberava em código desconhecido | Lista **branca** de falhas de configuração; 1006/1008/131009 como falha do destinatário |
| 11 | Flood com tokens aleatórios nunca estourava o limite | Balde global `whatsapp-webhook:unknown` antes do 404 |
| 12 | Linhas presas em `sending` | `failed()` nos jobs do código e dos avisos do SaaS; `whatsapp:sweep-stuck` a cada 10 min (failed `unknown_delivery` + alerta); sucesso limpa erro/falha anterior |
| 13 | Reserva `sending` antes da autenticação; lock de 30 s | `authorize()` antes do claim; lock de 120 s; `$timeout` 150 s; `retry_after` documentado |
| 14 | Migrations só PostgreSQL | Tokens e limpeza de auditoria em PHP; índices parciais documentados (PostgreSQL/SQLite); um app Gupshup por ambiente |

