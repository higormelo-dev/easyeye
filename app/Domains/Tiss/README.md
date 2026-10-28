# Módulo TISS (Domínio)

Exemplo de uso no Laravel:

```php
use App\Domains\Tiss\Services\TissWorkflowService;

$workflow = app(TissWorkflowService::class);

$batch = $workflow->createBatch([
    'entity_id' => $entityId,
    'operator_id' => $operatorId,
    'contract_id' => $contractId,
    'reference_month' => now()->format('Y-m'),
]);

$guide = $workflow->createGuide([
    'entity_id' => $entityId,
    'operator_id' => $operatorId,
    'contract_id' => $contractId,
    'patient_id' => $patientId,
    'doctor_id' => $doctorId,
    'guide_type' => 'sadt',
    'items' => [
        [
            'tuss_code' => '30301257',
            'description' => 'TOMOGRAFIA DE COERÊNCIA ÓPTICA',
            'quantity' => 1,
            'unit_amount' => 200,
        ],
    ],
]);

$workflow->attachGuideToBatch($batch, $guide);
$workflow->generateXmlInQueue($batch);
$workflow->sendBatchInQueue($batch);
```

Gerar o XML é seguro para repetir (`GenerateTissXmlService::generateBatch`): lote que já
saiu para a operadora (`sending` em diante) devolve o XML enviado, e o documento que já
foi oferecido ao transporte só é regerado se o CONTEÚDO mudou (data/hora do cabeçalho e
hash do epílogo não contam — `TissXmlContentFingerprint`). A chave de idempotência do
envio nasce do hash do arquivo; regerar com conteúdo igual criava chave nova e o lote
aceito saía de novo.

O envio (`SendTissBatchService::send`) escolhe o XML do lote e reivindica a transmissão numa
transação que começa travando a linha do lote (a mesma trava da geração) e confere se o XML
escolhido ainda é o do lote: se outra requisição o regerou no intervalo, adota o atual em vez
de enviar o velho. Lote cancelado nesse intervalo não é enviado.

Entrega em dúvida: se a operadora NÃO dá resposta conclusiva (exceção de transporte, HTTP 408, erro do
servidor 500, erro de gateway 502/503/504, timeout) — ou a tentativa morre sem gravar resposta —, ela
pode ter recebido o lote (no 500, o servidor pode ter gravado o lote e falhado ao montar a resposta; só
a recusa explícita do protocolo no corpo, `statusProtocolo`/`situacaoProtocolo` "Rejeitado", faz do 500
um veredito). A
transmissão fica `timeout` e o lote continua `sending` (travado para alterações e para regerar o
XML); o reenvio usa o MESMO XML e a MESMA chave, mesmo que o dado do lote tenha mudado.

A dúvida só sai com o ACEITE da operadora. Depois dela, nenhuma falha seguinte a desfaz — 401, erro
HTTP devolvido, endpoint ausente (que nem sai da clínica): nada disso diz se o primeiro envio chegou.
A retomada que falha mantém transmissão `timeout`, lote `sending` e chave `uncertain` (a mensagem ao
usuário passa a ser `tiss_send.delivery_still_unknown`: corrigir a causa e reenviar). Resposta de erro
da operadora (HTTP 4xx, ou 500 com recusa explícita do protocolo) ou endpoint ausente numa transmissão SEM
dúvida anterior é definitiva:
lote em `error`, editável. `SendTissBatchJob::failed()` e `GenerateTissBatchXmlJob::failed()` não
derrubam para `error` um lote que já saiu (enviando, enviado, processado...).

Ordem de locks: a linha do lote é sempre a 1ª (lote → transmissão → protocolo → guias). O processamento
do retorno (`ProcessTissReturnService`) trava o lote antes de glosas, protocolo e guias e relê tudo depois da
trava — o reenvio forçado de transmissão confirmada atualiza o protocolo existente com o lote travado, e
processar o retorno na ordem inversa (protocolo, depois lote) dava deadlock (40P01).

Retorno processado durante o reenvio: a requisição ao transporte não segura trava, e o processamento de um
retorno nesse intervalo deixa o lote entregue (processado/parcialmente pago/confirmado, guias pagas). O
fechamento do envio relê o lote sob a trava; se ele ficou entregue, só registra o resultado do reenvio
(transmissão, protocolo sem recuar, chave `completed`) — nunca devolve o lote a `error` nem o rebaixa, e às
guias, a `sent`. O mesmo vale na reivindicação: lote entregue por retorno não é retomado por um reenvio.

