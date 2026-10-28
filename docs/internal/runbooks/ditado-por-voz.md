# Ditado por voz do laudo (Fase 5) — ligar em produção

O médico (ou a secretária, ao preparar um rascunho) grava a fala no editor do laudo e o
**servidor** transcreve num provedor de IA aprovado pela política de LGPD; o texto volta e
entra no ponto do cursor. O navegador **não** usa o reconhecimento de voz do Chrome/Edge
(que manda o áudio ao Google sem contrato) — o áudio é dado de saúde e só sai por um
provedor com contrato e registro. Decisão e salvaguardas: [Política de provedores de IA —
§8](../legal/ai-providers-lgpd.md). Uso pelo usuário: manual do
[médico](../manuais/clinic/medico/README.md) e da [secretária](../manuais/clinic/secretaria/README.md).

**Princípio de entrada:** o ditado nasce **desligado de fábrica** e é **global** (não é
recurso de plano nem configuração por clínica). Subir o código não muda nada para ninguém:
o botão **Ditar** só aparece quando o recurso está ligado, o provedor está habilitado no
Manager e a política LGPD fecha — tudo isso é conferido a cada transcrição. Cada degrau
abaixo é uma decisão de uma pessoa, com como voltar atrás.

## 1. O que liga o quê

| Peça | Onde | Efeito |
|---|---|---|
| Chave de fábrica `AI_TRANSCRIPTION_ENABLED=false` | `.env` → `config/ai.php` → `transcription` | Desligada, o botão **Ditar** não aparece (`dictation.available=false`) |
| Provedor escolhido (`AI_TRANSCRIPTION_PROVIDER`) | `.env` | Qual provedor transcreve; tem de estar na lista de aceitos (§3) |
| Provedor habilitado **no Manager** | Manager → Provedores de IA → **Papéis do assistente** | **Fonte de verdade** lida a cada transcrição: a chave no `.env` sozinha não habilita nada, e retirar o provedor no Manager corta o envio **na hora** |
| Chave e endereço do provedor | `.env` (`MISTRAL_API_KEY`, `AI_MISTRAL_BASE_URL`, …) | Sem chave/endereço, o provedor não conta como habilitado e o ditado fica indisponível |
| Mecanismo de transferência internacional, quando exigido | Manager → Provedores de IA → drawer → **Proteção de dados** | Sem o registro, provedor que leva dado de paciente para fora do Brasil sem adequação não recebe o áudio |
| Aviso na tela | `ExamManagerReportModal.vue` | Já ligado: "o áudio vai a um provedor aprovado, não é guardado, não cite o nome do paciente" |
| Trilha de auditoria | `audit_logs`, evento `eye_images.report.dictation` | Já ligado: a **intenção** é gravada antes do envio e o desfecho completa a linha; falha fechado (§6) |

Nada é configurado na clínica. O painel de **Uso e créditos** de IA **não** vê o ditado: ele
não consome créditos de IA (o custo é por minuto de áudio, direto no provedor).

## 2. Antes de ligar (não é código — bloqueia o passo 3)

- **Contrato do provedor conferido por escrito:** retenção do áudio e do texto, uso para
  treino/modelo, retenção zero (ZDR) se for o caso, subprocessadores e região. Provedores
  costumam guardar entradas por até 30 dias para monitoramento de abuso **salvo ZDR
  contratada** — confira o provedor que vai ser ligado. Anote a conclusão na política de
  privacidade/registro de operações (LGPD art. 37): o áudio é dado de saúde e **não dá para
  redigir** antes do envio (por isso o aviso na tela).
- **Decisão de custo/franquia:** o gasto é por minuto de áudio no provedor, fora do painel de
  uso de IA. Definir se haverá franquia por plano (hoje não há).
- O item de política de privacidade que cita o ditado e a lista de provedores efetivos
  (países e mecanismos) depende de aval jurídico — ver [§8](../legal/ai-providers-lgpd.md).

## 3. Provedores que o ditado aceita

Só entram os que aceitam dado de paciente **e** falam o protocolo de transcrição compatível
com OpenAI (`POST {base_url}/audio/transcriptions`, multipart): `SpeechTranscriptionService::PROVIDERS`.

