<?php

declare(strict_types=1);

namespace App\Domains\Tiss\Surgical;

use App\Domains\Tiss\Enums\{TissGuideStatus, TissProtocolStatus, TissTransmissionStatus, TissXmlDirection, TissXmlStatus};
use App\Domains\Tiss\Models\{TissEntityOperatorCredential, TissGuide, TissIdempotencyKey, TissProtocol, TissTransmission, TissXmlDocument};
use App\Domains\Tiss\Services\{LogTissStatusTransitionService, ValidateTissXmlService};
use App\Domains\Tiss\Services\Transport\{HttpTissTransportClient, TissMessageTransportContract};
use App\Enums\Surgery\{AuthorizationEventType, SurgicalCaseStatus};
use App\Exceptions\Surgery\SurgeryRuleException;
use App\Models\Surgery\SurgicalCase;
use App\Services\Surgery\SurgeryAuthorizationService;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\{DB, Log, Storage};
use Illuminate\Support\Str;
use Throwable;

/**
 * Envio de UMA guia cirúrgica (solicitação de internação / anexo de OPME) à operadora.
 *
 * É o `SendTissBatchService` sem lote, e herda as mesmas garantias — nenhuma foi
 * afrouxada:
 *
 *  - Idempotência: a chave nasce do HASH do arquivo XML (entidade + guia + hash); o
 *    reenvio usa o MESMO XML e a MESMA chave, mesmo que o caso tenha mudado depois.
 *  - UMA transmissão por guia: duas requisições simultâneas passam pela trava da
 *    linha da guia; a 2ª encontra a transmissão da 1ª em voo (lease) e NÃO chama o
 *    transporte. A reivindicação (conferir o XML, achar/criar a transmissão) é uma
 *    transação própria que comita ANTES do I/O de rede.
 *  - ENTREGA EM DÚVIDA: sem resposta conclusiva (exceção de transporte, HTTP 408, 5xx,
 *    timeout) a operadora PODE ter recebido o pedido. A transmissão fica `timeout` e a
 *    guia fica travada (imutável: não edita, não cancela, não regera o XML). A dúvida
 *    só sai com o ACEITE da operadora; falha seguinte (401, erro devolvido, endpoint
 *    ausente) NÃO a desfaz.
 *  - Erro DEFINITIVO (4xx devolvido pela operadora, ou 500 com recusa explícita no
 *    corpo), numa transmissão sem dúvida anterior: transmissão `failed`, guia `error`
 *    (editável de novo).
 *  - SEM retentativa automática: nenhum job. Reenvio só por ação explícita.
 *
 * Ordem de locks (guia avulsa, sem lote): linha da GUIA → transmissão. O caso
 * cirúrgico NUNCA é travado aqui — o evento "enviada" é gravado depois, fora das
 * transações da guia, por SurgeryAuthorizationService::record() (que trava o caso).
 * O processamento do retorno segue a mesma regra (SurgicalAuthorizationResponseService).
 */
class SendSurgicalGuideService
{
    private const RESOLUTION_SEND = 'send';

    private const RESOLUTION_ALREADY_SENT = 'already_sent';

    private const RESOLUTION_IN_FLIGHT = 'in_flight';

    private const RESOLUTION_STALE_DOCUMENT = 'stale_document';

    /** Quantas vezes send() refaz a escolha do XML quando ele é trocado no meio do caminho. */
    private const DOCUMENT_SELECTION_ATTEMPTS = 3;

    /** Idempotência: escopo da chave. */
    private const KEY_SCOPE = 'guide_send';

    /** Status HTTP sem veredito da operadora (mesma lista do lote). */
    private const UNCERTAIN_HTTP_STATUSES = [408, 500, 502, 503, 504];

    public function __construct(
        private readonly SurgicalMessageXmlService $xml,
        private readonly ValidateTissXmlService $validator,
        private readonly LogTissStatusTransitionService $history,
        private readonly TissMessageTransportContract $transport,
        private readonly SurgeryAuthorizationService $authorization,
        private readonly SurgicalAuthorizationResponseService $responses,
    ) {
    }