Retorno processado é final (idempotência): o job `ProcessTissReturnJob` é entregue "pelo menos uma vez" — se o
worker morre depois de comitar o processamento e antes de confirmar o job, ele volta à fila. `ProcessTissReturnService::process`
trava o lote, depois a linha do retorno (`FOR UPDATE`; ordem lote → retorno → protocolo → guias), relê o retorno e, se ele já
está `processed`, devolve sem escrever nada. Efeito financeiro e marca `processed` ficam na mesma transação. Sem o guarda,
`ProcessGlosaReturnService` somava a glosa de novo em `denied_amount` (50 virava 100 e o pago caía de 100 para 50) e um retorno
antigo reentregue regredia protocolo, lote e guias por cima do desfecho de um retorno mais novo. Só `processed` é pulado
(`received`/`parsed`/`error` seguem processáveis) e o `failed()` do job não rebaixa um retorno `processed` para `error`.

Importação idempotente por ARQUIVO (`ReceiveTissResponseService::receive`): o guarda acima é por retorno, e o MESMO XML
importado duas vezes (duplo clique, importação repetida) virava dois retornos — `ProcessGlosaReturnService` chaveia a glosa
por `return_id` e soma em `denied_amount` (glosa de 150,50 virava 301,00 e o pago ia a zero). A identidade do arquivo é
(clínica, operadora, SHA-256 dos bytes) — o `file_hash` que o documento `return_xml` já grava, então retornos importados
antes da correção também são reconhecidos, sem backfill. Retorno desse arquivo já `processed`: nada é gravado, travado ou
despachado e o resultado vem com `alreadyImported` (`ReceivedTissReturn`); a tela recusa como erro do campo `xml_file`, com
a data da importação original. Retorno `received`/`parsed`/`error` (a 1ª tentativa caiu depois do commit): é RETOMADO — o
MESMO retorno é processado e nunca nasce o 2º; sem isso o "tente novamente" que a tela manda fazer não teria saída.
Serialização: `pg_advisory_xact_lock` por (clínica, operadora, hash) como 1ª instrução da transação de `receive()`
(`ReceiveTissResponseService::importLockKey()`), antes do `Storage::put` (o arquivo em disco é nomeado pelo SHA-256 dos bytes,
`tiss/{clínica}/returns/{hash}.xml`, nunca por texto do XML — um `numeroProtocolo` com `..` escreveria fora do prefixo da clínica), e releitura sob a trava (READ COMMITTED); fora do
PostgreSQL só a releitura barra a repetição sequencial. Invariantes (no PHPDoc de `receive()`): a chave é a 1ª trava; nenhum
`FOR UPDATE` de lote/retorno/protocolo sob ela; `process()` e o despacho ficam FORA da transação; `receive()` nunca roda
dentro de transação que já segure lote ou protocolo; nenhuma outra rotina toma a chave. Limites assumidos: identidade por
bytes exatos (arquivo regravado com BOM/CRLF ou reexportado pela operadora com data/hora nova é outro arquivo e soma de novo);
o arquivo NÃO é conferido com a operadora do convênio escolhido na tela (importar pelo convênio errado grava o retorno e um
protocolo novo na operadora errada e, se o `numeroLote` casar com um lote dela, processa lote e guias dela; os mesmos bytes
sob outro convênio são OUTRO arquivo de propósito — a identidade inclui a operadora — e não existe tela para desfazer);
retorno `processed` ÓRFÃO (importado sem lote/guia casando) não é reimportável — não existe "substituir retorno"; duplicatas
JÁ gravadas antes da correção não são curadas (consulta para achá-las abaixo). Não transforme `ProcessGlosaReturnService`
em delta por glosa: a chave do upsert tem `return_id` e o item é resolvido pelo 1º código igual (exame bilateral de mesmo
TUSS colapsaria e o total cairia).

Duplicatas legadas (retornos já gravados para os mesmos bytes), para decisão do dono antes de sanear:

