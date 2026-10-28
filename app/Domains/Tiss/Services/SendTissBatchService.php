<?php

declare(strict_types=1);

namespace App\Domains\Tiss\Services;

use App\Domains\Tiss\Enums\{TissBatchStatus, TissGuideStatus, TissProtocolStatus, TissTransmissionStatus, TissXmlDirection, TissXmlStatus};
use App\Domains\Tiss\Exceptions\TissBatchCancelledException;
use App\Domains\Tiss\Models\{TissBatch, TissEntityOperatorCredential, TissIdempotencyKey, TissProtocol, TissTransmission, TissXmlDocument};
use App\Domains\Tiss\Services\Transport\{HttpTissTransportClient, TissTransportContract};
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\{Carbon, Str};
use Illuminate\Support\Facades\{DB, Storage};
use RuntimeException;

class SendTissBatchService
{
    /** Chamar o transporte de verdade (tentativa nova, ou retomada de uma interrompida). */
    private const RESOLUTION_SEND = 'send';

    /** Já confirmada pela operadora e sem pedido de reenvio — devolve a linha existente. */
    private const RESOLUTION_ALREADY_SENT = 'already_sent';

    /** Outra tentativa ainda detém o lease (em voo de verdade) — não duplica o envio. */
    private const RESOLUTION_IN_FLIGHT = 'in_flight';

    /** O XML escolhido deixou de ser o do lote (outra requisição o regerou): refazer a escolha. */
    private const RESOLUTION_STALE_DOCUMENT = 'stale_document';

    /**
     * Reenvio FORÇADO de uma transmissão já CONFIRMADA, com o lote já entregue
     * (enviado, confirmado, processado, pago...): chama o transporte, mas a
     * confirmação, o status do lote, as guias e a chave não são tocados — só o
     * resultado do reenvio é registrado (claimConfirmedResend()).
     */
    private const RESOLUTION_RESEND_CONFIRMED = 'resend_confirmed';

    /** Lote que a operadora já CONFIRMOU ter recebido: de "enviado" a "pago". */
    private const DELIVERED_BATCH_STATUSES = [
        TissBatchStatus::Sent,
        TissBatchStatus::Acknowledged,
        TissBatchStatus::Processed,
        TissBatchStatus::PartiallyPaid,
        TissBatchStatus::Paid,
    ];

    /** Quantas vezes send() refaz a escolha do XML quando ele é trocado no meio do caminho. */
    private const DOCUMENT_SELECTION_ATTEMPTS = 3;

    /**
     * Status HTTP que NÃO dizem se a operadora processou o pedido: 408 (o
     * servidor desistiu de esperar), 500 (o servidor falhou ENQUANTO tratava o
     * pedido — pode ter gravado o lote e falhado ao montar a resposta) e os de
     * gateway/proxy — 502, 503 e 504, em que quem respondeu foi o intermediário e
     * o servidor da operadora pode ter recebido e processado o lote. O 500 só
     * deixa de valer como dúvida quando o corpo traz uma RECUSA EXPLÍCITA do
     * protocolo (a operadora disse "rejeitado": attemptGotNoResponse()). Qualquer
     * outro status HTTP (4xx) é a RESPOSTA da operadora: definitiva para ESTA
     * tentativa — mas, se uma tentativa anterior ficou em dúvida, nenhuma
     * resposta de erro a desfaz (finalizeTransmission()).
     */
    private const UNCERTAIN_HTTP_STATUSES = [408, 500, 502, 503, 504];

    public function __construct(
        private readonly GenerateTissXmlService $generateTissXmlService,
        private readonly ValidateTissXmlService $validateTissXmlService,
        private readonly LogTissStatusTransitionService $logStatusTransitionService,
        private readonly TissTransportContract $transportClient,
    ) {
    }

    public function send(TissBatch $batch, bool $force = false, bool $throwOnError = true): TissTransmission
    {
        $attempt = 0;

        // Escolher o XML do lote e reivindicar a transmissão são dois passos, e
        // entre eles outra requisição pode regerar o XML (um documento ainda sem
        // transmissão não é reaproveitado pela geração). A reivindicação confere,
        // com a linha do lote travada, se o XML escolhido ainda é o do lote; se
        // não é, adota o atual e refaz a escolha daqui — sem isso, esta
        // requisição enviava o XML A enquanto a outra enviava o B, com chaves de
        // idempotência diferentes (horário no cabeçalho): a operadora recebia o
        // lote duas vezes e o lote ficava apontando para B (achado via revisão
        // adversarial Codex).
        while (true) {
            $attempt++;

            $xmlDocument = $this->selectXmlDocument($batch);

            $credential = $this->resolveCredential($batch);

            if (! $credential) {
                throw new RuntimeException('Credencial ativa da operadora não encontrada para envio TISS.');
            }

            $idempotencyKey = $this->buildIdempotencyKey($batch, $xmlDocument);
            $idempotency    = $this->reserveIdempotency($batch, $idempotencyKey, $xmlDocument, $force);

            if (! $force && $idempotency->status === 'completed') {
                $alreadySent = TissTransmission::query()
                    ->forEntity((string) $batch->entity_id)
                    ->where('idempotency_key', $idempotencyKey)
                    ->orderByDesc('created_at')
                    ->first();

                if ($alreadySent) {
                    return $alreadySent;
                }
            }

            [$transmission, $resolution, $priorDeliveryUnresolved] = $this->resolveTransmissionForSend($batch, $xmlDocument, $idempotencyKey, $force);

            if ($resolution !== self::RESOLUTION_STALE_DOCUMENT) {
                break;
            }

            if ($attempt >= self::DOCUMENT_SELECTION_ATTEMPTS) {
                throw new RuntimeException(__('tiss_send.xml_changed_during_send'));
            }

            // Adota o XML atual: claimTransmission() já releu o lote (e seus
            // relacionamentos) do banco sob a trava; a próxima volta escolhe a partir dele.
        }

        // Outra tentativa ainda detém o lease: devolver a linha em silêncio
        // fazia o job da fila terminar "com sucesso" sem enviar nada e parar
        // de tentar. Com throwOnError, falha esta tentativa — a fila tenta de
        // novo depois (e o lease expira se a outra tentativa tiver morrido).
        if ($resolution === self::RESOLUTION_IN_FLIGHT && $throwOnError) {
            throw new RuntimeException(__('tiss_send.transmission_in_progress'));
        }

        if (! in_array($resolution, [self::RESOLUTION_SEND, self::RESOLUTION_RESEND_CONFIRMED], true)) {
            return $transmission;
        }

        $forcedResend = $resolution === self::RESOLUTION_RESEND_CONFIRMED;

        $result = $this->transportClient->sendBatch(
            credential: $credential,
            xml: (string) ($xmlDocument->xml_content ?? ''),
            idempotencyKey: $idempotencyKey,
            context: [
                'batch_id'     => (string) $batch->id,
                'batch_number' => (string) $batch->batch_number,
            ],
        );

        $finalTransmission = $forcedResend
            ? $this->finalizeConfirmedResend($batch, $transmission, $result, $idempotencyKey)
            : $this->finalizeTransmission($batch, $transmission, $result, $idempotencyKey, $priorDeliveryUnresolved);

        // Confirmação preservada: reenvio forçado de lote entregue OU lote que
        // outra sessão (um retorno processado) deixou entregue enquanto esta
        // requisição estava em voo — finalizeTransmission() relê o lote sob a trava,
        // então $batch já está atual aqui.
        $confirmationKept = $forcedResend || in_array($batch->status, self::DELIVERED_BATCH_STATUSES, true);

        if (($result['ok'] ?? false) !== true && $throwOnError) {
            $reason = (string) ($result['error_message'] ?? 'Falha ao enviar lote TISS.');

            // Entrega em dúvida: diz ao usuário o que isso significa (lote
            // travado, reenvio com a mesma chave) em vez de só repassar o erro.
            // Esta tentativa pode ter ficado sem resposta (delivery_unknown) ou
            // ter falhado de um jeito que, sozinho, seria definitivo — 401,
            // endpoint ausente — mas que não resolve a dúvida de uma tentativa
            // anterior (delivery_still_unknown).
            throw new RuntimeException(match (true) {
                $confirmationKept                                              => __('tiss_send.forced_resend_failed', ['reason' => $reason]),
                $finalTransmission->status !== TissTransmissionStatus::Timeout => $reason,
                $this->attemptGotNoResponse($result)                           => __('tiss_send.delivery_unknown', ['reason' => $reason]),
                default                                                        => __('tiss_send.delivery_still_unknown', ['reason' => $reason]),
            });
        }

        return $finalTransmission;
    }