    /**
     * @throws SurgeryRuleException estado do caso/guia não permite enviar; transmissão já em voo (409)
     */
    public function send(TissGuide $guide): TissTransmission
    {
        $this->assertSendable($guide);

        $attempt = 0;

        while (true) {
            $attempt++;

            $guide->refresh();
            $document = $this->selectDocument($guide);

            // ANTES de reivindicar: sem credencial nada sai, e uma transmissão 'sending'
            // órfã seria lida depois como entrega em dúvida.
            $credential = $this->credential($guide);
            $key        = $this->idempotencyKey($guide, $document);

            $this->reserveKey($guide, $key, $document);

            [$transmission, $resolution, $priorDoubt] = $this->resolve($guide, $document, $key);

            if ($resolution !== self::RESOLUTION_STALE_DOCUMENT) {
                break;
            }

            if ($attempt >= self::DOCUMENT_SELECTION_ATTEMPTS) {
                throw SurgeryRuleException::state('surgery_tiss.errors.xml_changed_during_send');
            }
        }

        if ($resolution === self::RESOLUTION_IN_FLIGHT) {
            throw SurgeryRuleException::state('surgery_tiss.errors.transmission_in_progress');
        }

        if ($resolution === self::RESOLUTION_ALREADY_SENT) {
            return $transmission;
        }

        $result = $this->dispatch($credential, $guide, $document, $key);

        $final = $this->finalize($guide, $transmission, $result, $key, $priorDoubt);

        if (($result['ok'] ?? false) === true) {
            // Fora das transações da guia: grava o evento "enviada" no log do caso e, se a
            // resposta já trouxe a decisão da operadora, aplica.
            $this->afterAcknowledged($guide->refresh(), $final, $result);

            return $final;
        }

        throw $this->failure($final, $result, $priorDoubt);
    }

    // ── Pré-condições ───────────────────────────────────────────────────────

    private function assertSendable(TissGuide $guide): void
    {
        if (! $guide->guide_type->isSurgicalRequest() || $guide->surgical_case_id === null) {
            throw SurgeryRuleException::invalid('surgery_tiss.errors.type_not_supported');
        }

        if (in_array($guide->status, [TissGuideStatus::Cancelled, TissGuideStatus::Denied], true)) {
            throw SurgeryRuleException::state('surgery_tiss.errors.guide_closed');
        }

        $case = SurgicalCase::query()->where('entity_id', $guide->entity_id)->find($guide->surgical_case_id);

        if ($case === null) {
            throw SurgeryRuleException::state('surgery_tiss.errors.guide_without_case');
        }

        // Enviar é o canal do pedido de autorização: o pedido precisa ter sido enviado
        // (caso "aguardando"). A retomada de uma entrega EM DÚVIDA segue valendo mesmo
        // se o caso andou — a operadora pode ter o pedido, e só o aceite encerra a dúvida.
        $doubt = $guide->transmissions()
            ->whereIn('status', [TissTransmissionStatus::Timeout->value, TissTransmissionStatus::Sending->value])
            ->exists();

        if ($case->status !== SurgicalCaseStatus::AwaitingAuthorization && ! $doubt) {
            throw SurgeryRuleException::state('surgery_tiss.errors.case_not_awaiting', ['status' => $case->status->label()]);
        }
    }

    /** O XML que este envio transmite: o atual da guia, gerado se ainda não há um válido. */
    private function selectDocument(TissGuide $guide): TissXmlDocument
    {
        $document = $this->xml->currentDocument($guide);

        if ($document === null || ! in_array($document->status, [TissXmlStatus::Validated, TissXmlStatus::Signed], true)) {
            $document = $this->xml->generate($guide);
            $guide->refresh();
        }

        if (! in_array($document->status, [TissXmlStatus::Validated, TissXmlStatus::Signed], true)) {
            throw SurgeryRuleException::invalid('surgery_tiss.errors.xml_rejected', context: [
                'errors' => array_slice((array) $document->validation_errors, 0, 5),
            ]);
        }

        return $document;
    }