| Código (`AI_TRANSCRIPTION_PROVIDER`) | Modelo (`AI_TRANSCRIPTION_MODEL`) | Onde processa | Transferência (LGPD art. 33) | Exige registro no painel? |
|---|---|---|---|---|
| `mistral` | `voxtral-mini-latest` (padrão do `config/ai.php`) | UE | Adequação (Res. CD/ANPD nº 32/2026) | Não |
| `azure_openai` | nome do **deployment** criado no Azure (obrigatório) | Conforme `AI_AZURE_OPENAI_DATA_REGION`: `br` = Brasil, `eu` = UE, outro = global | Brasil: não há; UE: adequação | Só sem região declarada (`global`) — cláusulas-padrão |
| `openai` | `whisper-1` | EUA | Cláusulas-padrão da ANPD (Res. 19/2024) | **Sim** |
| `groq` | `whisper-large-v3` | EUA | Cláusulas-padrão da ANPD | **Sim** |

Ficam de fora, por decisão: Gemini (os termos do Google proíbem uso em prática clínica),
Anthropic, xAI e Maritaca (`-br-sp`). Qualquer um sem o endpoint de transcrição é recusado.

## 4. Ligar (ordem)

1. **Chave e endereço do provedor no `.env`** (a mesma do assistente, se já usada):
   `MISTRAL_API_KEY` + `AI_MISTRAL_BASE_URL` · `OPENAI_API_KEY` + `AI_OPENAI_BASE_URL` ·
   `GROQ_API_KEY` + `AI_GROQ_BASE_URL` · `AZURE_OPENAI_API_KEY` + `AI_AZURE_OPENAI_BASE_URL`
   (**obrigatório** no Azure: não há endereço padrão) e `AI_AZURE_OPENAI_DATA_REGION`.
2. **Ligar o ditado** (`config/ai.php` → `transcription`, bloco já comentado no `.env.example`):
   `AI_TRANSCRIPTION_ENABLED=true`, `AI_TRANSCRIPTION_PROVIDER=<código>`,
   `AI_TRANSCRIPTION_MODEL=<modelo>` (no Azure, o **nome do deployment**),
   `AI_TRANSCRIPTION_LANGUAGE=pt`, `AI_TRANSCRIPTION_MAX_SECONDS=120`,
   `AI_TRANSCRIPTION_PER_MINUTE=10`, `AI_TRANSCRIPTION_TIMEOUT_SECONDS=60`.
3. **Habilitar o provedor no Manager** (Manager → Provedores de IA → **Papéis do assistente**):
   escolha o provedor em **Principal**, **Revisor** ou **Árbitro**. O ditado usa a mesma
   lista de provedores habilitados do assistente (`AiProviderSettings::enabledCodes()`), lida a
   cada transcrição. Consequência a saber: o provedor do ditado fica disponível também para os
   papéis do assistente — se isso não for desejado, use um provedor já habilitado.
4. **Registrar o mecanismo de transferência**, se o provedor exigir (§3): drawer do provedor →
   **Proteção de dados** → *Mecanismo* (cláusulas-padrão / específicas / normas corporativas) +
   *Referência* ao documento + *Assinado em*. O EasyEye guarda só a referência, nunca o documento.
5. `php artisan config:cache && php artisan queue:restart` e confirme que o worker da fila está no ar.

## 5. Conferir depois de ligar

| O que | Como | Esperado |
|---|---|---|
| Recurso disponível | tela do Gerenciador de exames (o botão **Ditar** aparece para quem prepara laudo) | botão visível; a prop `dictation.available` é `true` |
| Resolução do provedor | `php artisan tinker --execute="dump(app(App\Domains\AI\Services\SpeechTranscriptionService::class)->resolve());"` | `['provider' => …, 'model' => …]`; `null` = algum degrau faltando (§7) |
| Transcrição real | ditar 2–3 s num laudo de teste | texto entra no cursor; nada de erro na tela |
| Trilha de auditoria | `SELECT created_at, user_id, target_entity_id, new_values FROM audit_logs WHERE event = 'eye_images.report.dictation' ORDER BY created_at DESC LIMIT 5;` | uma linha por ditado, `outcome` de `sending` para `transcribed`/`no_speech`/`provider_failed`; `provider`, `model`, `audio_seconds`, `audio_bytes`, `audio_sha256` — **nunca** o áudio nem o texto |
| Áudio não guardado | procurar o arquivo temporário / `storage/` após um ditado | nada gravado; nada de texto transcrito no log |
| Falha não poluída | `grep -i speech.transcription storage/logs/*.log` | só `speech.transcription.failed` com `provider` e `status` (sem corpo de resposta) |
| Teto por minuto | ditar mais que `AI_TRANSCRIPTION_PER_MINUTE` vezes em 1 min | 429 no excesso; o balde é por usuário + clínica |
| Suíte automatizada | `./vendor/bin/pest tests/Feature/ExamManager/ExamManagerReportDictationTest.php` | verde |

