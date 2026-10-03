# Provedores de IA e LGPD

Registro dos provedores de IA do EasyEye quanto a **dados de pacientes**: quem pode
receber, onde processa, com que base vai para fora do Brasil e o que o sistema impõe.
Fatos conferidos nas fontes oficiais em **03/10/2026** (`ProviderDataPolicy::CHECKED_AT`).
Este documento descreve a operação técnica; **não substitui a análise do jurídico**, que
deve confirmar contratos, mecanismos e textos antes do uso em produção.

## 1. Papéis no tratamento

- **Clínica** (cliente do SaaS): controladora dos dados dos seus pacientes. Dado de saúde é
  dado pessoal sensível (LGPD art. 5º, II e art. 11).
- **EasyEye**: operador — trata em nome da clínica, segundo as instruções dela (art. 39).
- **Provedor de IA**: suboperador contratado pelo EasyEye. Quando processa fora do Brasil,
  há transferência internacional (art. 33).

## 2. O que o sistema faz

| Salvaguarda | Onde |
|---|---|
| Contexto clínico mínimo: sem nome nem iniciais (só idade e sexo), idade em vez de data de nascimento, sem código do paciente nem do prontuário | `AiMedicalContextBuilder` |
| Nome e apelido do paciente digitados no pedido viram o marcador `<PATIENT_NAME_REDACTED>`; e-mail, CPF, CNPJ, telefone e CEP são mascarados (filtro de identificadores — **não é anonimização completa**); o preâmbulo manda a IA não deduzir nem reproduzir os marcadores | `AiPromptGuardrailService`, `AiPayloadEnricher`, `AiSystemPromptResolver` |
| Imagem de exame nunca vai a quem não processa imagem (Maritaca, que faria OCR com terceiros) | `OpenAiCompatibleProvider::supportsVision()` |
| Dados do paciente gravados no pixel da imagem (nome, nascimento, ID, data, texto livre do exame) são tarjados antes de sair, pelo layout reconhecido do equipamento; layout não reconhecido = a imagem **não sai** (aviso ao médico; sem nenhuma imagem, a análise falha sem cobrar). Só a cópia enviada é tarjada — o original não muda. O que saiu de cada imagem fica em `ai_run_patient_exam.image_deidentification` | `ExamImageDeidentifier`, `ExamImageLayouts`, `EyeImageAttachmentService` |
| Gemini API não entra em papel do assistente; em execução real, se tiver sobrado num papel salvo, é ignorada; fixada num fluxo, só atende quem declara não levar dado de paciente (posologia do catálogo) | `ProviderDataPolicy`, `AiProviderSettings::enabledCodes()`, `AiProvidersController::update()`, `AiOrchestrator` |
| Provedor que leva dado de paciente para fora do Brasil sem adequação só entra num papel depois de registrado o mecanismo de transferência; quem já estava em uso segue, com aviso na tela, até o registro | `AiProvidersController` (papéis e modelo), drawer → Proteção de dados |
| Registro e remoção do mecanismo auditados (`manager.ai_providers.transfer`, `manager.ai_providers.transfer_removed`); a tela guarda só a referência ao documento | `audit_logs` |
| Cada execução registra o que saiu, sem o conteúdo: impressão digital (SHA-256) das instruções enviadas, categorias de dados (prontuário, demografia, metadados de exame, imagens, histórico de conversa, texto do pedido…), chaves do contexto e nº de imagens | `ai_runs.dispatch_audit` (`AiDispatchAudit`) |

### Imagens de exame (tarja por layout)

Layouts reconhecidos hoje (calibrados em 03/10/2026 com exportações reais): Oculus
Pentacam com telas em português (ficha à esquerda — Mapas, 4 Mapas, Av. Refrativa, Anéis
Corneanos — e Ectasia Reforçada Belin), Oculus Keratograph (Topo Overview e Topo 4-Maps),
Oculus Pachycam e Tomey EM-3000. Outro equipamento, idioma da tela, resolução de
exportação ou relatório com a ficha em outro lugar não é reconhecido e a imagem fica fora
da IA (retinografias e OCTs incluídos, até ganharem o seu layout). Para incluir um layout,
com amostras reais (que não entram no repositório): `php artisan ai:exam-image-layout
<imagens> --anchor=x,y,w,h --out=<pasta>` mostra o reconhecimento, gera a impressão digital
das regiões fixas e grava a imagem tarjada para conferência.

## 3. Suboperadores

| Provedor | Onde processa | Base para sair do Brasil | Dados de pacientes |
|---|---|---|---|
| Maritaca (Sabiá) — modelos `-br-sp` | Brasil | Não há transferência | Permitido |
| Maritaca (Sabiá) — sem sufixo | Brasil, EUA ou UE (Google Cloud) | Mecanismo contratual (art. 33, II) | Permitido com registro |
| Azure OpenAI — `AI_AZURE_OPENAI_DATA_REGION=eu` | UE (Standard regional, ex.: swedencentral) | Adequação da UE (Resolução CD/ANPD nº 32/2026) | Permitido |
| Azure OpenAI — `br` | Brasil (Provisioned em brazilsouth) | Não há transferência | Permitido |
| Azure OpenAI — `global` | Qualquer região (Global/Data Zone) | Mecanismo contratual | Permitido com registro |
| Mistral AI | UE | Adequação da UE | Permitido |
| OpenAI, Groq | EUA | Mecanismo contratual | Permitido com registro |
| Anthropic | Inferência em qualquer região por padrão (`inference_geo` global); dados guardados nos EUA | Mecanismo contratual | Permitido com registro |
| xAI (Grok) | Sem garantia de região no endpoint padrão (`us.api.x.ai` restringe aos EUA) | Mecanismo contratual | Permitido com registro |
| Gemini API (Google) | — | — | **Bloqueado**: os termos da Gemini API proíbem o uso em prática clínica |

