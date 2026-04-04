<?php

declare(strict_types=1);

namespace App\Domains\Tiss\Services;

use App\Domains\Tiss\Enums\{TissProtocolStatus, TissReturnStatus, TissXmlDirection, TissXmlStatus};
use App\Domains\Tiss\Jobs\ProcessTissReturnJob;
use App\Domains\Tiss\Models\{TissBatch, TissProtocol, TissReturn, TissXmlDocument};
use App\Domains\Tiss\Parsers\TissReturnParser;
use Illuminate\Support\Facades\{DB, Storage};

class ReceiveTissResponseService
{
    public function __construct(
        private readonly TissReturnParser $parser,
        private readonly ValidateTissXmlService $validateTissXmlService,
        private readonly ProcessTissReturnService $processTissReturnService,
        private readonly LogTissStatusTransitionService $logStatusTransitionService,
    ) {
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function receive(
        string $entityId,
        string $operatorId,
        string $xmlContent,
        ?TissProtocol $protocol = null,
        ?TissBatch $batch = null,
        bool $processSynchronously = false,
        array $metadata = [],
    ): TissReturn {
        $parsed = $this->parser->parse($xmlContent);

        $protocol ??= $this->resolveProtocol($entityId, $operatorId, $parsed['protocol_number'] ?? null);
        $batch ??= $protocol?->batch;

        if (! $batch && ! empty($parsed['batch_number'])) {
            $batch = TissBatch::query()
                ->forEntity($entityId)
                ->where('operator_id', $operatorId)
                ->where('batch_number', $parsed['batch_number'])
                ->first();
        }

        $validation = $this->validateTissXmlService->validate($xmlContent, $batch?->version?->code);

        $return = DB::transaction(function () use ($entityId, $operatorId, $xmlContent, $parsed, $protocol, $batch, $validation, $metadata): TissReturn {
            $relativePath = sprintf(
                'private/tiss/%s/returns/%s_%s.xml',
                $entityId,
                mb_strtolower((string) ($parsed['protocol_number'] ?? $batch?->batch_number ?? 'return')),
                now()->format('YmdHis'),
            );

            Storage::disk('local')->put($relativePath, $xmlContent);

            $xmlDocument = TissXmlDocument::query()->create([
                'entity_id'         => $entityId,
                'operator_id'       => $operatorId,
                'batch_id'          => $batch?->id,
                'version_id'        => $batch?->version_id,
                'document_type'     => 'return_xml',
                'direction'         => TissXmlDirection::Inbound->value,
                'status'            => ($validation['valid'] ?? false) ? TissXmlStatus::Validated->value : TissXmlStatus::Invalid->value,
                'file_path'         => $relativePath,
                'file_hash'         => hash('sha256', $xmlContent),
                'xml_content'       => $xmlContent,
                'generated_at'      => now(),
                'validated_at'      => now(),
                'validation_errors' => array_values(array_merge($validation['errors'] ?? [], $validation['warnings'] ?? [])),
                'metadata'          => array_merge([
                    'source'          => 'receive_tiss_response',
                    'protocol_number' => $parsed['protocol_number'] ?? null,
                ], $metadata),
            ]);

            $resolvedProtocol = $protocol;

            if (! $resolvedProtocol && ! empty($parsed['protocol_number'])) {
                $resolvedProtocol = TissProtocol::query()->firstOrCreate(
                    [
                        'entity_id'       => $entityId,
                        'operator_id'     => $operatorId,
                        'protocol_number' => (string) $parsed['protocol_number'],
                    ],
                    [
                        'batch_id'        => $batch?->id,
                        'xml_document_id' => $xmlDocument->id,
                        'status'          => TissProtocolStatus::Received->value,
                        'acknowledged_at' => now(),
                        'details'         => ['source' => 'return_xml'],
                    ],
                );
            }

            if ($resolvedProtocol) {
                $protocolStatus = $this->mapProtocolStatus((string) ($parsed['status'] ?? 'received'));

                $resolvedProtocol->update([
                    'batch_id'        => $resolvedProtocol->batch_id ?: $batch?->id,
                    'xml_document_id' => $resolvedProtocol->xml_document_id ?: $xmlDocument->id,
                    'status'          => $protocolStatus->value,
                    'details'         => array_merge((array) ($resolvedProtocol->details ?? []), [
                        'last_return_received_at' => now()->toIso8601String(),
                    ]),
                ]);

                $this->logStatusTransitionService->record(
                    entityId: $entityId,
                    contextType: 'protocol',
                    contextId: (string) $resolvedProtocol->id,
                    currentStatus: $protocolStatus->value,
                    reason: 'Retorno XML recebido da operadora.',
                );
            }

            $return = TissReturn::query()->create([
                'entity_id'       => $entityId,
                'operator_id'     => $operatorId,
                'version_id'      => $batch?->version_id,
                'protocol_id'     => $resolvedProtocol?->id,
                'xml_document_id' => $xmlDocument->id,
                'return_type'     => 'batch_return',
                'status'          => TissReturnStatus::Parsed->value,
                'received_at'     => now(),
                'summary'         => $parsed,
                'raw_payload'     => $xmlContent,
                'error_message'   => ($validation['valid'] ?? false) ? null : implode(' | ', $validation['errors'] ?? []),
                'metadata'        => [
                    'validation_schema' => $validation['schema'] ?? null,
                    'batch_id'          => $batch?->id,
                    'protocol_number'   => $parsed['protocol_number'] ?? null,
                ],
            ]);

            $this->logStatusTransitionService->record(
                entityId: $entityId,
                contextType: 'return',
                contextId: (string) $return->id,
                currentStatus: TissReturnStatus::Parsed->value,
                reason: 'Retorno TISS parseado e persistido.',
            );

            return $return;
        });

        if ($processSynchronously) {
            return $this->processTissReturnService->process($return);
        }

        if ((bool) config('tiss.returns.auto_process', true)) {
            ProcessTissReturnJob::dispatch((string) $return->id)
                ->onQueue((string) config('tiss.queues.returns', 'tiss-returns'));
        }

        return $return;
    }

    private function resolveProtocol(string $entityId, string $operatorId, ?string $protocolNumber): ?TissProtocol
    {
        if (! $protocolNumber) {
            return null;
        }

        return TissProtocol::query()
            ->forEntity($entityId)
            ->where('operator_id', $operatorId)
            ->where('protocol_number', $protocolNumber)
            ->first();
    }

    private function mapProtocolStatus(string $status): TissProtocolStatus
    {
        return match ($status) {
            'accepted'   => TissProtocolStatus::Accepted,
            'rejected'   => TissProtocolStatus::Rejected,
            'processing' => TissProtocolStatus::Processing,
            'error'      => TissProtocolStatus::Error,
            default      => TissProtocolStatus::Received,
        };
    }
}
