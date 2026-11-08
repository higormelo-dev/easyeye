# Runbook — alertas de configuração de produção

Origem: levantamento do servidor de 08/11/2026. Captcha do cadastro, transporte TISS e WhatsApp estavam simulados ou desligados em produção e o sistema "funcionava" sem avisar ninguém (o TISS gravava protocolo "Aceito" sem enviar nada).

**Hipótese de invalidação:** refazer se uma das variáveis abaixo mudar de nome ou se o envio de e-mail do SaaS deixar de usar `SaasAlertRecipients`.

## O que roda

`php artisan ops:check-production-config` (agendado todo dia às 08:00, um servidor só). Só lê configuração: sem rede, sem banco.

- **Fora de produção:** só lista os itens. Simulação é o normal em desenvolvimento e teste.
- **Em produção:** `Log::critical` (Sentry) e e-mail na hora ao admin e ao dono do SaaS. Cada item avisa no máximo **uma vez por semana** (o comando roda todo dia). Rode à mão depois de um deploy para conferir.

## Itens

| Código | Quando aparece | Efeito | Corrige com |
|---|---|---|---|
| `turnstile_off` | `TURNSTILE_SITE_KEY` ou `TURNSTILE_SECRET_KEY` vazia | `/register` aprova sem captcha | Criar o site no Cloudflare Turnstile e pôr as duas chaves no `.env` |
| `tiss_simulated` | `TISS_TRANSPORT_DRIVER` diferente de `http` | Nenhum lote chega à operadora; em produção o envio é **bloqueado** (abaixo) | `TISS_TRANSPORT_DRIVER=http` e credenciais por operadora ([Credenciais TISS](credenciais-tiss.md)) |
| `whatsapp_simulated` | `WHATSAPP_DRIVER` diferente de `gupshup` | Nenhuma mensagem sai | `WHATSAPP_DRIVER=gupshup` (ver [integração](../integracoes/whatsapp-gupshup.md)) |
| `whatsapp_sandbox` | `GUPSHUP_SANDBOX=true` com driver `gupshup` | O teste de conexão usa o modelo do sandbox | `GUPSHUP_SANDBOX=false` |
| `mail_not_sending` | `MAIL_MAILER` é `log` ou `array` | Nenhum e-mail sai, nem este alerta | Mailer real (SMTP) |
| `ai_fake` | `AI_PROVIDER_RUNTIME` diferente de `real` | A IA responde de mentira | `AI_PROVIDER_RUNTIME=real` |

Depois de mudar o `.env`: `php artisan config:cache` (o script de deploy já faz `optimize`).

## Trava do TISS em produção

Com o transporte `mock`, o envio de lote gravava um protocolo "Aceito" que a operadora nunca viu: a clínica acharia que faturou. Em **produção** o envio agora falha **antes de qualquer escrita** enquanto o driver não for `http`:

- lote: a tela mostra "Envio bloqueado: o TISS está em modo simulação…"; o job da fila falha na hora (sem as 5 tentativas) e leva o lote a **erro** com esse motivo. Depois de configurar o transporte real, reenvie o lote;
- guia cirúrgica avulsa: mesma mensagem (409).

Com o driver `http`, o envio também é bloqueado quando a credencial do ambiente não tem endereço ou ainda é a provisória do faturamento ([Credenciais TISS](credenciais-tiss.md)). Fora de produção, o mock gera protocolo `SIMULADO-…`, e as telas avisam que nada foi enviado.

Para demonstração, `TISS_ALLOW_SIMULATED_IN_PRODUCTION=true` libera o mock em produção. **Nada chega à operadora**; não use com clínica real faturando. Ambientes fora de produção (`APP_ENV` local ou testing) não são afetados. Um ambiente de teste que rode com `APP_ENV=production` precisa dessa liberação.

Código: `SimulatedTransportGuard` (`app/Domains/Tiss/Services/Transport`), chamado por `SendTissBatchService::send()` e `SendSurgicalGuideService::send()`. Testes: `tests/Feature/Tiss/SimulatedTransportGuardTest.php` e `tests/Feature/Ops/CheckProductionConfigCommandTest.php`.