    private function idempotencyKey(TissGuide $guide, TissXmlDocument $document): string
    {
        $fingerprint = (string) ($document->file_hash ?: hash('sha256', (string) $document->xml_content));

        return hash('sha256', implode('|', [(string) $guide->entity_id, (string) $guide->id, $fingerprint]));
    }

    /** Reserva a chave PRESERVANDO 'completed' (aceite) e 'uncertain' (dúvida): são fatos, não reservas. */
    private function reserveKey(TissGuide $guide, string $key, TissXmlDocument $document): void
    {
        $existing = TissIdempotencyKey::query()
            ->forEntity((string) $guide->entity_id)
            ->where('scope', self::KEY_SCOPE)
            ->where('key', $key)
            ->first();

        $attributes = [
            'fingerprint'  => (string) ($document->file_hash ?: hash('sha256', (string) $document->xml_content)),
            'status'       => 'reserved',
            'last_seen_at' => now(),
            'expires_at'   => now()->addHours((int) config('tiss.idempotency.ttl_hours', 48)),
        ];

        if ($existing !== null) {
            if (in_array($existing->status, ['uncertain', 'completed'], true)) {
                unset($attributes['status']);
            }

            $existing->update($attributes);

            return;
        }

        try {
            TissIdempotencyKey::query()->create(['entity_id' => $guide->entity_id, 'scope' => self::KEY_SCOPE, 'key' => $key, ...$attributes]);
        } catch (UniqueConstraintViolationException) {
            // A outra requisição criou a chave entre o SELECT e o INSERT: vale a dela.
        }
    }

    // ── Reivindicação (transação própria, comita antes do I/O) ──────────────

    /**
     * @return array{0: TissTransmission|null, 1: string, 2: bool} transmissão, o que fazer com ela, e se há dúvida PENDENTE de tentativa anterior
     */
    private function resolve(TissGuide $guide, TissXmlDocument $document, string $key): array
    {
        try {
            return $this->claim($guide, $document, $key, createIfMissing: true);
        } catch (UniqueConstraintViolationException) {
            // A transmissão desta chave nasceu entre a leitura e o INSERT: relê (sem criar).
            return $this->claim($guide, $document, $key, createIfMissing: false);
        }
    }

    /**
     * @return array{0: TissTransmission|null, 1: string, 2: bool}
     */
    private function claim(TissGuide $guide, TissXmlDocument $document, string $key, bool $createIfMissing): array
    {
        return DB::transaction(function () use ($guide, $document, $key, $createIfMissing): array {
            $locked = $this->lockGuide($guide);

            if (in_array($locked->status, [TissGuideStatus::Cancelled, TissGuideStatus::Denied], true)) {
                throw SurgeryRuleException::state('surgery_tiss.errors.guide_closed');
            }

            // O XML escolhido deixou de ser o da guia (editada/regerada no intervalo): refaz.
            if ((string) $locked->xml_document_id !== (string) $document->id) {
                return [null, self::RESOLUTION_STALE_DOCUMENT, false];
            }

            $existing = TissTransmission::query()
                ->forEntity((string) $locked->entity_id)
                ->where('idempotency_key', $key)
                ->lockForUpdate()
                ->first();

            if ($existing === null) {
                if (! $createIfMissing) {
                    throw SurgeryRuleException::state('surgery_tiss.errors.transmission_in_progress');
                }

                $this->assertDeliverable($locked, $document);

                return [$this->createTransmission($locked, $document, $key), self::RESOLUTION_SEND, false];
            }

            // 'sending' = lease de propriedade. Vigente: outra requisição está em voo de
            // verdade — nunca duplica o envio. Vencido: a tentativa morreu sem gravar
            // resposta; segue como dúvida (a operadora pode ter recebido).
            if ($existing->status === TissTransmissionStatus::Sending) {
                if (! $this->leaseExpired($existing->requested_at)) {
                    return [$existing, self::RESOLUTION_IN_FLIGHT, false];
                }

                $this->history->record(
                    entityId: (string) $locked->entity_id,
                    contextType: 'transmission',
                    contextId: (string) $existing->id,
                    currentStatus: TissTransmissionStatus::Timeout->value,
                    previousStatus: TissTransmissionStatus::Sending->value,
                    reason: 'Tentativa anterior interrompida sem resposta registrada (prazo de segurança expirado) — retomando o envio.',
                    payload: ['guide_id' => (string) $locked->id, 'attempt' => $existing->attempt],
                );
            }

            if ($existing->status === TissTransmissionStatus::Acknowledged) {
                return [$existing, self::RESOLUTION_ALREADY_SENT, false];
            }

            $this->assertDeliverable($locked, $document);

            $priorDoubt = in_array($existing->status, [TissTransmissionStatus::Timeout, TissTransmissionStatus::Sending], true);

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

            $this->history->record(
                entityId: (string) $locked->entity_id,
                contextType: 'transmission',
                contextId: (string) $existing->id,
                currentStatus: TissTransmissionStatus::Sending->value,
                reason: 'Transmissão reenviada (tentativa ' . $existing->attempt . ').',
                payload: ['guide_id' => (string) $locked->id],
            );

            return [$existing->fresh(), self::RESOLUTION_SEND, $priorDoubt];
        });
    }

