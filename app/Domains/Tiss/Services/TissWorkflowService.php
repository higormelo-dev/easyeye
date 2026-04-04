<?php

declare(strict_types=1);

namespace App\Domains\Tiss\Services;

use App\Domains\Tiss\Actions\{AttachGuideToBatchAction, CancelTissBatchAction, CancelTissGuideAction, CloseTissBatchAction, CreateTissBatchAction, CreateTissGuideAction, RetryTissBatchAction};
use App\Domains\Tiss\Jobs\{GenerateTissBatchXmlJob, SendTissBatchJob};
use App\Domains\Tiss\Models\{TissBatch, TissGuide, TissReturn};

class TissWorkflowService
{
    public function __construct(
        private readonly CreateTissBatchAction $createTissBatchAction,
        private readonly CreateTissGuideAction $createTissGuideAction,
        private readonly AttachGuideToBatchAction $attachGuideToBatchAction,
        private readonly CloseTissBatchAction $closeTissBatchAction,
        private readonly CancelTissGuideAction $cancelTissGuideAction,
        private readonly CancelTissBatchAction $cancelTissBatchAction,
        private readonly RetryTissBatchAction $retryTissBatchAction,
        private readonly GenerateTissXmlService $generateTissXmlService,
        private readonly SendTissBatchService $sendTissBatchService,
        private readonly ReceiveTissResponseService $receiveTissResponseService,
    ) {
    }

    /**
     * @param array<string, mixed> $batchPayload
     */
    public function createBatch(array $batchPayload): TissBatch
    {
        return ($this->createTissBatchAction)($batchPayload);
    }

    /**
     * @param array<string, mixed> $guidePayload
     */
    public function createGuide(array $guidePayload): TissGuide
    {
        return ($this->createTissGuideAction)($guidePayload);
    }

    public function attachGuideToBatch(TissBatch $batch, TissGuide $guide): void
    {
        ($this->attachGuideToBatchAction)($batch, $guide);
    }

    public function closeBatch(TissBatch $batch): TissBatch
    {
        return ($this->closeTissBatchAction)($batch);
    }

    public function cancelGuide(TissGuide $guide, ?string $reason = null): TissGuide
    {
        return ($this->cancelTissGuideAction)($guide, $reason);
    }

    public function cancelBatch(TissBatch $batch, ?string $reason = null): TissBatch
    {
        return ($this->cancelTissBatchAction)($batch, $reason);
    }

    public function retryBatch(TissBatch $batch, ?string $reason = null): TissBatch
    {
        return ($this->retryTissBatchAction)($batch, $reason);
    }

    public function generateXmlNow(TissBatch $batch): void
    {
        $this->generateTissXmlService->generateBatch($batch);
    }

    public function generateXmlInQueue(TissBatch $batch): void
    {
        GenerateTissBatchXmlJob::dispatch((string) $batch->id, (string) $batch->entity_id)
            ->onQueue((string) config('tiss.queues.xml', 'tiss-xml'));
    }

    public function sendBatchNow(TissBatch $batch, bool $force = false): void
    {
        $this->sendTissBatchService->send(batch: $batch, force: $force, throwOnError: true);
    }

    public function sendBatchInQueue(TissBatch $batch, bool $force = false): void
    {
        SendTissBatchJob::dispatch((string) $batch->id, (string) $batch->entity_id, $force)
            ->onQueue((string) config('tiss.queues.send', 'tiss-send'));
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function receiveResponse(
        string $entityId,
        string $operatorId,
        string $xmlContent,
        bool $processSynchronously = false,
        array $metadata = [],
    ): TissReturn {
        return $this->receiveTissResponseService->receive(
            entityId: $entityId,
            operatorId: $operatorId,
            xmlContent: $xmlContent,
            processSynchronously: $processSynchronously,
            metadata: $metadata,
        );
    }
}