```sql
SELECT r.entity_id, r.operator_id, d.file_hash, count(*) AS retornos,
       array_agg(r.id ORDER BY r.received_at, r.id) AS ids,
       array_agg(r.status ORDER BY r.received_at, r.id) AS statuses
FROM tiss_returns r
JOIN tiss_xml_documents d ON d.id = r.xml_document_id
WHERE r.deleted_at IS NULL AND d.file_hash IS NOT NULL
GROUP BY r.entity_id, r.operator_id, d.file_hash
HAVING count(*) > 1
ORDER BY count(*) DESC;
```

Lote cancelado: a geração do XML (`GenerateTissXmlService::generateBatch`) e o envio recusam o lote
cancelado sob a trava (`TissBatchCancelledException`), antes de qualquer escrita. Os jobs `GenerateTissBatchXmlJob`
e `SendTissBatchJob` enfileirados antes do cancelamento tratam isso como "nada a fazer" — sem retentativas, sem
regerar o XML das guias canceladas nem transmitir o que o usuário cancelou.

XML anterior à guarda de schema: o validador antigo gravava `validated` com `metadata.schema=null`. O transporte
real (`HttpTissTransportClient`) só exige o XML validado contra o XSD quando uma requisição vai sair (primeiro envio,
retomada, reenvio forçado) — a guarda roda na reivindicação, depois de decidido que a chamada não devolve uma
transmissão já confirmada/lote já entregue (`ALREADY_SENT`) nem uma tentativa em voo (`IN_FLIGHT`). Para o XML sem
evidência, `SendTissBatchService::revalidateInPlace` valida o MESMO `xml_content` contra o XSD da versão do lote e grava
a evidência no próprio documento (`metadata.schema`, `revalidated_at`, `validated_at`, `validation_errors`) antes de
reenviar: bytes, `file_hash`, id do documento e chave de idempotência não mudam, então a entrega em dúvida de um XML
antigo retoma com a MESMA chave. XML que o XSD rejeita (ou malformado) continua barrado, sem alterar o documento; sem
como resolver o XSD (versão sem schema configurado, arquivo ausente) o envio segue bloqueado ("validação efetiva").
Não regere o XML de lote enviando/enviado para recompor a evidência: a chave de idempotência mudaria.

Reenvio forçado (`force=true`) de transmissão já confirmada: com o lote já entregue (`sent`,
`acknowledged`, `processed`, `partially_paid`, `paid`) a confirmação da operadora não é reescrita. A
transmissão segue `acknowledged`; lote, guias e chave (`completed`) ficam como estão; a falha do
reenvio é registrada à parte (`metadata.forced_resend.last_failure` e histórico de status) e o
sucesso só atualiza a resposta, sem rebaixar lote/guias processados ou pagos nem recuar o protocolo.
Lote em `error` por rejeição via retorno continua reenviável à força (sucesso → `sent`, falha → `error`).

Falha definitiva de job: o `failed()` de `SendTissBatchJob` e de `GenerateTissBatchXmlJob` passa por
`RecordTissBatchFailureAction`, que lê o lote com a linha travada (`FOR UPDATE`) na mesma transação
em que grava. Só um lote que ainda não saiu vai a `error`; `sending` só registra o motivo (envio) e
lote enviado/confirmado/processado/pago ou cancelado não é tocado. Sem a trava, uma sessão que
comitasse `sending`/`sent` entre a leitura e a escrita teria o status sobrescrito.

Limitação: o contrato do transporte não traz nenhum sinal de recusa da operadora correlacionado ao
envio ORIGINAL (a resposta dele se perdeu, então não há protocolo para correlacionar), e não existe
conciliação manual (tela/comando) ainda — o lote só sai da dúvida quando a operadora aceita. Quando o
problema está no conteúdo do lote (a operadora recusa o XML), é preciso conciliar com a operadora e
liberar o lote à mão.

Recebimento de XML retorno:

```php
$received = $workflow->receiveResponse(
    entityId: $entityId,
    operatorId: $operatorId,
    xmlContent: $xmlRetorno,
    processSynchronously: false,
);

$received->return;          // TissReturn (novo, retomado ou o já processado)
$received->alreadyImported; // true = arquivo repetido de retorno já processado (nada foi escrito)
```

## Mensagens avulsas do módulo cirúrgico (solicitação de internação e anexo de OPME)