    private function createTransmission(TissGuide $locked, TissXmlDocument $document, string $key): TissTransmission
    {
        $transmission = TissTransmission::query()->create([
            'entity_id'       => $locked->entity_id,
            'operator_id'     => $locked->operator_id,
            'guide_id'        => $locked->id,
            'xml_document_id' => $document->id,
            'idempotency_key' => $key,
            'status'          => TissTransmissionStatus::Sending->value,
            'queue'           => null,
            'attempt'         => 1,
            'max_attempts'    => 1, // sem retentativa automática
            'requested_at'    => now(),
        ]);

        $this->history->record(
            entityId: (string) $locked->entity_id,
            contextType: 'transmission',
            contextId: (string) $transmission->id,
            currentStatus: TissTransmissionStatus::Sending->value,
            reason: 'Transmissão iniciada.',
            payload: ['guide_id' => (string) $locked->id],
        );

        return $transmission;
    }

    /**
     * Transporte REAL: o XML precisa estar VALIDADO contra o XSD (evidência
     * `metadata.schema`). Sem a evidência (documento anterior à guarda), revalida NO
     * LUGAR — mesmos bytes, mesmo hash, mesma chave. Mock simula sem essa garantia.
     */
    private function assertDeliverable(TissGuide $locked, TissXmlDocument $document): void
    {
        if (! $this->transport instanceof HttpTissTransportClient) {
            return;
        }

        if (! in_array($document->status, [TissXmlStatus::Validated, TissXmlStatus::Signed], true)) {
            throw SurgeryRuleException::invalid('surgery_tiss.errors.xml_rejected');
        }

        if (($document->metadata['schema'] ?? null) !== null) {
            return;
        }

        $validation = $this->validator->validate((string) $document->xml_content, $locked->version?->code ?? $locked->version()->value('code'));

        if (! ($validation['valid'] ?? false)) {
            throw SurgeryRuleException::invalid('surgery_tiss.errors.xml_rejected', context: ['errors' => array_slice((array) ($validation['errors'] ?? []), 0, 5)]);
        }

        if (($validation['schema'] ?? null) === null) {
            throw SurgeryRuleException::state('surgery_tiss.errors.schema_unavailable');
        }

        $document->update([
            'validated_at'      => now(),
            'validation_errors' => array_values(array_merge($validation['errors'] ?? [], $validation['warnings'] ?? [])),
            'metadata'          => array_merge((array) ($document->metadata ?? []), [
                'schema'         => $validation['schema'],
                'revalidated_at' => now()->toIso8601String(),
            ]),
        ]);
    }

