<?php

declare(strict_types=1);

namespace App\Domains\Tiss\Surgical;

use App\Domains\Tiss\Enums\{TissGuideStatus, TissTransmissionStatus, TissXmlDirection, TissXmlStatus};
use App\Domains\Tiss\Models\{TissGuide, TissTransmission, TissXmlDocument};
use App\Domains\Tiss\Services\{LogTissStatusTransitionService, PreValidateTissGuideService, ResolveTissVersionService, ValidateTissXmlService};
use App\Domains\Tiss\Xml\Builders\{V202601TissSurgicalMessageBuilder, V202603TissSurgicalMessageBuilder};
use App\Domains\Tiss\Xml\Contracts\TissSurgicalMessageBuilder;
use App\Domains\Tiss\Xml\TissXmlContentFingerprint;
use App\Exceptions\Surgery\SurgeryRuleException;
use Illuminate\Support\Facades\{DB, Storage};

/**
 * Gera o XML da mensagem avulsa de uma guia cirúrgica (solicitação de internação ou
 * anexo de OPME) e o valida contra o XSD da versão.
 *
 * Regras (as mesmas do lote, na medida em que fazem sentido para uma guia só):
 *  - guia que já foi oferecida à operadora (transmissão em voo, SEM resposta ou
 *    confirmada) tem XML imutável: devolve o documento atual, sem regerar — o horário
 *    do cabeçalho mudaria o hash e, com ele, a chave de idempotência;
 *  - documento já oferecido ao transporte e conteúdo igual ao que sairia agora
 *    (TissXmlContentFingerprint) é reaproveitado — o reenvio depois de uma falha
 *    definitiva retoma a MESMA transmissão;
 *  - pendência de pré-validação (erro) impede gerar: XML que a operadora vai
 *    glosar/recusar não sai.
 * A guia é travada (FOR UPDATE) durante a geração; ordem de locks: guia → transmissão.
 */
class SurgicalMessageXmlService
{
    public function __construct(
        private readonly ResolveTissVersionService $versions,
        private readonly ValidateTissXmlService $validator,
        private readonly PreValidateTissGuideService $preValidator,
        private readonly LogTissStatusTransitionService $history,
        private readonly V202603TissSurgicalMessageBuilder $builder202603,
        private readonly V202601TissSurgicalMessageBuilder $builder202601,
    ) {
    }

    public function generate(TissGuide $guide): TissXmlDocument
    {
        return DB::transaction(function () use ($guide): TissXmlDocument {
            /** @var TissGuide $locked */
            $locked = TissGuide::query()->forEntity((string) $guide->entity_id)->whereKey($guide->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->guide_type->isSurgicalRequest()) {
                throw SurgeryRuleException::invalid('surgery_tiss.errors.type_not_supported');
            }

            if (in_array($locked->status, [TissGuideStatus::Cancelled, TissGuideStatus::Denied], true)) {
                throw SurgeryRuleException::state('surgery_tiss.errors.guide_closed');
            }

            // Já oferecida à operadora: o XML enviado é o registro do que ela recebeu.
            $offered = $this->offeredDocument($locked);

            if ($offered !== null && $this->hasLiveTransmission($locked)) {
                return $offered;
            }

            $locked->loadMissing(['operator', 'items', 'version', 'entity']);

            $validation = $this->preValidator->validate($locked);

            if ($validation->hasErrors()) {
                throw SurgeryRuleException::invalid('surgery_tiss.errors.prevalidation_failed', context: [
                    'issues' => $validation->errors()->map(fn ($i) => $i->toArray())->values()->all(),
                ]);
            }

            if (! $locked->version) {
                $locked->version()->associate($this->versions->resolve(contract: $locked->contract));
                $locked->save();
            }

            $xml = $this->builderFor((string) $locked->version?->code)->build($locked);

            $reusable = $this->transmittedDocumentWithSameContent($locked, $xml);

            if ($reusable !== null) {
                $this->point($locked, $reusable);

                return $reusable;
            }

            $path = sprintf(
                'tiss/%s/guides/%s_%s.xml',
                $locked->entity_id,
                mb_strtolower((string) $locked->guide_number_provider),
                now()->format('YmdHis'),
            );

            Storage::disk()->put($path, $xml);

            $schemaCheck = $this->validator->validate($xml, (string) $locked->version?->code);
            $valid       = (bool) ($schemaCheck['valid'] ?? false);

            $document = TissXmlDocument::query()->create([
                'entity_id'         => $locked->entity_id,
                'operator_id'       => $locked->operator_id,
                'guide_id'          => $locked->id,
                'version_id'        => $locked->version_id,
                'document_type'     => $locked->guide_type->documentType(),
                'direction'         => TissXmlDirection::Outbound->value,
                'status'            => $valid ? TissXmlStatus::Validated->value : TissXmlStatus::Invalid->value,
                'file_path'         => $path,
                'file_hash'         => hash('sha256', $xml),
                'xml_content'       => $xml,
                'generated_at'      => now(),
                'validated_at'      => now(),
                'validation_errors' => array_values(array_merge($schemaCheck['errors'] ?? [], $schemaCheck['warnings'] ?? [])),
                'metadata'          => ['schema' => $schemaCheck['schema'] ?? null],
            ]);

            $previous = $locked->status->value;

            if ($valid) {
                $locked->update(['xml_document_id' => $document->id, 'status' => TissGuideStatus::XmlGenerated->value, 'errors' => null]);
            } else {
                // XML recusado pelo XSD: a guia volta a rascunho com os erros à vista; nada sai.
                $locked->update([
                    'xml_document_id' => null,
                    'status'          => TissGuideStatus::Draft->value,
                    'errors'          => array_values(array_map(fn (string $e) => ['severity' => 'error', 'code' => 'XSD', 'message' => $e], $schemaCheck['errors'] ?? [])),
                ]);
            }

            $this->history->record(
                entityId: (string) $locked->entity_id,
                contextType: 'guide',
                contextId: (string) $locked->id,
                currentStatus: (string) $locked->fresh()->status->value,
                previousStatus: $previous,
                reason: $valid ? 'XML da mensagem avulsa gerado e validado.' : 'XML da mensagem avulsa recusado pelo XSD.',
                payload: ['xml_document_id' => (string) $document->id],
            );

            return $document;
        });
    }