O evento aparece na exportação de conformidade (Relatórios → **Compliance & Auditoria**, só
admin: Data/Hora, Usuário, Evento, Modelo, ID, IP, User-Agent — **sem** os detalhes). Os
detalhes (provedor, duração, SHA-256, `outcome`) vivem em `new_values` — só por consulta ao
banco.

### O que cada `outcome` significa

| `outcome` | Quando | O que prova |
|---|---|---|
| `sending` | Linha gravada **antes** do envio (transação já comitada) com a **intenção**: provedor, modelo, duração, bytes e SHA-256 | Só que o sistema ia enviar e registrou a intenção. **Não** prova que o áudio chegou ao provedor: o envio pode ter sido abandonado no meio e a linha fica só com `sending`. Não use `sending` como prova de transferência |
| `transcribed` (+ `text_characters`) | O provedor respondeu com texto | O áudio **chegou** ao provedor e foi processado; `text_characters` é a dimensão do retorno (não o conteúdo) |
| `no_speech` | O provedor respondeu sem fala reconhecida | O áudio **chegou** ao provedor e não tinha fala |
| `provider_failed` | Erro de conexão ou resposta não-2xx do provedor | Houve tentativa de envio e ela falhou — o registro não separa "não chegou" (conexão) de "chegou e foi recusado" (não-2xx) |
| `not_sent` | Trilha existe mas nada saiu (ex.: arquivo temporário ilegível) | O áudio **não** saiu |

**Para responder a um pedido de titular:** só `transcribed` e `no_speech` atestam que o provedor
recebeu e processou o áudio. `sending` sozinho é a intenção (o envio pode nem ter saído) e
`provider_failed` é tentativa sem desfecho conhecido. É isso — e não "houve transferência" — que
a trilha permite afirmar.

Áudio recusado na validação (formato, curto demais, acima do teto) **não** deixa linha: nada saiu.

## 6. Quando a tela mostra 503 `dictation_audit_failed`

Significa: **a trilha de auditoria não pôde ser gravada e o áudio NÃO foi enviado** (falha
fechada — o sistema prefere não transcrever a entregar dado de saúde sem registro). A frase
ao usuário: "A trilha de auditoria do envio do áudio não pôde ser gravada; nada foi enviado.
Tente de novo."

1. Mensagem para o usuário no momento: **nada foi enviado** e pode ditar de novo. Não é o
   provedor de IA; não adianta trocar de provedor.
2. No servidor: a linha `speech.transcription.audit_failed` em `storage/logs/laravel.log` traz
   **só a classe e o código** da exceção — a mensagem original do driver **não** é gravada (de
   propósito: costuma trazer valores da linha) e tampouco vai para a trilha em `audit_logs` (nesse
   caminho a linha nem chega a ser escrita). Como a exceção é capturada aqui, o Sentry **não**
   recebe este erro por este caminho: procure no Sentry os erros de banco **não tratados** da
   mesma janela de tempo (mesma causa provável: banco fora, conexão/pool esgotado, disco) e, se
   houver, nos logs do PostgreSQL e da infra. Olhe o banco: conexão/espaço, escrita em
   `audit_logs` (permissão/trigger), e se a requisição não estourou o tempo de transação.
3. Resolvido o banco, o ditado volta sozinho — não há estado a limpar.
4. **Nunca** contorne desligando a trilha ou trocando por um registro que engole erro de
   banco: seria enviar dado de saúde sem prova de envio. Se persistir, desligue o ditado
   (`AI_TRANSCRIPTION_ENABLED=false`) até o banco voltar.

## 7. Outras respostas e o que fazer