    // ── I/O de rede (sem trava) ─────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function dispatch(TissEntityOperatorCredential $credential, TissGuide $guide, TissXmlDocument $document, string $key): array
    {
        try {
            return $this->transport->sendMessage(
                credential: $credential,
                xml: (string) $document->xml_content,
                idempotencyKey: $key,
                transactionType: (string) $guide->guide_type->transactionType(),
                context: ['guide_id' => (string) $guide->id, 'guide_number' => (string) $guide->guide_number_provider],
            );
        } catch (Throwable $e) {
            // O transporte já devolve falha como array; exceção inesperada vira "sem resposta"
            // (nunca finge que a operadora recusou). Sem mensagem do XML no log (LGPD).
            return [
                'ok'               => false,
                'status_code'      => null,
                'response_body'    => null,
                'protocol_number'  => null,
                'protocol_status'  => null,
                'error_code'       => 'transport_exception',
                'error_message'    => class_basename($e),
                'response_time_ms' => null,
            ];
        }
    }

    // ── Fechamento (transação própria, guia travada) ────────────────────────

    /**
     * @param array<string, mixed> $result
     */
    private function finalize(TissGuide $guide, TissTransmission $transmission, array $result, string $key, bool $priorDoubt): TissTransmission
    {
        return DB::transaction(function () use ($guide, $transmission, $result, $key, $priorDoubt): TissTransmission {
            $locked = $this->lockGuide($guide);

            $ok             = (bool) ($result['ok'] ?? false);
            $responseBody   = is_string($result['response_body'] ?? null) ? $result['response_body'] : null;
            $protocolNumber = is_string($result['protocol_number'] ?? null) ? $result['protocol_number'] : null;
            $protocolStatus = is_string($result['protocol_status'] ?? null) ? $result['protocol_status'] : null;

            $noResponse = ! $ok && $this->attemptGotNoResponse($result);
            $uncertain  = ! $ok && ($noResponse || $priorDoubt);

            $status = match (true) {
                $ok        => TissTransmissionStatus::Acknowledged,
                $uncertain => TissTransmissionStatus::Timeout,
                default    => TissTransmissionStatus::Failed,
            };

            $transmission->update([
                'status'           => $status->value,
                'responded_at'     => now(),
                'http_status'      => $result['status_code'] ?? null,
                'response_time_ms' => $result['response_time_ms'] ?? null,
                'error_code'       => $result['error_code'] ?? null,
                'error_message'    => isset($result['error_message']) ? mb_substr((string) $result['error_message'], 0, 1500) : null,
                'response_payload' => $responseBody,
                'metadata'         => ['protocol_status' => $protocolStatus],
            ]);

            $this->history->record(
                entityId: (string) $locked->entity_id,
                contextType: 'transmission',
                contextId: (string) $transmission->id,
                currentStatus: $status->value,
                previousStatus: TissTransmissionStatus::Sending->value,
                reason: match (true) {
                    $ok         => 'Transmissão confirmada pela operadora.',
                    $noResponse => 'Sem resposta da operadora — entrega em dúvida.',
                    $uncertain  => 'Falha na tentativa, mas o envio anterior segue em dúvida (a falha não prova que a operadora não recebeu).',
                    default     => 'Falha na transmissão.',
                },
            );

            $previousGuideStatus = $locked->status->value;

            if ($ok) {
                $this->persistReceipt($locked, $transmission, $responseBody, $protocolNumber, $protocolStatus);

                // Só avança: guia que a operadora já respondeu (autorizada/negada) não volta a "enviada".
                if (in_array($locked->status, [TissGuideStatus::Draft, TissGuideStatus::XmlGenerated, TissGuideStatus::Error], true)) {
                    $locked->update(['status' => TissGuideStatus::Sent->value]);
                }

                $this->markKey($locked, $key, 'completed', ['transmission_id' => (string) $transmission->id, 'protocol_number' => $protocolNumber]);
            } elseif ($uncertain) {
                // A guia continua "XML pronto" mas TRAVADA pela transmissão em dúvida.
                $this->markKey($locked, $key, 'uncertain', ['transmission_id' => (string) $transmission->id, 'error' => $result['error_message'] ?? null]);
            } else {
                $locked->update(['status' => TissGuideStatus::Error->value]);
                $this->markKey($locked, $key, 'failed', ['transmission_id' => (string) $transmission->id, 'error' => $result['error_message'] ?? null]);
            }

            if ($locked->status->value !== $previousGuideStatus) {
                $this->history->record(
                    entityId: (string) $locked->entity_id,
                    contextType: 'guide',
                    contextId: (string) $locked->id,
                    currentStatus: $locked->status->value,
                    previousStatus: $previousGuideStatus,
                    reason: $ok ? 'Guia enviada e recebida pela operadora.' : 'Erro definitivo no envio da guia.',
                    payload: ['transmission_id' => (string) $transmission->id],
                );
            }

            return $transmission->fresh();
        });
    }