Fase 4 / M3b. Dois tipos de guia (`TissGuideType::InpatientRequest`, `OpmeAttachment`) que NÃO viajam em `loteGuias`
(`ctm_guiaLote` só aceita consulta, SP-SADT, resumo de internação, honorários e odonto): a solicitação de internação é
`prestadorParaOperadora/solicitacaoProcedimento` (`SOLICITACAO_PROCEDIMENTOS`) e o anexo de OPME é `loteAnexos`
(`ENVIO_ANEXO`). Código em `App\Domains\Tiss\Surgical`; a guia nasce do caso cirúrgico (`tiss_guides.surgical_case_id`).

- **Despacho explícito**: o builder de LOTE faz `match` por tipo e recusa (`TissGuideTypeNotBatchableException`) o que não é
  consulta/SP-SADT — antes, tudo que não era consulta saía como SP-SADT sem erro. `AttachGuideToBatchAction` também recusa.
- **Retrato congelado**: `payload.surgical.data` (derivado do caso) + `overrides` (o que a secretária completou: TUSS do material,
  data sugerida, telefone). Atualizar a partir do caso troca `data` e preserva `overrides`; `source_hash` acusa guia desatualizada.
  O builder só lê o retrato, nunca o caso vivo.
- **XML**: `V202603TissSurgicalMessageBuilder`, validado contra o XSD 4.03.00 (mesma evidência `metadata.schema`); XML recusado nunca
  sai. Pré-validação própria (`SurgicalGuideRules`).
- **Envio** (`SendSurgicalGuideService`): UMA transmissão por guia, sem lote e SEM retentativa automática (nenhum job). Idempotência
  pelo hash do XML (escopo `guide_send`). Mesmas garantias do lote: lease de transmissão em voo (a 2ª requisição não chama o
  transporte), entrega em dúvida (timeout/408/5xx = transmissão `timeout`, guia TRAVADA — não edita, não cancela, não regera; o reenvio
  usa o MESMO XML e a MESMA chave mesmo que o caso tenha mudado; só o aceite encerra a dúvida, falha posterior não a desfaz) e erro
  definitivo (4xx / 500 com recusa explícita → guia `error`, editável). Transporte real exige XSD validado.
- **Locks**: guia → transmissão. O caso cirúrgico só é travado na GERAÇÃO (caso → guia); envio e retorno não o seguram — o evento no
  log de autorização é gravado depois, fora das transações da guia.
- **Retorno**: `TissReturnParser::parseAuthorization()` (aditivo) lê os DOIS envelopes do XSD: a solicitação de internação
  (`autorizacaoServicos`/`situacaoAutorizacao`) e o anexo de OPME (`recebimentoAnexo/protocoloRecebimentoAnexo/anexosClinicos/anexoOPME`,
  itens em `servicosAutorizadosOPME`, ou `recebimentoAnexo/mensagemErro`); os outros anexos (quimio, radio, situação inicial) não são
  autorização cirúrgica e o parser devolve null. `SurgicalAuthorizationResponseService` transforma a resposta (síncrona, anexada na aba ou
  importada em `ReceiveTissResponseService::receive`) em evento do caso (`channel = tiss`, `tiss_guide_id`) pelo mesmo
  `SurgeryAuthorizationService::record()` da secretária. Idempotente (resposta conferida por guia + número/validade, seja o evento de
  desfecho ou a anotação da resposta parcial do pedido; arquivo importado por clínica + operadora + SHA-256). Resposta que não cabe no
  estado do caso vira anotação.
- **Escopo da resposta**: a operadora responde por GUIA (internação = procedimentos; anexo de OPME = materiais) e a resposta vale só
  para os itens da guia (`metadata.source_kind`/`source_id`, casados por TUSS na resposta parcial). O caso só passa a autorizado quando
  nenhum item fica sem resposta; antes disso segue "aguardando" — e o anexo de OPME continua podendo ser enviado — e a resposta fica no
  log como anotação (número, validade, itens). Negativa de qualquer guia nega o caso. O número e a senha da autorização da internação
  vão no cabeçalho do anexo de OPME (`SurgicalGuideSource::authorization()`).
- Limites: só o código TUSS do material e o telefone do solicitante precisam ser completados à mão; envio por webservice só com
  transporte `http` configurado (o padrão é `mock`, que responde no envelope certo de cada transação); sem tela de conciliação para
  entrega em dúvida — ela sai com o aceite da operadora.

