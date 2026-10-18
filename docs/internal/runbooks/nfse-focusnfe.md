# Runbook — NFS-e do SaaS via Focus NFe

Data: 2026-10-18 · Escopo: **só a dona do SaaS emite**. Prestador = empresa dona do EasyEye (uma só, global); tomador = a clínica
que pagou uma fatura (assinatura, troca de plano, pacote de créditos de IA). Cortesia e trial não geram fatura paga, logo não
geram nota. Tudo mora em **Manager → Nota fiscal**; a clínica só baixa PDF/XML em **Minha assinatura**.

Fontes: plano `docs/internal/planos/nfse-focusnfe-plano.md` (seções 13–17), API da Focus (`https://doc.focusnfe.com.br`, páginas lidas
em 18/10/2026 — a Focus muda a API sem versão nova; reconferir `updatedAt` antes de mexer no builder), manual do Manager
`docs/internal/manuais/manager-nfse.md`. Hipótese de invalidação: mudança de layout do DPS/NFS-e na Focus, ou refactor do webhook de
billing (os pontos de disparo do `InvoiceFullyReversed` moram nele).

## 1. Cadastro da empresa na Focus (feito UMA vez, no painel da Focus)

O EasyEye **não** cadastra a empresa nem o certificado (`/v2/empresas` fica fora do escopo). No painel da Focus, a dona do SaaS:

1. cadastra o CNPJ prestador, inscrição municipal, regime tributário e **certificado digital A1** (`arquivo_certificado_base64` + senha);
2. habilita **um** dos padrões — `habilita_nfse` (municipal) **ou** `habilita_nfsen_producao` (nacional/DPS). Os dois não convivem em
   produção; o padrão escolhido no Manager tem de ser o habilitado lá;
3. confere série e próximo número (municipal: a Focus controla o RPS; **nacional: quem manda a série/número da DPS é o EasyEye**, ver §3);
4. pode validar o cadastro com `dry_run=1` na API de empresas (token master) antes de produzir;
5. copia **dois tokens por ambiente**: o da *empresa* (emite, consulta, cancela, e-mail, gatilhos) e o *master* (só consultas de município).
   Homologação e produção têm tokens diferentes.

## 2. Configurar no Manager → Nota fiscal

| Seção | O que fazer |
|---|---|
| Prestador | CNPJ, IM, IBGE do município, CNAE, item da lista (LC 116, ex. `01.07`), código tributário municipal, código de tributação nacional (6 dígitos, ex. `010701`), alíquota do ISS (digita em %; grava fração), ISS retido, natureza da operação, optante do Simples, regime especial |
| Padrão e ambiente | `municipal` ou `nacional`; `homologacao` ou `producao` (**padrão seguro = homologação**) |
| Emissão automática | Liga a nota ao pagar. Só liga com prestador completo **e** token do ambiente ativo |
| Credenciais | Token da empresa + master por ambiente. Nunca voltam à tela (só "tem token"); trocar exige motivo ≥ 20 caracteres (auditado) |
| Gatilho (webhook) | "Registrar" cria o hook na Focus; "Remover" apaga |
| Município | "Validar município" mostra o que a prefeitura exige (endereço, CNPJ/CPF, CNAE, item, código tributário, cancelamento por webservice) |
| Clínicas sem IBGE | Preenche o IBGE do tomador (digitado ou achado por nome/UF); é validado na Focus antes de gravar |

Série e próximo número da DPS (padrão nacional): `serie_dps` (1–49999) e `proximo_numero_dps` ficam na mesma tela. O contador é reservado
sob `FOR UPDATE` na criação da nota e **não pode voltar** para número já usado na série. Se a empresa já emitiu DPS fora do EasyEye,
suba o contador acima do último número antes de ligar.

Regime especial: as tabelas do municipal (1–6) e do nacional (0–9) **são diferentes**; o mesmo campo vale para o padrão ativo. Ao trocar de
padrão, revise-o. `optante` + regime `5` vira MEI (`opSimpNac = 2`) no nacional.

## 3. Webhook

- URL: `https://<domínio do app>/api/fiscal/focusnfe/webhook/{token}` (o `{token}` é aleatório, cifrado, só roteia). Mostrada na tela; se o
  domínio público for outro (túnel), `fiscal.webhook.public_url` em `config/fiscal.php` o fixa. A tela recusa registrar URL `http`, `localhost`, `.test`, `.local`.
- Segredo: a Focus manda o valor de `authorization` no cabeçalho **`X-FocusNfe-Secret`**, comparado em tempo constante. Token desconhecido → 404; segredo errado → 401.
- A Focus precisa de **2xx imediato**; o EasyEye responde 200 e processa em fila (`ProcessNfseWebhookJob`). Retentativas da Focus: 1 min, 30 min,
  1 h, 3 h, 24 h (máx. 48 h). Passado isso só a consulta direta resolve — é o papel de `fiscal:nfse-sync`.
- Idempotente por conteúdo (entrega repetida não grava nem audita) e **só avança**: processando → autorizado → cancelado; aviso fora de ordem é ignorado.
- Registrar de novo remove o gatilho antigo primeiro. Trocou o **padrão** (municipal↔nacional)? Registre o gatilho de novo (o evento muda: `nfse` ↔ `nfsen`).

