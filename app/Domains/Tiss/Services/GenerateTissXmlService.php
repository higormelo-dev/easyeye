<?php

declare(strict_types=1);

namespace App\Domains\Tiss\Services;

use App\Domains\Tiss\Enums\{TissGuideStatus, TissXmlDirection, TissXmlStatus};
use App\Domains\Tiss\Models\{TissBatch, TissXmlDocument};
use App\Domains\Tiss\Xml\Builders\{V202601TissXmlBuilder, V202603TissXmlBuilder};
use App\Domains\Tiss\Xml\Contracts\TissXmlBuilder;
use Illuminate\Support\Facades\{DB, Storage};

class GenerateTissXmlService
{
    public function __construct(
        private readonly ResolveTissVersionService $resolveVersionService,
        private readonly ValidateTissXmlService $validateTissXmlService,
        private readonly LogTissStatusTransitionService $logStatusTransitionService,
        private readonly V202603TissXmlBuilder $builder202603,
        private readonly V202601TissXmlBuilder $builder202601,
    ) {
    }

    public function generateBatch(TissBatch $batch): TissXmlDocument
    {
        return DB::transaction(function () use ($batch): TissXmlDocument {
            $batch->loadMissing(['contract.preferredVersion', 'version', 'guides.items', 'entity', 'operator']);

            if (! $batch->version) {
                $resolvedVersion = $this->resolveVersionService->resolve(contract: $batch->contract);
                $batch->version()->associate($resolvedVersion);
                $batch->save();
                $batch->refresh();
            }

            $previousBatchStatus = $batch->status->value;
            $batch->update(['status' => 'xml_generating']);

            $this->logStatusTransitionService->record(
                entityId: (string) $batch->entity_id,
                contextType: 'batch',
                contextId: (string) $batch->id,
                currentStatus: 'xml_generating',
                previousStatus: $previousBatchStatus,
                reason: 'Início da geração XML TISS',
            );

            $builder = $this->resolveBuilder((string) $batch->version?->code);
            $xml     = $builder->buildBatch($batch);

            $relativePath = sprintf(
                'private/tiss/%s/batches/%s_%s.xml',
                $batch->entity_id,
                mb_strtolower($batch->batch_number),
                now()->format('YmdHis'),
            );

            Storage::disk('local')->put($relativePath, $xml);

            $validation = $this->validateTissXmlService->validate($xml, (string) $batch->version?->code);

            $xmlDocument = TissXmlDocument::query()->create([
                'entity_id'         => $batch->entity_id,
                'operator_id'       => $batch->operator_id,
                'batch_id'          => $batch->id,
                'version_id'        => $batch->version_id,
                'document_type'     => 'batch_submission',
                'direction'         => TissXmlDirection::Outbound->value,
                'status'            => ($validation['valid'] ?? false) ? TissXmlStatus::Validated->value : TissXmlStatus::Invalid->value,
                'file_path'         => $relativePath,
                'file_hash'         => hash('sha256', $xml),
                'xml_content'       => $xml,
                'generated_at'      => now(),
                'validated_at'      => now(),
                'validation_errors' => array_values(array_merge(
                    $validation['errors'] ?? [],
                    $validation['warnings'] ?? [],
                )),
                'metadata' => ['schema' => $validation['schema'] ?? null],
            ]);

            $batch->update([
                'xml_document_id' => $xmlDocument->id,
                'status'          => ($validation['valid'] ?? false) ? 'xml_ready' : 'error',
                'last_error'      => ($validation['valid'] ?? false) ? null : implode(' | ', $validation['errors'] ?? []),
            ]);

            if ($validation['valid'] ?? false) {
                $batch->guides()->update(['status' => TissGuideStatus::XmlGenerated->value]);
            }

            $this->logStatusTransitionService->record(
                entityId: (string) $batch->entity_id,
                contextType: 'batch',
                contextId: (string) $batch->id,
                currentStatus: (string) $batch->fresh()->status->value,
                previousStatus: 'xml_generating',
                reason: ($validation['valid'] ?? false) ? 'XML TISS gerado e validado.' : 'XML TISS inválido.',
                payload: ['xml_document_id' => (string) $xmlDocument->id],
            );

            return $xmlDocument;
        });
    }

    private function resolveBuilder(string $versionCode): TissXmlBuilder
    {
        return match (true) {
            str_starts_with($versionCode, '202601') => $this->builder202601,
            default                                 => $this->builder202603,
        };
    }
}