    /** Documento XML atual da guia (o que vai para a operadora). */
    public function currentDocument(TissGuide $guide): ?TissXmlDocument
    {
        if ($guide->xml_document_id === null) {
            return null;
        }

        return TissXmlDocument::query()
            ->forEntity((string) $guide->entity_id)
            ->whereKey($guide->xml_document_id)
            ->first();
    }

    private function offeredDocument(TissGuide $guide): ?TissXmlDocument
    {
        return $this->currentDocument($guide);
    }

    /** Há transmissão em voo, sem resposta ou confirmada: o XML não muda. */
    private function hasLiveTransmission(TissGuide $guide): bool
    {
        return $guide->transmissions()
            ->whereIn('status', [
                TissTransmissionStatus::Sending->value,
                TissTransmissionStatus::Timeout->value,
                TissTransmissionStatus::Acknowledged->value,
            ])
            ->exists();
    }

    /**
     * Documento já oferecido ao transporte (há transmissão sobre ele), válido e de
     * conteúdo igual ao recém-montado — o mais recente que casa. Procura pelas
     * transmissões, não pelo ponteiro da guia: reabrir solta o ponteiro, e o reenvio
     * depois de uma falha definitiva sem mudar nada retoma a MESMA transmissão.
     */
    private function transmittedDocumentWithSameContent(TissGuide $guide, string $xml): ?TissXmlDocument
    {
        $fingerprint = TissXmlContentFingerprint::of($xml);

        if ($fingerprint === null) {
            return null;
        }

        $documents = TissXmlDocument::query()
            ->forEntity((string) $guide->entity_id)
            ->where('guide_id', $guide->id)
            ->whereIn('status', [TissXmlStatus::Validated->value, TissXmlStatus::Signed->value])
            ->whereIn('id', TissTransmission::query()
                ->forEntity((string) $guide->entity_id)
                ->where('guide_id', $guide->id)
                ->select('xml_document_id'))
            ->orderByDesc('generated_at')
            ->get();

        return $documents->first(
            fn (TissXmlDocument $document): bool => $fingerprint === TissXmlContentFingerprint::of((string) $document->xml_content),
        );
    }

    /** Aponta o documento reaproveitado como o atual (o rascunho pode tê-lo solto ao ser reaberto). */
    private function point(TissGuide $locked, TissXmlDocument $document): void
    {
        if ((string) $locked->xml_document_id !== (string) $document->id || $locked->status === TissGuideStatus::Draft) {
            $locked->update(['xml_document_id' => $document->id, 'status' => TissGuideStatus::XmlGenerated->value]);
        }
    }

    private function builderFor(string $versionCode): TissSurgicalMessageBuilder
    {
        return match (true) {
            str_starts_with($versionCode, '202601') => $this->builder202601,
            default                                 => $this->builder202603,
        };
    }
}