| Resposta | Mensagem | Causa provável | O que fazer |
|---|---|---|---|
| 503 `dictation_unavailable` | "O ditado por voz não está disponível no momento." | Chave desligada; provedor fora da lista (§3); modelo vazio; provedor **não habilitado no Manager**; chave/base_url ausente do `.env`; política de dado de paciente; ou falta o registro de transferência | Conferir degrau por degrau com o `resolve()` do §5 |
| 503 `dictation_audit_failed` | ver §6 | Trilha não gravou | §6 |
| 502 `dictation_provider_failed` | "O serviço de transcrição não respondeu. Tente de novo em instantes." | Conexão, timeout ou erro (401/429/5xx) do provedor | Chave/endereço do provedor, cota e saúde dele; o log traz só `provider` e `status` |
| 422 `dictation_invalid_audio` | "O áudio enviado não é válido ou ficou curto demais. Grave de novo." | WAV PCM fora do que o inspetor aceita — 8.000–48.000 Hz, 1–2 canais, 8/16/24/32 bits, cabeçalho coerente (`byteRate` = taxa × canais × bits/8) — ou abaixo de 0,4 s | Navegador do usuário / microfone; gravar de novo |
| 422 `dictation_too_long` | "…passou do limite de :seconds segundos." | Acima de `AI_TRANSCRIPTION_MAX_SECONDS` (medido pelos frames reais, não pelo que o arquivo declara) | Ditar em partes; para ditar mais, subir o teto **e** o custo por chamada |
| 422 `dictation_no_speech` | "Nenhuma fala reconhecida. Verifique o microfone e tente de novo." | Silêncio/ruído | Microfone; ditar de novo |
| 429 | (limite) | Acima de `AI_TRANSCRIPTION_PER_MINUTE` | Esperar; subir o teto só com custo na conta |

O upload também tem teto de tamanho: `AI_TRANSCRIPTION_MAX_KB` (padrão 6144 KB ≈ 192 s de WAV
16 kHz mono — é rede de segurança; quem manda é o teto de duração).

## 8. Como voltar atrás

1. **Cortar o ditado para todos, na hora:** retirar o provedor dos papéis no Manager
   (Manager → Provedores de IA → Papéis do assistente) — vale na requisição seguinte, sem deploy.
2. **Desligar o recurso:** `AI_TRANSCRIPTION_ENABLED=false` + `php artisan config:cache`
   (o botão **Ditar** desaparece; não afeta o assistente de IA).
3. **Trocar de provedor:** mudar `AI_TRANSCRIPTION_PROVIDER`/`MODEL`, habilitar o novo no
   Manager e registrar o mecanismo de transferência, se exigido. O áudio passa a ir para o novo
   a partir da requisição seguinte; não há fila nem reprocessamento.
4. Nenhum dado clínico é gravado pelo ditado, então não há o que apagar — apenas a trilha em
   `audit_logs`, que **não** deve ser apagada.

## 9. Limites assumidos (não são gaps escondidos)

- **Retenção do áudio no provedor** depende do contrato, não do código: o EasyEye não guarda o
  áudio (lido do upload, enviado e descartado) nem o texto. Se o provedor retiver, isso está no
  contrato dele — por isso o passo 2 é bloqueante.
- **Sem diarização, sem pontuação garantida, sem vocabulário clínico:** é transcrição de fala
  comum; nomes próprios e termos podem sair errados. Por isso o aviso "não cite o nome do
  paciente" e a revisão obrigatória antes de emitir.
- **O ditado não vira ato clínico:** o texto entra no editor; quem assina é o médico.
- **Não há franquia nem medição por clínica** do custo do ditado (fica no provedor).
- **Um teto por ditado** (`AI_TRANSCRIPTION_MAX_SECONDS`, padrão 120 s) é também o teto de
  custo por chamada; ditados longos são feitos em partes.
- **Só provedores com o protocolo de transcrição compatível com OpenAI** e que aceitam dado de
  paciente; Azure usa o nome do deployment como modelo (o modelo do catálogo não vale aqui).
- O ditado exige microfone e navegador com gravação (`MediaRecorder`) e conversão para WAV no
  cliente; navegador sem isso mostra "Este navegador não permite gravar áudio para o ditado."