    /**
     * O XML que este envio vai transmitir: o do lote, gerado se ainda não há um
     * válido. As garantias de validação do transporte real ficam em
     * assertDeliverable(), que a reivindicação chama quando uma requisição vai
     * de fato sair.
     */
    private function selectXmlDocument(TissBatch $batch): TissXmlDocument
    {
        $batch->loadMissing([
            'operator',
            'contract.credential',
            'xmlDocument',
            'guides',
        ]);

        if (! $batch->xmlDocument || ! in_array($batch->xmlDocument->status, [TissXmlStatus::Validated, TissXmlStatus::Signed], true)) {
            $this->generateTissXmlService->generateBatch($batch);
            $batch->refresh()->loadMissing(['operator', 'contract.credential', 'xmlDocument', 'guides']);
        }

        $xmlDocument = $batch->xmlDocument;

        if (! $xmlDocument) {
            throw new RuntimeException('XML do lote não foi encontrado após geração.');
        }

        return $xmlDocument;
    }

    /**
     * Transporte REAL: antes de uma operadora de verdade receber, o XML precisa
     * estar VALIDADO (ou assinado) e a validação ter sido efetiva. Transporte
     * mock (padrão do projeto) simula o envio sem essas garantias — fixtures e
     * demos com dados mínimos geram XML que o XSD rejeita e seguem fluindo; o
     * real, nunca.
     *
     * @throws RuntimeException XML rejeitado, ou sem como provar a validação contra o XSD
     */
    private function assertDeliverable(TissBatch $batch, TissXmlDocument $xmlDocument): void
    {
        if (! $this->transportClient instanceof HttpTissTransportClient) {
            return;
        }

        // generateBatch() persiste o documento como Invalid — com
        // metadata.schema preenchido (o caminho do XSD que o REJEITOU) —
        // quando a validação falha, e o guard de schema logo abaixo só
        // confere se existe um caminho, não se a validação passou: um XML
        // rejeitado pelo XSD seguia pro transporte real. A checagem de
        // status em selectXmlDocument() só decide se REGERA o XML, nunca
        // reconferia o resultado da regeneração (achado via revisão
        // adversarial Codex).
        if (! in_array($xmlDocument->status, [TissXmlStatus::Validated, TissXmlStatus::Signed], true)) {
            throw new RuntimeException($this->xmlRejectedMessage($xmlDocument));
        }

        // Sem schema, "validado" podia significar só "bem formado" (schema não
        // encontrado, require_schema_validation=false) e nada garantia
        // conformidade (achado via auditoria de encerramento, EE-07).
        if ($xmlDocument->metadata['schema'] ?? null) {
            return;
        }

        $this->revalidateInPlace($batch, $xmlDocument);
    }

    /**
     * XML anterior à guarda de schema: o validador antigo gravava Validated com
     * metadata.schema=null quando não achava o XSD. Lote enviando/enviado não
     * regera o XML (GenerateTissXmlService devolve o que saiu), então recusar
     * esse documento trancava para sempre a retomada de uma entrega em dúvida
     * (achado via revisão adversarial Codex, 24ª rodada). Aqui o MESMO
     * xml_content é validado contra o XSD resolvido e a evidência é gravada no
     * próprio documento — bytes, file_hash, id e, portanto, a chave de
     * idempotência ficam intactos. XML que o XSD rejeita continua barrado, sem
     * alterar o documento.
     */
    private function revalidateInPlace(TissBatch $batch, TissXmlDocument $xmlDocument): void
    {
        $validation = $this->validateTissXmlService->validate((string) $xmlDocument->xml_content, $batch->version?->code);

        if (! ($validation['valid'] ?? false)) {
            throw new RuntimeException($this->rejectedMessage($validation['errors'] ?? []));
        }

        if (($validation['schema'] ?? null) === null) {
            throw new RuntimeException(
                'Envio bloqueado: o XML deste lote não foi validado contra o schema XSD oficial ' .
                '(schema ausente ou require_schema_validation desligado). Transporte real exige validação efetiva.',
            );
        }

        $xmlDocument->update([
            'validated_at'      => now(),
            'validation_errors' => array_values(array_merge($validation['errors'] ?? [], $validation['warnings'] ?? [])),
            'metadata'          => array_merge((array) ($xmlDocument->metadata ?? []), [
                'schema'         => $validation['schema'],
                'revalidated_at' => now()->toIso8601String(),
            ]),
        ]);
    }