## 4. Homologação → produção (checklist)

1. Em homologação: credenciais, prestador, gatilho registrado, **uma nota manual** emitida e cancelada (ou `FOCUSNFE_HOMOLOG_SMOKE=1`, §10).
2. Cadastrar o token de **produção** (e master) na seção Credenciais do ambiente `producao`.
3. Trocar o ambiente para `producao` (a tela não deixa ligar emissão automática sem token do ambiente).
4. **Registrar o gatilho de produção** (o de homologação não vale lá).
5. Conferir o padrão habilitado na empresa (§1) = padrão do Manager; ajustar `proximo_numero_dps` se necessário.
6. Emitir **uma nota manual** de uma fatura real e conferir número, PDF, XML e e-mail. Só então ligar a **emissão automática**.
7. A janela do lote (`fiscal:nfse-reissue`) é de 90 dias: ao ligar a automática, faturas pagas dos últimos 90 dias sem nota serão emitidas na próxima execução (05:10).
   Para não reprocessar histórico, emita à mão o que quiser e rode `php artisan fiscal:nfse-reissue --dry-run` antes; ou diminua `fiscal.reissue.window_days`.

## 5. Operação diária

- **Emitir**: automática no `InvoicePaid`; manual por fatura (lista "Faturas pagas sem nota" ou drawer da assinatura/pacote). Papel: financeiro ou admin.
- **Estados**: `pending_send` → `processando_autorizacao` → `autorizado` | `erro_autorizacao`; `send_failed` (token/rede/configuração, o envio nem foi avaliado); `cancelado`.
- **Erro de autorização**: a tela mostra os erros da prefeitura campo a campo (`codigo`, `mensagem`, `correcao`). Corrija o cadastro/configuração e use **Emitir** de novo: cria
  **nova linha com nova `ref`** (a antiga fica como histórico; nunca duas valendo para a mesma fatura).
- **Consultar agora** (papel financeiro): GET na Focus e aplica o resultado. **Reenviar e-mail**: até 10 destinatários, só nota autorizada.
- **Lote** `fiscal:nfse-reissue` (diário 05:10): só com emissão automática ligada (ou `--force`). Até 3 notas por fatura, 6 h entre tentativas, nunca fatura com nota **cancelada**.
- **Varredura** `fiscal:nfse-sync` (a cada 10 min): consulta notas paradas em processamento há > 10 min e reenfileira `pending_send` > 15 min (o envio confere a ref na Focus antes de repetir o POST).
- **Estorno** da fatura: total/chargeback cancela a nota autorizada (fila). Parcial **não** cancela — o drawer mostra o aviso e a decisão é humana. Nota ainda em voo fica marcada e é cancelada ao autorizar.
- **Pendência de cancelamento** (`cancel_required`): o cancelamento automático não foi possível (município sem cancelamento, prazo, erro da Focus). Cancele na prefeitura e use "marcar como cancelada externamente", ou cancele pela tela.

## 6. Cancelamento na prefeitura

Município com `possui_cancelamento_nfse = false` (ou nota fora do prazo): o botão Cancelar avisa e o EasyEye **não** chama a Focus. Cancele no portal da prefeitura e, no Manager, use
**Marcar como cancelada externamente** com o motivo (20–255). Fica auditado e sem XML de cancelamento (a clínica não recebe esse arquivo).
Cancelar pelo sistema exige justificativa de 15–255 caracteres e é **definitivo**; só nota autorizada se cancela.

## 7. Retenção de XML (5 anos)

O XML da nota e o do cancelamento ficam no **disco padrão** do projeto (`Storage::disk()`): `nfse/{ambiente}/{Y}/{m}/{ref}.xml` e `…-cancel.xml`
(`storage/app/private/nfse/…` no disco local). **Produção deve usar S3** (ver `config/filesystems.php`): ligar versionamento/replicação do bucket. No disco local, incluir o volume no backup.
Não há rotina de expurgo — a obrigação de guarda é de 5 anos. O XML sai para a clínica só pela rota autenticada; o PDF é o link público da Focus (S3 da Focus).

## 8. Troubleshooting

| Sintoma | Causa provável / ação |
|---|---|
| `send_failed` com "Focus recusou as credenciais" (401) | Token errado/de outro ambiente. Reponha o token, "Testar credencial", **Emitir** de novo |
| `erro_autorizacao` "Regime Especial de Tributação ausente" | Regime especial/optante do prestador; ver tabela do padrão ativo (§2) |
| `endereco_obrigatorio` / `ibge_ausente` ao emitir | O município do prestador exige endereço do tomador: complete o cadastro da clínica e o IBGE (card "Clínicas sem IBGE") |
| `prestador_incompleto` | Campos listados na mensagem (`prestador.cnpj`, `…inscricao_municipal`, `…item_lista_servico`, `…codigo_tributacao_nacional_iss`); alguns municípios exigem CNAE e código tributário |
| `documento_invalido` | CNPJ/CPF da clínica sem dígito verificador válido: corrigir no cadastro da clínica |
| 422 `erro_validacao` na emissão | Texto da própria Focus na tela; campo/limite. Para o nacional, ver lista de campos do DPS |
| Nota autorizada mas sem XML | Falha de rede ao baixar: o download da clínica/Manager busca de novo; `fiscal:nfse-sync` e novas entregas do webhook também completam |
| Webhook não chega | Gatilho registrado? URL pública `https`? Segredo/URL mudaram (re-registrar). Enquanto isso o `fiscal:nfse-sync` cobre |
| 429 da Focus | Respeitado (`Retry-After`); o job volta à fila sozinho |
| Contador da DPS repetido | A tela recusa número ≤ último usado na série; suba o contador |