Fontes oficiais de cada provedor: `ProviderDataPolicy::sources()` (também no drawer do
provedor). Retenção, uso para treino, monitoramento de abuso e retenção zero (ZDR) mudam
por contrato e plano — confirme no contrato assinado com cada provedor antes de usar.

Além da LGPD, vale a política de uso de cada provedor: a Anthropic classifica saúde como
uso de alto risco (profissional qualificado revisa antes de finalizar; avisar quando a
saída for mostrada direto ao paciente); o Google proíbe uso clínico na Gemini API e a
documentação do Google Cloud traz restrição equivalente para os serviços de IA
generativa — trocar a Gemini API pelo Vertex AI não resolve sem confirmação do Google.

DeepSeek (dados na China, uso para treino) e OpenRouter (repassa a terceiros com políticas
próprias) foram **retirados** da lista pronta em 03/10/2026 — não há integração com eles.

## 4. Mecanismo de transferência internacional

- **UE**: coberta pela decisão de adequação da ANPD (Resolução CD/ANPD nº 32/2026) — não
  exige registro no painel.
- **Demais destinos**: o contrato precisa de um mecanismo do art. 33, II. O usual são as
  **cláusulas-padrão contratuais da ANPD** (Resolução CD/ANPD nº 19/2024), obrigatórias nos
  contratos desde agosto/2025. Nas DPAs conferidas, só a do Google cita as cláusulas
  brasileiras; Microsoft, Anthropic, Mistral, xAI e Groq não mencionam a LGPD, e a da
  OpenAI não pôde ser confirmada. Na prática: peça ao provedor o
  aditivo com as cláusulas-padrão da ANPD (ou confirme se ele já as oferece).
- **No painel** (Provedores de IA → Ver detalhes → Proteção de dados): mecanismo
  (cláusulas-padrão, cláusulas específicas ou normas corporativas globais aprovadas pela
  ANPD), referência do documento e data de assinatura. Registre **depois** de assinado; o
  documento fica com o jurídico.
- **Transparência**: a Resolução CD/ANPD nº 19/2024 também trata da informação ao titular
  sobre transferências internacionais (incluindo o acesso às cláusulas utilizadas) — o
  jurídico deve definir o formato (ex.: página pública com destinos e mecanismos).

## 5. Configuração recomendada

**Azure OpenAI (UE)**

1. No Azure AI Foundry, crie o deployment como **Standard regional** em **swedencentral**,
   com o nome do modelo (ex.: `gpt-4o-mini`). Global e Data Zone processam fora da UE.
2. `.env`: `AZURE_OPENAI_API_KEY`, `AI_AZURE_OPENAI_BASE_URL=https://{recurso}.openai.azure.com/openai/v1`,
   `AI_AZURE_OPENAI_MODEL` (nome do deployment) e `AI_AZURE_OPENAI_DATA_REGION=eu`.
3. Cadastre o modelo em Modelos e preços → Novo modelo (a API do Azure não lista
   deployments) e confira o preço regional, que costuma ser maior que o Global.
4. Peça à Microsoft o **monitoramento de abuso modificado**: sem ele, prompts e respostas
   podem ficar guardados por até 30 dias e passar por revisão humana.
5. Em brazilsouth o Standard regional não tem modelos GPT de chat; só Provisioned (PTU,
   mínimo de 25–50 PTU, cobrança por hora) — nesse caso, `AI_AZURE_OPENAI_DATA_REGION=br`.

**Maritaca (Brasil)**: use os modelos com sufixo `-br-sp` (padrão `sabia-4-br-sp`) —
processam 100% no Brasil (+30% no preço). Os preços oficiais são em R$ e a sincronização
converte para US$ pela cotação da última recarga de provedor.

## 6. Checklist do dono do SaaS

1. Escolher os provedores de produção.
2. Para cada um que exige registro: assinar o aditivo com o mecanismo e registrá-lo no painel.
3. Ambiente de teste: se o Gemini estiver num papel, trocar por Azure (Suécia), Mistral,
   Sabiá `-br-sp` ou Anthropic (com registro) — o Gemini deixa de ser chamado após o deploy.
4. Incluir os suboperadores no registro das operações de tratamento e no contrato com as
   clínicas (autorização para subcontratar, com a lista e os países).
5. Revisar a Política de Privacidade (próximo item).

## 7. Política de Privacidade (próxima versão)

A versão 1.0 (`resources/legal/privacy-policy-1.0.txt`) cita só OpenAI, Anthropic e Google
Gemini na seção 6 e trata transferências de forma genérica na seção 8. Conteúdo não muda
uma versão publicada: publique uma nova `TermVersion` (com a tradução `.en.txt`) depois da
revisão jurídica, com a lista de provedores efetivamente usados, os países (Brasil, UE,
EUA) e os mecanismos (adequação da UE; cláusulas-padrão da ANPD). Não afirme retenção zero
ou não uso para treino sem o compromisso contratual correspondente.

## 8. Manutenção

Mudou o termo, a DPA ou a região de um provedor: atualize `ProviderDataPolicy` (perfil,
fontes e `CHECKED_AT`), este documento e, se for o caso, a Política de Privacidade.