    /**
     * Resolve a linha de TissTransmission a usar para esta tentativa de
     * envio, decidindo se o transporte deve ser chamado de verdade.
     *
     * O schema (tiss_transmissions_entity_idempotency_unique + a coluna
     * `attempt`) já previa UMA linha por chave de idempotência, reutilizada
     * entre tentativas — mas o código criava uma linha NOVA a cada chamada,
     * o que só funcionava na 1ª tentativa. Num reenvio (retry da fila após
     * falha, ou force=true manual) a 2ª tentativa colidia com a linha da 1ª
     * (já FALHADA) na unique constraint; o catch dessa colisão "adotava" a
     * linha existente e retornava sem nunca chamar o transporte de novo e
     * sem lançar erro — a fila via a tentativa como concluída com sucesso e
     * parava de tentar, deixando o lote preso em erro pra sempre, mesmo com
     * force=true (achado via revisão adversarial Codex).
     *
     * A reivindicação (conferir o XML, achar ou criar a linha, marcar o lote
     * "enviando") é UMA transação que começa travando a linha do lote — a
     * MESMA trava da geração do XML — e só então relê qual é o XML do lote: se
     * não é mais o escolhido (outra requisição regerou), nada é gravado e
     * devolve RESOLUTION_STALE_DOCUMENT para send() adotar o atual; lote
     * cancelado nesse intervalo não é transmitido. A transação comita antes do
     * I/O de rede (send() chama o transporte depois). Antes, a escolha do XML e
     * a criação da transmissão não tinham trava em comum: dois envios do mesmo
     * lote com XMLs diferentes (horário no cabeçalho = chave diferente)
     * passavam um pelo outro e a operadora recebia duas mensagens (achado via
     * revisão adversarial Codex).
     *
     * @return array{0: TissTransmission|null, 1: string, 2: bool} Transmissão a usar (null se o XML escolhido ficou velho), o que fazer com ela (RESOLUTION_*) e se a entrega de uma tentativa ANTERIOR desta mesma transmissão continua em dúvida (ver reuseExistingTransmission()).
     */
    private function resolveTransmissionForSend(
        TissBatch $batch,
        TissXmlDocument $xmlDocument,
        string $idempotencyKey,
        bool $force,
    ): array {
        try {
            return $this->claimTransmission($batch, $xmlDocument, $idempotencyKey, $force, createIfMissing: true);
        } catch (UniqueConstraintViolationException) {
            // Quem não passa pela trava do lote gravou a MESMA chave de
            // idempotência entre a leitura e o INSERT (o envio concorrente do
            // mesmo lote espera na trava e já a enxerga). O INSERT que falhou
            // foi desfeito pelo próprio DB::transaction() antes de relançar a
            // exceção — seguro reler o estado agora, sem criar de novo.
            return $this->claimTransmission($batch, $xmlDocument, $idempotencyKey, $force, createIfMissing: false);
        }
    }

    /**
     * @return array{0: TissTransmission|null, 1: string, 2: bool}
     */
    private function claimTransmission(
        TissBatch $batch,
        TissXmlDocument $xmlDocument,
        string $idempotencyKey,
        bool $force,
        bool $createIfMissing,
    ): array {
        return DB::transaction(function () use ($batch, $xmlDocument, $idempotencyKey, $force, $createIfMissing): array {
            $this->lockBatchRow($batch);
            $batch->refresh();

            if ($batch->status === TissBatchStatus::Cancelled) {
                throw new TissBatchCancelledException(__('tiss_send.batch_cancelled'));
            }

            if ((string) $batch->xml_document_id !== (string) $xmlDocument->id) {
                return [null, self::RESOLUTION_STALE_DOCUMENT, false];
            }

            $resolved = $this->reuseExistingTransmission($batch, $xmlDocument, $idempotencyKey, $force);

            if ($resolved !== null) {
                return $resolved;
            }

            if (! $createIfMissing) {
                throw new RuntimeException('Conflito de idempotência TISS sem transmissão correspondente.');
            }

            // Primeira requisição deste XML: é a hora de exigir a validação.
            $this->assertDeliverable($batch, $xmlDocument);

            return $this->createFreshTransmission($batch, $xmlDocument, $idempotencyKey, attempt: 1);
        });
    }

