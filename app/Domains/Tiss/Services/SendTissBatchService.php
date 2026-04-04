<?php

declare(strict_types=1);

namespace App\Domains\Tiss\Services;

use App\Domains\Tiss\Enums\{TissBatchStatus, TissGuideStatus, TissProtocolStatus, TissTransmissionStatus, TissXmlDirection, TissXmlStatus};
use App\Domains\Tiss\Models\{TissBatch, TissEntityOperatorCredential, TissIdempotencyKey, TissProtocol, TissTransmission, TissXmlDocument};
use App\Domains\Tiss\Services\Transport\TissTransportContract;
use Illuminate\Support\Facades\{DB, Storage};
use RuntimeException;

class SendTissBatchService
{
    public function __construct(
        private readonly GenerateTissXmlService $generateTissXmlService,
        private readonly LogTissStatusTransitionService $logStatusTransitionService,
        private readonly TissTransportContract $transportClient,
    ) {
    }

    public function send(TissBatch $batch, bool $force = false, bool $throwOnError = true): TissTransmission
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

        $attempt = TissTransmission::query()
            ->forEntity((string) $batch->entity_id)
            ->where('batch_id', $batch->id)
            ->where('idempotency_key', $idempotencyKey)
            ->count() + 1;

        $transmission = DB::transaction(function () use ($batch, $xmlDocument, $idempotencyKey, $attempt): TissTransmission {
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

            return $transmission;
        });

        $result = $this->transportClient->sendBatch(
            credential: $credential,
            xml: (string) ($xmlDocument->xml_content ?? ''),
            idempotencyKey: $idempotencyKey,
            context: [
                'batch_id'     => (string) $batch->id,
                'batch_number' => (string) $batch->batch_number,
            ],
        );

        $finalTransmission = $this->finalizeTransmission($batch, $transmission, $result, $idempotencyKey);

        if (($result['ok'] ?? false) !== true && $throwOnError) {
            throw new RuntimeException((string) ($result['error_message'] ?? 'Falha ao enviar lote TISS.'));
        }

        return $finalTransmission;
    }

    /**
     * @param array<string, mixed> $result
     */
    private function finalizeTransmission(
        TissBatch $batch,
        TissTransmission $transmission,
        array $result,
        string $idempotencyKey,
    ): TissTransmission {
        return DB::transaction(function () use ($batch, $transmission, $result, $idempotencyKey): TissTransmission {
            $ok             = (bool) ($result['ok'] ?? false);
            $responseBody   = is_string($result['response_body'] ?? null) ? $result['response_body'] : null;
            $protocolNumber = is_string($result['protocol_number'] ?? null) ? $result['protocol_number'] : null;
            $protocolStatus = is_string($result['protocol_status'] ?? null) ? $result['protocol_status'] : null;

            $transmissionStatus = $ok ? TissTransmissionStatus::Acknowledged : $this->resolveFailureTransmissionStatus($result);

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
                reason: $ok ? 'Transmissão confirmada pela operadora.' : 'Falha na transmissão.',
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
                $batch->update([
                    'status'     => TissBatchStatus::Error->value,
                    'last_error' => $result['error_message'] ?? 'Falha desconhecida no envio.',
                ]);

                $this->logStatusTransitionService->record(
                    entityId: (string) $batch->entity_id,
                    contextType: 'batch',
                    contextId: (string) $batch->id,
                    currentStatus: TissBatchStatus::Error->value,
                    previousStatus: TissBatchStatus::Sending->value,
                    reason: 'Erro no envio do lote.',
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
                        'status'            => 'failed',
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

    private function persistAcknowledgementProtocol(
        TissBatch $batch,
        TissTransmission $transmission,
        ?string $responseBody,
        ?string $protocolNumber,
        ?string $protocolStatus,
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
                'private/tiss/%s/responses/%s_%s_ack.xml',
                $batch->entity_id,
                mb_strtolower((string) $batch->batch_number),
                now()->format('YmdHis'),
            );

            Storage::disk('local')->put($relativePath, $responseBody);

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

    private function reserveIdempotency(
        TissBatch $batch,
        string $idempotencyKey,
        TissXmlDocument $xmlDocument,
        bool $force,
    ): TissIdempotencyKey {
        $ttlHours = (int) config('tiss.idempotency.ttl_hours', 48);

        return TissIdempotencyKey::query()->updateOrCreate(
            [
                'entity_id' => $batch->entity_id,
                'scope'     => 'batch_send',
                'key'       => $idempotencyKey,
            ],
            [
                'fingerprint'  => (string) ($xmlDocument->file_hash ?: hash('sha256', (string) ($xmlDocument->xml_content ?? ''))),
                'status'       => $force ? 'forced' : 'reserved',
                'last_seen_at' => now(),
                'expires_at'   => now()->addHours($ttlHours),
            ],
        );
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
     * @param array<string, mixed> $result
     */
    private function resolveFailureTransmissionStatus(array $result): TissTransmissionStatus
    {
        $statusCode = $result['status_code'] ?? null;
        $errorCode  = (string) ($result['error_code'] ?? '');

        if ($statusCode === 408 || str_contains($errorCode, 'timeout')) {
            return TissTransmissionStatus::Timeout;
        }

        return TissTransmissionStatus::Failed;
    }
}