Logs: `nfse.focus` (op, ref, http, status, duração — nunca corpo/cabeçalho/token), `nfse.send`, `nfse.sync.*`, e o **Log de cobrança** do Manager (envio recusado, pendência de cancelamento).

## 9. Segurança e LGPD

- Tokens (empresa, master), `webhook_token` e `webhook_secret` **cifrados** em `nfse_credentials`; fora do JSON, da auditoria e dos logs. Nenhum endpoint os devolve.
- Payloads (`request_payload`/`response_payload`) saneados (chaves de segredo descartadas) e **fora da auditoria** (ela guarda a mudança de status).
- O tomador é **congelado** na nota (`payer_snapshot`): dado contratual da clínica (razão social, CNPJ, e-mail, endereço), nunca de paciente.
- Isolamento: download da clínica confere `entity_id` no controller (404 para nota de outra clínica) + `billing.contact`; XML nunca por URL pública; token só no servidor.
- Webhook: segredo + token de URL comparados em tempo constante; sem escrita na requisição; limite por token (`fiscal-webhook`).
- Auditoria (`audit_logs`, `admin.audit`): configuração, credenciais (flags, sem segredo), gatilho, IBGE, emitir, cancelar, marcar externa, reenviar e-mail, consultar.
- Notas e faturas com nota **não podem ser apagadas** (FKs `restrict`).

## 10. Smoke opt-in contra a homologação

```
DB_DATABASE=<banco do app> FOCUSNFE_HOMOLOG_SMOKE=1 ./vendor/bin/pest tests/Integration/FocusNfeHomologSmokeTest.php
```
Emite uma nota de teste com tomador sintético, espera autorizar, baixa o XML e cancela. Trava dupla: variável + credencial de **homologação** com token no banco lido; só o host
`homologacao.focusnfe.com.br`; nada é gravado. Opcionais: `FOCUSNFE_SMOKE_TIMEOUT`, `FOCUSNFE_SMOKE_EMAIL`.

## 11. Deploy

1. **Migrations** (aditivas): `nfse_credentials`, `nfse_documents` (com índices únicos parciais: uma nota ocupando por fatura; DPS única por ambiente/série/número) e `entities.ibge_code`.
2. **Seeder** `SubscriptionSettingSeeder` (cria só as chaves `nfse.*` que faltam; **nunca sobrescreve** o que o dono digitou). Sem o seeder o sistema funciona com os padrões do código.
3. **Scheduler** (cron `schedule:run` já existente): `fiscal:nfse-sync` a cada 10 min e `fiscal:nfse-reissue` às 05:10.
4. **Fila**: os jobs/listeners usam a fila `FISCAL_QUEUE` (opcional, padrão `default`) — o worker existente já serve. Reiniciar os workers no deploy (listeners novos).
5. **Env**: nenhuma obrigatória. Nada de token em `.env`; tudo entra pelo Manager.
6. Comece em **homologação** e a emissão automática **desligada** (padrões de fábrica).

## 12. Riscos conhecidos

- **CNPJ alfanumérico (IN RFB 2.229/2024)**: o EasyEye envia o documento canônico com letras (prestador e tomador). A documentação da Focus ainda diz "caracteres não numéricos são ignorados"; se ela
  remover as letras, a nota é recusada/sai com documento errado. Validar com a Focus antes de uma clínica com CNPJ novo (letras) pagar; a nota de CPF/CNPJ numérico não é afetada.
- **Reforma Tributária (IBS/CBS)**: os campos RT do DPS (`ibs_cbs_*`, `finalidade_emissao`, `consumidor_final`, `indicador_destinatario`…) ficam **de fora**, como no exemplo oficial. Se o ambiente
  nacional passar a exigi-los, a emissão volta `erro_autorizacao` com a lista — ajustar `NationalNfsePayloadBuilder`.
- **Municípios sem cancelamento por webservice**: estorno total deixa `cancel_required` e o cancelamento é manual na prefeitura (§6).
- **Local do serviço**: `servico.codigo_municipio` / `codigo_municipio_prestacao` = município do **prestador** (ISS devido no estabelecimento prestador, LC 116 art. 3º). Se o contador orientar outro, é uma linha nos builders.
- **Simples/MEI**: `optante` + regime `5` = MEI; sem isso optante = ME/EPP (3). Regime especial muda de tabela entre os padrões (§2).
- **Valor**: a nota é do valor **pago**; estorno parcial posterior não reescreve a nota (decisão humana).