    /**
     * 1º lock de tudo que escreve o lote TISS (geração do XML, reivindicação e
     * fechamento do envio): a linha do lote, antes da transmissão e das guias —
     * mesma ordem de BillingEditLocks (lote TISS → guias). Reivindicar pela
     * transmissão primeiro e fechar pelo lote primeiro (ou o contrário)
     * cruzaria as trancas de duas tentativas do mesmo lote.
     */
    private function lockBatchRow(TissBatch $batch): void
    {
        TissBatch::query()
            ->forEntity((string) $batch->entity_id)
            ->whereKey($batch->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * Roda DENTRO da transação de claimTransmission() (lote já travado): o
     * DB::transaction() abaixo vira um SAVEPOINT.
     *
     * O 3º item do retorno é a DÚVIDA PENDENTE: a transmissão retomada tem uma
     * tentativa anterior cujo desfecho a clínica não conhece — 'timeout' (sem
     * resposta) ou 'sending' com o lease vencido (worker morto no meio) — e a
     * operadora pode ter recebido o lote. Uma falha desta tentativa que, sozinha,
     * seria definitiva (401, endpoint ausente, erro devolvido) NÃO desfaz essa
     * dúvida: nada disso diz se o 1º POST chegou. Só o aceite a encerra
     * (finalizeTransmission()). Transmissão 'failed' (resposta de erro da
     * operadora) e 'acknowledged' reenviada à força não carregam dúvida.
     *
     * @return array{0: TissTransmission, 1: string, 2: bool}|null null quando ainda não existe nenhuma transmissão para esta chave.
     */
    private function reuseExistingTransmission(TissBatch $batch, TissXmlDocument $xmlDocument, string $idempotencyKey, bool $force): ?array
    {
        return DB::transaction(function () use ($batch, $xmlDocument, $idempotencyKey, $force): ?array {
            $existing = TissTransmission::query()
                ->forEntity((string) $batch->entity_id)
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing === null) {
                return null;
            }

            // 'sending' = lease de propriedade (requested_at + lease_seconds).
            // Lease vigente: outra tentativa pode estar em voo de verdade —
            // nunca duplica envio pra operadora, nem com force. Lease
            // EXPIRADO: a tentativa morreu sem gravar resposta (worker
            // morto, falha ao gravar o retorno) — antes ficava 'sending' pra
            // sempre e todo reenvio, até force=true, "terminava com sucesso"
            // sem enviar nada (achado via revisão adversarial Codex). Aqui a
            // retomada segue o caminho normal de reenvio abaixo: MESMA linha,
            // MESMA chave de idempotência (a operadora que honra a chave
            // deduplica, se aquela tentativa chegou a entregar).
            if ($existing->status === TissTransmissionStatus::Sending) {
                if (! $this->leaseExpired($existing->requested_at)) {
                    return [$existing, self::RESOLUTION_IN_FLIGHT, false];
                }

                $this->logStatusTransitionService->record(
                    entityId: (string) $batch->entity_id,
                    contextType: 'transmission',
                    contextId: (string) $existing->id,
                    currentStatus: TissTransmissionStatus::Timeout->value,
                    previousStatus: TissTransmissionStatus::Sending->value,
                    reason: 'Tentativa anterior interrompida sem resposta registrada (prazo de segurança expirado) — retomando o envio.',
                    payload: [
                        'batch_id'     => (string) $batch->id,
                        'attempt'      => $existing->attempt,
                        'requested_at' => $existing->requested_at?->toIso8601String(),
                    ],
                );
            }

            // Lote já ENTREGUE: a operadora confirmou — por HTTP ou por um retorno
            // processado (que pode ter chegado mesmo com a resposta do envio
            // perdida). É um fato que um reenvio não pode apagar: sem pedido
            // explícito não há nada a reenviar, e o reenvio FORÇADO só registra o
            // resultado, sem rebaixar lote nem guias (achados via revisão
            // adversarial Codex, 18ª e 22ª rodadas).
            $batchDelivered = in_array($batch->status, self::DELIVERED_BATCH_STATUSES, true);

            // Já confirmada pela operadora e sem pedido explícito de reenvio.
            if (! $force && ($existing->status === TissTransmissionStatus::Acknowledged || $batchDelivered)) {
                if ($batchDelivered && $existing->status === TissTransmissionStatus::Sending) {
                    $this->settleInterruptedAttempt($batch, $existing);
                }

                return [$existing, self::RESOLUTION_ALREADY_SENT, false];
            }

            // Daqui em diante SAI uma requisição (retomada, reenvio ou reenvio
            // forçado). Exigir a validação do XML só aqui, e não antes de decidir,
            // deixa de fora tudo o que devolve uma transmissão sem transmitir: a
            // guarda de schema (transporte real) travava a retomada de uma entrega
            // em dúvida e o "tentar de novo" de um envio já concluído quando o XML
            // era anterior a ela (achado via revisão adversarial Codex, 24ª
            // rodada). Lança dentro da transação: o rollback não deixa nada
            // reivindicado.
            $this->assertDeliverable($batch, $xmlDocument);

            if ($batchDelivered) {
                return $this->claimConfirmedResend($batch, $existing);
            }

            // Antes de a linha ser reescrita abaixo: 'sending' só chega aqui com
            // o lease vencido (o vigente devolveu IN_FLIGHT acima).
            $priorDeliveryUnresolved = in_array($existing->status, [TissTransmissionStatus::Timeout, TissTransmissionStatus::Sending], true);

            // Reenvio forçado de uma confirmada cujo lote NÃO está entregue (a
            // operadora o rejeitou por retorno: lote em erro): a reserva da chave
            // não rebaixa mais 'completed' sozinha, então a marca de "reenvio" é
            // posta aqui — se o worker morrer no meio, o envio normal seguinte
            // retoma a linha em vez de devolvê-la como "já enviada".
            if ($force && $existing->status === TissTransmissionStatus::Acknowledged) {
                TissIdempotencyKey::query()
                    ->forEntity((string) $batch->entity_id)
                    ->where('scope', 'batch_send')
                    ->where('key', $idempotencyKey)
                    ->update(['status' => 'forced']);
            }

            // Falhou antes (Timeout/Failed), ou pede reenvio forçado de algo
            // já confirmado: reaproveita a MESMA linha (bate com o contador
            // `attempt` que o schema já previa) em vez de tentar criar outra.
            $previousBatchStatus = $batch->status->value;

            $batch->update([
                'status'     => TissBatchStatus::Sending->value,
                'last_error' => null,
            ]);

            $this->logStatusTransitionService->record(
                entityId: (string) $batch->entity_id,
                contextType: 'batch',
                contextId: (string) $batch->id,
                currentStatus: TissBatchStatus::Sending->value,
                previousStatus: $previousBatchStatus,
                reason: 'Lote em reenvio para operadora.',
            );

            $existing->update([
                'status'           => TissTransmissionStatus::Sending->value,
                'attempt'          => $existing->attempt + 1,
                'requested_at'     => now(),
                'responded_at'     => null,
                'http_status'      => null,
                'response_time_ms' => null,
                'error_code'       => null,
                'error_message'    => null,
            ]);

            $this->logStatusTransitionService->record(
                entityId: (string) $batch->entity_id,
                contextType: 'transmission',
                contextId: (string) $existing->id,
                currentStatus: TissTransmissionStatus::Sending->value,
                reason: 'Transmissão reenviada (tentativa ' . $existing->attempt . ').',
                payload: ['batch_id' => (string) $batch->id],
            );

            return [$existing->fresh(), self::RESOLUTION_SEND, $priorDeliveryUnresolved];
        });
    }

    /**
     * Reenvio FORÇADO de uma transmissão já CONFIRMADA, com o lote já entregue:
     * NADA da confirmação é reescrito aqui — nem o status da transmissão, nem o
     * lote (que pode já estar processado ou pago), nem as guias, nem a chave
     * (que continua 'completed'). Só o contador de tentativas sobe e um marcador
     * de "em voo" entra no metadata: é o lease deste reenvio (a transmissão não
     * pode virar 'sending' sem perder a confirmação). Roda DENTRO da transação
     * de claimTransmission(), com o lote travado.
     *
     * Antes o reenvio reescrevia a linha e o lote como "enviando"; se falhasse, a
     * transmissão virava "falhou", o lote "erro" (editável) e a chave "failed" —
     * mudar o dado e gerar o XML de novo saía com chave nova e a operadora que
     * já tinha o lote o receberia outra vez (achado via revisão adversarial
     * Codex, 18ª rodada).
     *
     * @return array{0: TissTransmission, 1: string, 2: bool}
     */
    private function claimConfirmedResend(TissBatch $batch, TissTransmission $existing): array
    {
        $metadata  = (array) ($existing->metadata ?? []);
        $startedAt = $metadata['forced_resend']['started_at'] ?? null;

        // Outro reenvio forçado ainda em voo (dentro do lease): não duplica. Se
        // o worker dele morreu, o marcador vence e este segue.
        if (is_string($startedAt) && ! $this->leaseExpired(Carbon::parse($startedAt))) {
            return [$existing, self::RESOLUTION_IN_FLIGHT, false];
        }

        $attempt = $existing->attempt + 1;

        $metadata['forced_resend'] = array_merge((array) ($metadata['forced_resend'] ?? []), [
            'started_at' => now()->toIso8601String(),
            'attempt'    => $attempt,
        ]);

        $existing->update([
            'attempt'  => $attempt,
            'metadata' => $metadata,
        ]);

        $this->logStatusTransitionService->record(
            entityId: (string) $batch->entity_id,
            contextType: 'transmission',
            contextId: (string) $existing->id,
            currentStatus: TissTransmissionStatus::Acknowledged->value,
            previousStatus: TissTransmissionStatus::Acknowledged->value,
            reason: 'Reenvio forçado de transmissão já confirmada (tentativa ' . $attempt . '): a confirmação e o status do lote ficam preservados.',
            payload: ['batch_id' => (string) $batch->id, 'batch_status' => $batch->status->value],
        );

        return [$existing->fresh(), self::RESOLUTION_RESEND_CONFIRMED, false];
    }

    /**
     * Tentativa interrompida (linha 'sending' com o lease vencido) cujo lote um
     * retorno processado já deixou ENTREGUE: não há o que retomar. A linha sai de
     * 'sending' (que não deve ficar eternamente nem repetir o aviso de
     * "interrompida" a cada chamada): confirmada se já tinha protocolo, senão
     * 'timeout' — o desfecho do HTTP daquela tentativa é desconhecido.
     */
    private function settleInterruptedAttempt(TissBatch $batch, TissTransmission $existing): void
    {
        $settled = $existing->protocol_id !== null ? TissTransmissionStatus::Acknowledged : TissTransmissionStatus::Timeout;

        $existing->update(['status' => $settled->value]);

        $this->logStatusTransitionService->record(
            entityId: (string) $batch->entity_id,
            contextType: 'transmission',
            contextId: (string) $existing->id,
            currentStatus: $settled->value,
            previousStatus: TissTransmissionStatus::Sending->value,
            reason: 'Tentativa interrompida encerrada: o lote já foi entregue (confirmado pela operadora).',
            payload: ['batch_id' => (string) $batch->id, 'batch_status' => $batch->status->value],
        );
    }

    private function leaseExpired(?CarbonInterface $startedAt): bool
    {
        $leaseSeconds = max(1, (int) config('tiss.transport.lease_seconds', 180));

        return $startedAt === null
            || $startedAt->lte(now()->subSeconds($leaseSeconds));
    }

    private function xmlRejectedMessage(TissXmlDocument $xmlDocument): string
    {
        return $this->rejectedMessage((array) ($xmlDocument->validation_errors ?? []));
    }

    /**
     * @param array<int, mixed> $errors
     */
    private function rejectedMessage(array $errors): string
    {
        $errors = collect($errors)
            ->filter(fn ($error) => is_string($error) && $error !== '')
            ->take(3)
            ->implode(' | ');

        return $errors === ''
            ? __('tiss_send.xml_rejected')
            : __('tiss_send.xml_rejected_with_errors', ['errors' => Str::limit($errors, 500)]);
    }

    /**
     * @return array{0: TissTransmission, 1: string, 2: bool}
     */
    private function createFreshTransmission(
        TissBatch $batch,
        TissXmlDocument $xmlDocument,
        string $idempotencyKey,
        int $attempt,
    ): array {
        $previousBatchStatus = $batch->status->value;

        $batch->update([
            'status'     => TissBatchStatus::Sending->value,
            'last_error' => null,
        ]);

        $this->logStatusTransitionService->record(
            entityId: (string) $batch->entity_id,
            contextType: 'batch',
            contextId: (string) $batch->id,
            currentStatus: TissBatchStatus::Sending->value,
            previousStatus: $previousBatchStatus,
            reason: 'Lote em envio para operadora.',
        );

        $transmission = TissTransmission::query()->create([
            'entity_id'       => $batch->entity_id,
            'operator_id'     => $batch->operator_id,
            'batch_id'        => $batch->id,
            'xml_document_id' => $xmlDocument->id,
            'idempotency_key' => $idempotencyKey,
            'status'          => TissTransmissionStatus::Sending->value,
            'queue'           => config('tiss.queues.send', 'tiss-send'),
            'attempt'         => $attempt,
            'max_attempts'    => (int) config('tiss.queues.max_attempts.send', 5),
            'requested_at'    => now(),
        ]);

        $this->logStatusTransitionService->record(
            entityId: (string) $batch->entity_id,
            contextType: 'transmission',
            contextId: (string) $transmission->id,
            currentStatus: TissTransmissionStatus::Sending->value,
            reason: 'Transmissão iniciada.',
            payload: ['batch_id' => (string) $batch->id],
        );

        return [$transmission, self::RESOLUTION_SEND, false];
    }

    /**
     * @param array<string, mixed> $result
     * @param bool                 $priorDeliveryUnresolved uma tentativa ANTERIOR desta transmissão ficou sem desfecho conhecido (ver reuseExistingTransmission())
     */
    private function finalizeTransmission(
        TissBatch $batch,
        TissTransmission $transmission,
        array $result,
        string $idempotencyKey,
        bool $priorDeliveryUnresolved,
    ): TissTransmission {
        return DB::transaction(function () use ($batch, $transmission, $result, $idempotencyKey, $priorDeliveryUnresolved): TissTransmission {
            // Mesma 1ª trava da reivindicação e da geração do XML (lote → transmissão).
            $this->lockBatchRow($batch);

            // A requisição ao transporte NÃO segura trava: enquanto esteve em voo,
            // outra sessão pode ter comitado — sobretudo o processamento de um
            // retorno da operadora, que lê o lote em 'enviando' e o leva a
            // processado/parcialmente pago (guias pagas). Decidir pelo retrato
            // que esta tentativa guardou sobrescrevia esse desfecho: a falha
            // devolvia o lote a "erro" (editável, com XML e chave novos na
            // próxima geração) e o sucesso o rebaixava, e às guias, para "enviado"
            // (achado via revisão adversarial Codex, 22ª rodada). Relê sob a trava.
            $batch->refresh();

            // Lote entregue durante o voo (esta tentativa o pôs em 'enviando'): a
            // confirmação da operadora vale mais que o resultado deste reenvio.
            if (in_array($batch->status, self::DELIVERED_BATCH_STATUSES, true)) {
                return $this->finalizeConfirmedResend($batch, $transmission, $result, $idempotencyKey);
            }

            $ok             = (bool) ($result['ok'] ?? false);
            $responseBody   = is_string($result['response_body'] ?? null) ? $result['response_body'] : null;
            $protocolNumber = is_string($result['protocol_number'] ?? null) ? $result['protocol_number'] : null;
            $protocolStatus = is_string($result['protocol_status'] ?? null) ? $result['protocol_status'] : null;

            // Entrega EM DÚVIDA: esta tentativa ficou sem resposta, OU uma anterior
            // ficou e esta não a resolveu (só o aceite resolve). Ver o comentário
            // do ramo de falha abaixo.
            $noResponse = ! $ok && $this->attemptGotNoResponse($result);
            $uncertain  = ! $ok && ($noResponse || $priorDeliveryUnresolved);

            $transmissionStatus = match (true) {
                $ok        => TissTransmissionStatus::Acknowledged,
                $uncertain => TissTransmissionStatus::Timeout,
                default    => TissTransmissionStatus::Failed,
            };

            $transmission->update([
                'status'           => $transmissionStatus->value,
                'responded_at'     => now(),
                'http_status'      => $result['status_code'] ?? null,
                'response_time_ms' => $result['response_time_ms'] ?? null,
                'error_code'       => $result['error_code'] ?? null,
                'error_message'    => $result['error_message'] ?? null,
                'response_payload' => $responseBody,
                'metadata'         => [
                    'protocol_status' => $protocolStatus,
                ],
            ]);

            $this->logStatusTransitionService->record(
                entityId: (string) $batch->entity_id,
                contextType: 'transmission',
                contextId: (string) $transmission->id,
                currentStatus: $transmissionStatus->value,
                previousStatus: TissTransmissionStatus::Sending->value,
                reason: match (true) {
                    $ok         => 'Transmissão confirmada pela operadora.',
                    $noResponse => 'Sem resposta da operadora — entrega em dúvida.',
                    $uncertain  => 'Falha na tentativa, mas o envio anterior segue em dúvida (a falha não prova que a operadora não recebeu o lote).',
                    default     => 'Falha na transmissão.',
                },
            );

            if ($ok) {
                $protocol = $this->persistAcknowledgementProtocol($batch, $transmission, $responseBody, $protocolNumber, $protocolStatus);

                $batch->update([
                    'status'       => TissBatchStatus::Sent->value,
                    'submitted_at' => now(),
                    'last_error'   => null,
                ]);

                $batch->guides()->update([
                    'status' => TissGuideStatus::Sent->value,
                ]);

                $this->logStatusTransitionService->record(
                    entityId: (string) $batch->entity_id,
                    contextType: 'batch',
                    contextId: (string) $batch->id,
                    currentStatus: TissBatchStatus::Sent->value,
                    previousStatus: TissBatchStatus::Sending->value,
                    reason: 'Lote enviado e recebido pela operadora.',
                    payload: ['protocol_id' => (string) $protocol->id],
                );

                TissIdempotencyKey::query()
                    ->forEntity((string) $batch->entity_id)
                    ->where('scope', 'batch_send')
                    ->where('key', $idempotencyKey)
                    ->update([
                        'status'            => 'completed',
                        'last_seen_at'      => now(),
                        'response_snapshot' => [
                            'transmission_id' => (string) $transmission->id,
                            'protocol_number' => $protocol->protocol_number,
                        ],
                    ]);
            } else {
                // Sem resposta da operadora (Timeout): ela PODE ter recebido e
                // processado o lote — a entrega fica EM DÚVIDA. "Em dúvida" é o
                // mesmo estado de um envio em andamento: o lote continua
                // "enviando" (travado — não aceita guia nova nem retirada, não
                // cancela, e GenerateTissXmlService devolve o XML enviado em vez
                // de regerar) e o reenvio retoma ESTA transmissão, com a MESMA
                // chave. Marcá-lo "erro" (editável) deixava mudar o dado
                // (CNES, guia) e reenviar um XML novo com chave nova: a operadora
                // que tinha aceito o primeiro recebia o lote de novo, sem a
                // deduplicação da chave original (achado via revisão adversarial
                // Codex).
                //
                // A dúvida SOBREVIVE às tentativas seguintes: depois de uma
                // resposta perdida, um reenvio que falha com 401, com erro da
                // operadora ou localmente (endpoint ausente) não diz nada sobre o
                // 1º POST — nem que ele foi recusado nem que não chegou. Só o
                // aceite encerra a dúvida; olhar apenas o desfecho da ÚLTIMA
                // tentativa liberava o lote para edição e a próxima geração saía
                // com XML e chave novos (achado via revisão adversarial Codex,
                // 17ª rodada). Erro DEVOLVIDO pela operadora (HTTP 4xx/500) numa
                // transmissão SEM dúvida anterior é resposta definitiva: "erro".
                $batchStatus = $uncertain ? TissBatchStatus::Sending : TissBatchStatus::Error;

                $batch->update([
                    'status'     => $batchStatus->value,
                    'last_error' => $result['error_message'] ?? 'Falha desconhecida no envio.',
                ]);

                $this->logStatusTransitionService->record(
                    entityId: (string) $batch->entity_id,
                    contextType: 'batch',
                    contextId: (string) $batch->id,
                    currentStatus: $batchStatus->value,
                    previousStatus: TissBatchStatus::Sending->value,
                    reason: match (true) {
                        $noResponse => 'Sem resposta da operadora — entrega em dúvida: lote travado até a próxima tentativa (mesma chave de idempotência).',
                        $uncertain  => 'Tentativa falhou, mas o envio anterior segue em dúvida: lote travado até a operadora aceitar (mesma chave de idempotência).',
                        default     => 'Erro no envio do lote.',
                    },
                    payload: [
                        'transmission_id' => (string) $transmission->id,
                        'error'           => $result['error_message'] ?? null,
                    ],
                );

                TissIdempotencyKey::query()
                    ->forEntity((string) $batch->entity_id)
                    ->where('scope', 'batch_send')
                    ->where('key', $idempotencyKey)
                    ->update([
                        'status'            => $uncertain ? 'uncertain' : 'failed',
                        'last_seen_at'      => now(),
                        'response_snapshot' => [
                            'transmission_id' => (string) $transmission->id,
                            'error'           => $result['error_message'] ?? null,
                        ],
                    ]);
            }

            return $transmission->fresh();
        });
    }

    /**
     * Fechamento de um reenvio forçado de transmissão confirmada (ver
     * claimConfirmedResend()). A confirmação do 1º envio é preservada qualquer
     * que seja o resultado:
     *
     *  - falha (401, erro HTTP, sem resposta, endpoint ausente): transmissão,
     *    lote, guias e chave não mudam; a falha fica no metadata da transmissão
     *    (`forced_resend.last_failure`) e no histórico de status;
     *  - sucesso: a resposta da transmissão é atualizada e o protocolo recebido
     *    é registrado sem recuar um protocolo que já andou (aceito, rejeitado...);
     *    o status do lote e das guias — que podem já ser "processado"/"pago" — e
     *    a data de envio não são tocados.
     *
     * Também fecha a tentativa cujo lote ficou ENTREGUE enquanto a requisição
     * estava em voo (um retorno da operadora processado nesse intervalo —
     * finalizeTransmission()): a transmissão sai de 'sending' (confirmada se
     * houve ou há aceite, senão 'timeout'), a chave fica 'completed' (a operadora
     * tem o lote) e lote e guias seguem como o retorno os deixou.
     *
     * @param array<string, mixed> $result
     */
    private function finalizeConfirmedResend(TissBatch $batch, TissTransmission $transmission, array $result, string $idempotencyKey): TissTransmission
    {
        return DB::transaction(function () use ($batch, $transmission, $result, $idempotencyKey): TissTransmission {
            // Mesma 1ª trava da reivindicação e da geração do XML (lote → transmissão).
            $this->lockBatchRow($batch);

            $ok       = (bool) ($result['ok'] ?? false);
            $metadata = (array) ($transmission->metadata ?? []);
            $forced   = (array) ($metadata['forced_resend'] ?? []);

            unset($forced['started_at']); // o reenvio terminou: sai o marcador de "em voo"

            $previousStatus = $transmission->status;

            if (! $ok) {
                $forced['last_failure'] = [
                    'attempt'       => $transmission->attempt,
                    'at'            => now()->toIso8601String(),
                    'http_status'   => $result['status_code'] ?? null,
                    'error_code'    => $result['error_code'] ?? null,
                    'error_message' => mb_substr((string) ($result['error_message'] ?? ''), 0, 500),
                ];

                // Já confirmada continua confirmada; a tentativa que estava em voo
                // ('sending') sai dela confirmada se houve aceite antes, senão 'timeout'.
                $settled = $previousStatus === TissTransmissionStatus::Acknowledged || $transmission->protocol_id !== null
                    ? TissTransmissionStatus::Acknowledged
                    : TissTransmissionStatus::Timeout;

                $transmission->update([
                    'status'   => $settled->value,
                    'metadata' => array_merge($metadata, ['forced_resend' => $forced]),
                ]);

                $this->markKeyDelivered($batch, $idempotencyKey, $transmission);

                $this->logStatusTransitionService->record(
                    entityId: (string) $batch->entity_id,
                    contextType: 'transmission',
                    contextId: (string) $transmission->id,
                    currentStatus: $settled->value,
                    previousStatus: $previousStatus->value,
                    reason: 'Reenvio falhou — a confirmação da operadora foi preservada.',
                    payload: $forced['last_failure'] + ['batch_id' => (string) $batch->id, 'batch_status' => $batch->status->value],
                );

                return $transmission->fresh();
            }

            $responseBody   = is_string($result['response_body'] ?? null) ? $result['response_body'] : null;
            $protocolNumber = is_string($result['protocol_number'] ?? null) ? $result['protocol_number'] : null;
            $protocolStatus = is_string($result['protocol_status'] ?? null) ? $result['protocol_status'] : null;

            unset($forced['last_failure']);
            $forced['last_success_at'] = now()->toIso8601String();

            $transmission->update([
                'status'           => TissTransmissionStatus::Acknowledged->value,
                'responded_at'     => now(),
                'http_status'      => $result['status_code'] ?? null,
                'response_time_ms' => $result['response_time_ms'] ?? null,
                'error_code'       => null,
                'error_message'    => null,
                'response_payload' => $responseBody,
                'metadata'         => array_merge($metadata, ['protocol_status' => $protocolStatus, 'forced_resend' => $forced]),
            ]);

            $protocol = $this->persistAcknowledgementProtocol($batch, $transmission, $responseBody, $protocolNumber, $protocolStatus, keepAdvancedStatus: true);

            $this->markKeyDelivered($batch, $idempotencyKey, $transmission, $protocol->protocol_number);

            $this->logStatusTransitionService->record(
                entityId: (string) $batch->entity_id,
                contextType: 'transmission',
                contextId: (string) $transmission->id,
                currentStatus: TissTransmissionStatus::Acknowledged->value,
                previousStatus: $previousStatus->value,
                reason: 'Reenvio confirmado pela operadora; status do lote preservado.',
                payload: ['batch_id' => (string) $batch->id, 'protocol_id' => (string) $protocol->id],
            );

            return $transmission->fresh();
        });
    }

    /**
     * Chave 'completed': com o lote ENTREGUE (a operadora o confirmou, por HTTP ou
     * por retorno) a chave deste XML está concluída, qualquer que seja o resultado
     * do reenvio que acabou de fechar. No reenvio forçado de lote já entregue ela
     * já estava assim (a reserva não a reescreve) — aqui só importa quando o lote
     * foi entregue DURANTE o voo.
     */
    private function markKeyDelivered(TissBatch $batch, string $idempotencyKey, TissTransmission $transmission, ?string $protocolNumber = null): void
    {
        $attributes = ['status' => 'completed', 'last_seen_at' => now()];

        if ($protocolNumber !== null) {
            $attributes['response_snapshot'] = [
                'transmission_id' => (string) $transmission->id,
                'protocol_number' => $protocolNumber,
            ];
        }

        TissIdempotencyKey::query()
            ->forEntity((string) $batch->entity_id)
            ->where('scope', 'batch_send')
            ->where('key', $idempotencyKey)
            ->update($attributes);
    }

    private function persistAcknowledgementProtocol(
        TissBatch $batch,
        TissTransmission $transmission,
        ?string $responseBody,
        ?string $protocolNumber,
        ?string $protocolStatus,
        bool $keepAdvancedStatus = false,
    ): TissProtocol {
        $normalizedProtocolNumber = $protocolNumber ?: sprintf('PRT-PENDING-%s', $batch->batch_number);

        $protocol = TissProtocol::query()->firstOrCreate(
            [
                'entity_id'       => $batch->entity_id,
                'operator_id'     => $batch->operator_id,
                'protocol_number' => $normalizedProtocolNumber,
            ],
            [
                'batch_id'        => $batch->id,
                'status'          => TissProtocolStatus::Received->value,
                'acknowledged_at' => now(),
                'details'         => ['source' => 'batch_send'],
            ],
        );

        $xmlDocumentId = null;

        if ($responseBody) {
            $relativePath = sprintf(
                'tiss/%s/responses/%s_%s_ack.xml',
                $batch->entity_id,
                mb_strtolower((string) $batch->batch_number),
                now()->format('YmdHis'),
            );

            Storage::disk()->put($relativePath, $responseBody);

            $xmlDocument = TissXmlDocument::query()->create([
                'entity_id'     => $batch->entity_id,
                'operator_id'   => $batch->operator_id,
                'batch_id'      => $batch->id,
                'version_id'    => $batch->version_id,
                'document_type' => 'protocol_receipt',
                'direction'     => TissXmlDirection::Inbound->value,
                'status'        => TissXmlStatus::Validated->value,
                'file_path'     => $relativePath,
                'file_hash'     => hash('sha256', $responseBody),
                'xml_content'   => $responseBody,
                'generated_at'  => now(),
                'validated_at'  => now(),
                'metadata'      => [
                    'source'          => 'send_batch',
                    'transmission_id' => (string) $transmission->id,
                ],
            ]);

            $xmlDocumentId = $xmlDocument->id;
        }

        $mappedProtocolStatus   = $this->mapProtocolStatus($protocolStatus);
        $previousProtocolStatus = $protocol->status->value;

        // Reenvio forçado: a operadora pode devolver o MESMO protocolo, que já
        // andou depois do aceite (processando, aceito, rejeitado...) por retorno
        // processado — a confirmação do reenvio não o faz voltar a "recebido".
        if ($keepAdvancedStatus && ! $protocol->wasRecentlyCreated && ! in_array($protocol->status, [TissProtocolStatus::Pending, TissProtocolStatus::Received], true)) {
            $mappedProtocolStatus = $protocol->status;
        }

        $protocol->update([
            'batch_id'        => $batch->id,
            'xml_document_id' => $xmlDocumentId ?: $protocol->xml_document_id,
            'status'          => $mappedProtocolStatus->value,
            'acknowledged_at' => $protocol->acknowledged_at ?? now(),
            'details'         => array_merge((array) ($protocol->details ?? []), [
                'transmission_id' => (string) $transmission->id,
                'protocol_status' => $protocolStatus,
            ]),
        ]);

        $transmission->update([
            'protocol_id' => $protocol->id,
        ]);

        $this->logStatusTransitionService->record(
            entityId: (string) $batch->entity_id,
            contextType: 'protocol',
            contextId: (string) $protocol->id,
            currentStatus: $mappedProtocolStatus->value,
            previousStatus: $previousProtocolStatus,
            reason: 'Protocolo retornado pela operadora.',
            payload: [
                'protocol_number' => $protocol->protocol_number,
                'transmission_id' => (string) $transmission->id,
            ],
        );

        return $protocol->fresh();
    }

    private function resolveCredential(TissBatch $batch): ?TissEntityOperatorCredential
    {
        if ($batch->contract?->credential && $batch->contract->credential->active) {
            return $batch->contract->credential;
        }

        $environment = (string) config('tiss.transport.environment', 'production');

        return TissEntityOperatorCredential::query()
            ->forEntity((string) $batch->entity_id)
            ->where('operator_id', $batch->operator_id)
            ->where('environment', $environment)
            ->where('active', true)
            ->orderByDesc('updated_at')
            ->first();
    }

    private function buildIdempotencyKey(TissBatch $batch, TissXmlDocument $xmlDocument): string
    {
        $fingerprint = (string) ($xmlDocument->file_hash ?: hash('sha256', (string) ($xmlDocument->xml_content ?? '')));

        return hash('sha256', implode('|', [
            (string) $batch->entity_id,
            (string) $batch->id,
            $fingerprint,
        ]));
    }

    /**
     * Reserva a chave de idempotência — PRESERVANDO um status 'completed'
     * existente. Antes sobrescrevia pra 'reserved' em toda chamada, mesmo
     * numa chave já concluída, apagando a marca que o caller (send(), linha
     * acima) usa pra devolver a transmissão já enviada em vez de reenviar
     * (achado via auditoria de encerramento, EE-08). force=true é a ÚNICA
     * forma explícita de pedir reenvio mesmo com a chave concluída.
     */
    private function reserveIdempotency(
        TissBatch $batch,
        string $idempotencyKey,
        TissXmlDocument $xmlDocument,
        bool $force,
    ): TissIdempotencyKey {
        $ttlHours    = (int) config('tiss.idempotency.ttl_hours', 48);
        $fingerprint = (string) ($xmlDocument->file_hash ?: hash('sha256', (string) ($xmlDocument->xml_content ?? '')));

        $existing = TissIdempotencyKey::query()
            ->forEntity((string) $batch->entity_id)
            ->where('scope', 'batch_send')
            ->where('key', $idempotencyKey)
            ->first();

        if ($existing && $existing->status === 'completed' && ! $force) {
            return $existing;
        }

        $attributes = [
            'fingerprint'  => $fingerprint,
            'status'       => $force ? 'forced' : 'reserved',
            'last_seen_at' => now(),
            'expires_at'   => now()->addHours($ttlHours),
        ];

        if ($existing) {
            // 'uncertain' (entrega em dúvida) e 'completed' (confirmada) são FATOS
            // sobre a entrega, não reservas: a nova tentativa não os reescreve. A
            // dúvida só sai com o aceite ('completed'); uma falha que não a resolve
            // a mantém (finalizeTransmission()); e a confirmação não é perdida por
            // um reenvio forçado que falha (claimConfirmedResend()) — só o reenvio
            // que de fato reabre a linha confirmada põe 'forced'
            // (reuseExistingTransmission()). Se a tentativa morrer sem gravar
            // resposta, a chave segue como estava.
            if (in_array($existing->status, ['uncertain', 'completed'], true)) {
                unset($attributes['status']);
            }

            $existing->update($attributes);

            return $existing->fresh();
        }

        try {
            return TissIdempotencyKey::query()->create([
                'entity_id' => $batch->entity_id,
                'scope'     => 'batch_send',
                'key'       => $idempotencyKey,
                ...$attributes,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Outra chamada concorrente criou a chave entre o SELECT acima e
            // este INSERT — relê e deixa send() decidir a partir do estado
            // real (pode já estar 'completed' por essa outra chamada).
            return TissIdempotencyKey::query()
                ->forEntity((string) $batch->entity_id)
                ->where('scope', 'batch_send')
                ->where('key', $idempotencyKey)
                ->firstOrFail();
        }
    }

    private function mapProtocolStatus(?string $status): TissProtocolStatus
    {
        return match ($status) {
            'accepted'   => TissProtocolStatus::Accepted,
            'rejected'   => TissProtocolStatus::Rejected,
            'processing' => TissProtocolStatus::Processing,
            default      => TissProtocolStatus::Received,
        };
    }

    /**
     * Esta tentativa, sozinha, ficou SEM resposta conclusiva da operadora?
     * Exceção de transporte (conexão caída no meio, timeout de leitura, resposta
     * perdida: sem status HTTP), 408, erro do servidor (500), erro de gateway
     * (502/503/504) ou timeout: a requisição pode ter chegado e sido processada.
     * Falha que nunca saiu da clínica (endpoint não configurado), erro DEVOLVIDO
     * pela operadora (HTTP 4xx) e o 500 com recusa explícita do protocolo não são
     * "sem resposta" — mas, depois de uma dúvida anterior, também não a resolvem
     * (ver finalizeTransmission()).
     *
     * @param array<string, mixed> $result
     */
    private function attemptGotNoResponse(array $result): bool
    {
        $statusCode = $result['status_code'] ?? null;
        $errorCode  = (string) ($result['error_code'] ?? '');

        if ($errorCode === 'missing_endpoint') {
            return false;
        }

        // HTTP 500 é ambíguo: a operadora pode ter gravado o lote e falhado depois,
        // ao montar a resposta. Só a recusa EXPLÍCITA do protocolo no corpo (o
        // statusProtocolo/situacaoProtocolo que o transporte já extrai) estabelece
        // que ela não aceitou — aí a resposta é um veredito dela, não uma falha
        // (achado via revisão adversarial Codex, 20ª rodada).
        if ($statusCode === 500 && ($result['protocol_status'] ?? null) === 'rejected') {
            return false;
        }

        return $statusCode === null
            || in_array($statusCode, self::UNCERTAIN_HTTP_STATUSES, true)
            || str_contains($errorCode, 'timeout');
    }
}