    private function persistReceipt(TissGuide $locked, TissTransmission $transmission, ?string $responseBody, ?string $protocolNumber, ?string $protocolStatus): void
    {
        $protocol = TissProtocol::query()->firstOrCreate(
            [
                'entity_id'       => $locked->entity_id,
                'operator_id'     => $locked->operator_id,
                'protocol_number' => $protocolNumber ?: sprintf('PRT-PENDING-%s', $locked->guide_number_provider),
            ],
            [
                'status'          => $this->mapProtocolStatus($protocolStatus)->value,
                'acknowledged_at' => now(),
                'details'         => ['source' => 'surgical_guide_send', 'guide_id' => (string) $locked->id],
            ],
        );

        $documentId = null;

        if ($responseBody) {
            $path = sprintf('tiss/%s/responses/%s_%s_ack.xml', $locked->entity_id, Str::lower((string) $locked->guide_number_provider), now()->format('YmdHis'));

            Storage::disk()->put($path, $responseBody);

            $documentId = TissXmlDocument::query()->create([
                'entity_id'     => $locked->entity_id,
                'operator_id'   => $locked->operator_id,
                'guide_id'      => $locked->id,
                'version_id'    => $locked->version_id,
                'document_type' => 'protocol_receipt',
                'direction'     => TissXmlDirection::Inbound->value,
                'status'        => TissXmlStatus::Validated->value,
                'file_path'     => $path,
                'file_hash'     => hash('sha256', $responseBody),
                'xml_content'   => $responseBody,
                'generated_at'  => now(),
                'validated_at'  => now(),
                'metadata'      => ['source' => 'send_surgical_guide', 'transmission_id' => (string) $transmission->id],
            ])->id;
        }

        $protocol->update([
            'xml_document_id' => $documentId ?: $protocol->xml_document_id,
            'details'         => array_merge((array) ($protocol->details ?? []), ['transmission_id' => (string) $transmission->id, 'protocol_status' => $protocolStatus]),
        ]);

        $transmission->update(['protocol_id' => $protocol->id]);
    }

    /** @param array<string, mixed> $snapshot */
    private function markKey(TissGuide $locked, string $key, string $status, array $snapshot): void
    {
        TissIdempotencyKey::query()
            ->forEntity((string) $locked->entity_id)
            ->where('scope', self::KEY_SCOPE)
            ->where('key', $key)
            ->update(['status' => $status, 'last_seen_at' => now(), 'response_snapshot' => $snapshot]);
    }

    // ── Depois do aceite (fora das transações da guia) ──────────────────────

    /**
     * @param array<string, mixed> $result
     */
    private function afterAcknowledged(TissGuide $guide, TissTransmission $transmission, array $result): void
    {
        $case = SurgicalCase::query()->where('entity_id', $guide->entity_id)->find($guide->surgical_case_id);

        if ($case !== null) {
            $this->recordSubmitted($case, $guide, $transmission);
        }

        // A resposta síncrona pode já trazer a decisão (autorização/negativa).
        $body = is_string($result['response_body'] ?? null) ? $result['response_body'] : null;

        if ($body !== null) {
            try {
                $this->responses->applyFromXml($guide, $body, source: 'transmission');
            } catch (Throwable $e) {
                // A decisão não aplicada NÃO desfaz o envio (a operadora já tem o pedido):
                // a secretária registra à mão. Só classe + origem no log (LGPD: a mensagem de
                // uma exceção de banco pode trazer dado do paciente).
                Log::error('tiss.surgical_guide.response_not_applied', [
                    'guide_id'  => (string) $guide->id,
                    'exception' => $e::class,
                    'origin'    => basename($e->getFile()) . ':' . $e->getLine(),
                ]);
            }
        }
    }

