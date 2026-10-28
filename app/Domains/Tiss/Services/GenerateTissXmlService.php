<?php

declare(strict_types=1);

namespace App\Domains\Tiss\Services;

use App\Domains\Tiss\Enums\{TissBatchStatus, TissGuideStatus, TissXmlDirection, TissXmlStatus};
use App\Domains\Tiss\Exceptions\TissBatchCancelledException;
use App\Domains\Tiss\Models\{TissBatch, TissTransmission, TissXmlDocument};
use App\Domains\Tiss\Xml\Builders\{V202601TissXmlBuilder, V202603TissXmlBuilder};
use App\Domains\Tiss\Xml\Contracts\TissXmlBuilder;
use App\Domains\Tiss\Xml\TissXmlContentFingerprint;
use Illuminate\Support\Facades\{DB, Storage};

class GenerateTissXmlService
{
    /**
     * Lote cuja mensagem já saiu para a operadora — ou pode ter saído (enviando:
     * em voo, worker morto, ou SEM RESPOSTA da operadora — entrega em dúvida,
     * ver SendTissBatchService::finalizeTransmission): o XML enviado é
     * imutável. Mesmo conjunto que impede cancelar o lote (CancelTissBatchAction)
     * e tirar guia dele (DetachGuideFromBatchAction::EDITABLE_STATUSES).
     */
    public const SENT_STATUSES = [
        TissBatchStatus::Sending,
        TissBatchStatus::Sent,
        TissBatchStatus::Acknowledged,
        TissBatchStatus::Processed,
        TissBatchStatus::PartiallyPaid,
        TissBatchStatus::Paid,
    ];

    public function __construct(
        private readonly ResolveTissVersionService $resolveVersionService,
        private readonly ValidateTissXmlService $validateTissXmlService,
        private readonly LogTissStatusTransitionService $logStatusTransitionService,
        private readonly V202603TissXmlBuilder $builder202603,
        private readonly V202601TissXmlBuilder $builder202601,
    ) {
    }

    /**
     * Gera o XML do lote — exceto quando ele já foi (ou pode ter sido) à
     * operadora, caso em que devolve o documento existente:
     *
     *  1. lote enviando/enviado/processado/pago: o XML enviado é o registro do
     *     que a operadora recebeu; regerar divergia dele e ainda empurrava o
     *     lote (e as guias) de "enviado" de volta para "XML pronto";
     *  2. documento já oferecido ao transporte (há transmissão sobre ele) e
     *     conteúdo igual ao que sairia agora: o horário do cabeçalho muda a
     *     cada geração e entra no hash do arquivo — a chave de idempotência
     *     do envio —, então regerar o MESMO lote criava outra identidade para
     *     uma mensagem que a operadora talvez já tivesse recebido. Conteúdo
     *     diferente (guia retirada/corrigida, dado cadastral) continua
     *     gerando um documento novo.
     *
     * Um documento que nunca foi ao transporte continua sendo regerado, com o
     * horário de agora, como sempre.
     */
    public function generateBatch(TissBatch $batch): TissXmlDocument
    {
        return DB::transaction(function () use ($batch): TissXmlDocument {
            // Estado do lote lido JÁ com o lock: um envio que está virando
            // "enviando" (ou acabou de confirmar) não é atropelado por uma
            // geração que leu o status antes.
            TissBatch::query()->forEntity((string) $batch->entity_id)->whereKey($batch->getKey())->lockForUpdate()->firstOrFail();
            $batch->refresh();

            // Lote CANCELADO (ex.: a geração e o envio já estavam na fila quando o
            // usuário cancelou): nada a gerar. Seguir regerava o XML das guias
            // canceladas ainda anexadas, devolvia o lote a "XML pronto" e as guias a
            // "XML gerado" — e o envio seguinte, sem mais nenhum sinal do
            // cancelamento, transmitia o que o usuário cancelou (achado via revisão
            // adversarial Codex, 23ª rodada). Recusa ANTES de qualquer escrita.
            if ($batch->status === TissBatchStatus::Cancelled) {
                throw new TissBatchCancelledException(__('tiss_send.batch_cancelled_xml'));
            }

            $sent = $this->sentDocument($batch);

            if ($sent !== null) {
                return $sent;
            }

            $batch->loadMissing(['contract.preferredVersion', 'version', 'guides.items', 'entity', 'operator']);

            if (! $batch->version) {
                $resolvedVersion = $this->resolveVersionService->resolve(contract: $batch->contract);
                $batch->version()->associate($resolvedVersion);
                $batch->save();
                $batch->refresh();
            }

            $builder = $this->resolveBuilder((string) $batch->version?->code);
            $xml     = $builder->buildBatch($batch);

            $transmitted = $this->transmittedDocumentWithSameContent($batch, $xml);

            if ($transmitted !== null) {
                return $transmitted;
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

            $relativePath = sprintf(
                'tiss/%s/batches/%s_%s.xml',
                $batch->entity_id,
                mb_strtolower($batch->batch_number),
                now()->format('YmdHis'),
            );

            Storage::disk()->put($relativePath, $xml);

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

    /** Documento do lote que já saiu (ou pode ter saído) para a operadora — null se ainda é regerável. */
    private function sentDocument(TissBatch $batch): ?TissXmlDocument
    {
        if (! in_array($batch->status, self::SENT_STATUSES, true)) {
            return null;
        }

        return $this->currentDocument($batch);
    }

    /**
     * Documento atual do lote, se já foi oferecido ao transporte, está válido e
     * tem o mesmo conteúdo do XML que acabou de ser montado.
     */
    private function transmittedDocumentWithSameContent(TissBatch $batch, string $xml): ?TissXmlDocument
    {
        $current = $this->currentDocument($batch);

        if ($current === null || ! in_array($current->status, [TissXmlStatus::Validated, TissXmlStatus::Signed], true)) {
            return null;
        }

        $transmitted = TissTransmission::query()
            ->forEntity((string) $batch->entity_id)
            ->where('batch_id', $batch->id)
            ->where('xml_document_id', $current->id)
            ->exists();

        if (! $transmitted) {
            return null;
        }

        $fingerprint = TissXmlContentFingerprint::of($xml);

        return $fingerprint !== null && $fingerprint === TissXmlContentFingerprint::of((string) $current->xml_content)
            ? $current
            : null;
    }

    private function currentDocument(TissBatch $batch): ?TissXmlDocument
    {
        if ($batch->xml_document_id === null) {
            return null;
        }

        return TissXmlDocument::query()
            ->forEntity((string) $batch->entity_id)
            ->whereKey($batch->xml_document_id)
            ->first();
    }

    private function resolveBuilder(string $versionCode): TissXmlBuilder
    {
        return match (true) {
            str_starts_with($versionCode, '202601') => $this->builder202601,
            default                                 => $this->builder202603,
        };
    }
}