    /** Evento "enviada" (channel tiss) no log do caso — uma vez por transmissão. */
    private function recordSubmitted(SurgicalCase $case, TissGuide $guide, TissTransmission $transmission): void
    {
        if ($case->status !== SurgicalCaseStatus::AwaitingAuthorization) {
            return;
        }

        $already = $case->authorizationEvents()
            ->where('type', AuthorizationEventType::Submitted->value)
            ->where('tiss_guide_id', $guide->id)
            ->where('note', 'like', '%' . $transmission->id . '%')
            ->exists();

        if ($already) {
            return;
        }

        try {
            $this->authorization->record($case, [
                'type'            => AuthorizationEventType::Submitted->value,
                'channel'         => 'tiss',
                'tiss_guide_id'   => (string) $guide->id,
                'protocol_number' => $transmission->protocol?->protocol_number,
                'note'            => 'TISS ' . $guide->guide_number_provider . ' · ' . $transmission->id,
            ]);
        } catch (SurgeryRuleException) {
            // O caso mudou de estado entre o envio e o registro: o log da guia já tem o fato.
        }
    }

    // ── Falha ───────────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $result
     */
    private function failure(TissTransmission $final, array $result, bool $priorDoubt): SurgeryRuleException
    {
        $reason = (string) ($result['error_message'] ?? __('surgery_tiss.errors.unknown_failure'));

        // Dúvida: diz o que isso significa (guia travada, reenvio com a mesma chave).
        if ($final->status === TissTransmissionStatus::Timeout) {
            $key = $this->attemptGotNoResponse($result) ? 'surgery_tiss.errors.delivery_unknown' : 'surgery_tiss.errors.delivery_still_unknown';

            return SurgeryRuleException::state($key, ['reason' => Str::limit($reason, 300)]);
        }

        return SurgeryRuleException::state('surgery_tiss.errors.send_failed', ['reason' => Str::limit($reason, 300)]);
    }

    /**
     * Esta tentativa, sozinha, ficou SEM resposta conclusiva da operadora? (mesma regra
     * do lote: exceção/timeout/408/5xx; 500 com recusa explícita no corpo é veredito;
     * endpoint ausente nunca saiu da clínica.).
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

        if ($statusCode === 500 && ($result['protocol_status'] ?? null) === 'rejected') {
            return false;
        }

        return $statusCode === null
            || in_array($statusCode, self::UNCERTAIN_HTTP_STATUSES, true)
            || str_contains($errorCode, 'timeout');
    }

    // ── Auxiliares ──────────────────────────────────────────────────────────

    private function lockGuide(TissGuide $guide): TissGuide
    {
        return TissGuide::query()
            ->forEntity((string) $guide->entity_id)
            ->whereKey($guide->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function leaseExpired(?CarbonInterface $startedAt): bool
    {
        $leaseSeconds = max(1, (int) config('tiss.transport.lease_seconds', 180));

        return $startedAt === null || $startedAt->lte(now()->subSeconds($leaseSeconds));
    }

    private function credential(TissGuide $guide): TissEntityOperatorCredential
    {
        $guide->loadMissing('contract.credential');

        if ($guide->contract?->credential && $guide->contract->credential->active) {
            return $guide->contract->credential;
        }

        $credential = TissEntityOperatorCredential::query()
            ->forEntity((string) $guide->entity_id)
            ->where('operator_id', $guide->operator_id)
            ->where('environment', (string) config('tiss.transport.environment', 'production'))
            ->where('active', true)
            ->orderByDesc('updated_at')
            ->first();

        if ($credential === null) {
            throw SurgeryRuleException::state('surgery_tiss.errors.no_credential');
        }

        return $credential;
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
}
