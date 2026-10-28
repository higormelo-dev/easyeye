<?php

declare(strict_types=1);

namespace App\Domains\Tiss\Surgical;

use App\Domains\Tiss\Enums\{TissGuideStatus, TissTransmissionStatus};
use App\Domains\Tiss\Models\{TissGuide, TissGuideItem, TissTransmission};
use App\Domains\Tiss\Services\PreValidateTissGuideService;
use App\Models\Surgery\{SurgicalAuthorizationEvent, SurgicalCase};

/**
 * Serialização da guia cirúrgica para a aba TISS: estado, pré-validação, retrato
 * efetivo (com o que foi completado à mão), itens, XML e histórico de transmissões.
 * Nunca devolve o XML nem o corpo de resposta (PHI): só metadados e ids.
 */
final class SurgicalGuideView
{
    public function __construct(
        private readonly SurgicalGuideService $guides,
        private readonly PreValidateTissGuideService $preValidator,
    ) {
    }

    /** @return array<string, mixed> */
    public function present(SurgicalCase $case, TissGuide $guide, bool $withValidation = true): array
    {
        $guide->loadMissing(['items', 'transmissions.protocol', 'xmlDocument', 'operator']);

        $snapshot = SurgicalGuideSnapshot::of($guide);
        $locked   = $this->guides->isLocked($guide);
        $editable = ! $locked && in_array($guide->status, SurgicalGuideService::EDITABLE_STATUSES, true);

        $validation = $withValidation && ! in_array($guide->status, [TissGuideStatus::Cancelled, TissGuideStatus::Denied], true)
            ? $this->preValidator->validate($guide)
            : null;

        return [
            'id'                   => (string) $guide->id,
            'type'                 => $guide->guide_type->value,
            'number'               => $guide->guide_number_provider,
            'operator_number'      => $guide->guide_number_operator,
            'authorization_number' => $guide->authorization_number,
            'status'               => ['value' => $guide->status->value, 'label' => __('surgery_tiss.guide_status.' . $guide->status->value)],
            'delivery'             => $this->delivery($guide),
            'locked'               => $locked,
            'editable'             => $editable,
            'stale'                => $editable && $this->guides->isStale($guide),
            'validation'           => $validation === null ? null : [
                'passes'   => $validation->passes(),
                'errors'   => $validation->errors()->map(fn ($i) => $i->toArray())->values()->all(),
                'warnings' => $validation->warnings()->map(fn ($i) => $i->toArray())->values()->all(),
            ],
            'fields' => [
                'suggested_date'          => $snapshot->nullableString('suggested_date'),
                'referenced_guide_number' => $snapshot->nullableString('referenced_guide_number'),
                'professional_phone'      => $snapshot->nullableString('professional.phone'),
                'justification'           => $snapshot->nullableString('justification'),
                'specification'           => $snapshot->nullableString('specification'),
                'observation'             => $snapshot->nullableString('observation'),
            ],
            'summary' => [
                'hospital'      => $snapshot->nullableString('hospital.name'),
                'hospital_code' => $snapshot->nullableString('hospital.operator_code'),
                'diagnoses'     => array_values((array) $snapshot->get('diagnoses', [])),
                'days'          => (int) $snapshot->get('days_requested', 0),
            ],
            'overridden' => array_keys(array_filter($snapshot->overrides())),
            'items'      => $guide->items->sortBy('id')->values()->map(fn (TissGuideItem $i) => $this->item($i))->all(),
            'xml'        => $guide->xmlDocument === null ? null : [
                'id'           => (string) $guide->xmlDocument->id,
                'status'       => $guide->xmlDocument->status->value,
                'errors'       => array_slice((array) $guide->xmlDocument->validation_errors, 0, 8),
                'generated_at' => $guide->xmlDocument->generated_at?->toIso8601String(),
                'validated'    => ($guide->xmlDocument->metadata['schema'] ?? null) !== null,
            ],
            'transmissions' => $guide->transmissions->sortByDesc('created_at')->values()->map(fn (TissTransmission $t) => [
                'id'           => (string) $t->id,
                'status'       => $t->status->value,
                'label'        => __('surgery_tiss.transmission_status.' . $t->status->value),
                'attempt'      => $t->attempt,
                'requested_at' => $t->requested_at?->toIso8601String(),
                'responded_at' => $t->responded_at?->toIso8601String(),
                'http_status'  => $t->http_status,
                'error'        => $t->error_message ? mb_substr((string) $t->error_message, 0, 300) : null,
                'protocol'     => $t->protocol?->protocol_number,
            ])->all(),
            'events' => SurgicalAuthorizationEvent::query()
                ->where('surgical_case_id', $case->getKey())
                ->where('tiss_guide_id', $guide->id)
                ->orderBy('occurred_at')
                ->orderBy('created_at')
                ->get()
                ->map(fn (SurgicalAuthorizationEvent $e) => [
                    'id'     => (string) $e->id, 'label' => $e->type->label(), 'occurred_at' => $e->occurred_at?->toIso8601String(),
                    'number' => $e->authorization_number, 'note' => $e->note ?? $e->denial_reason,
                ])->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function item(TissGuideItem $item): array
    {
        $meta = (array) ($item->metadata ?? []);

        return [
            'id'                      => (string) $item->id,
            'description'             => $item->description,
            'quantity'                => (int) round((float) $item->quantity),
            'table_code'              => $item->table_code,
            'tuss_code'               => $item->tuss_code,
            'unit_amount'             => (float) $item->unit_amount,
            'kind'                    => $meta['source_kind'] ?? null,
            'manufacturer'            => $meta['manufacturer'] ?? null,
            'manufacturer_option'     => $meta['manufacturer_option'] ?? null,
            'anvisa_registration'     => $meta['anvisa_registration'] ?? null,
            'manufacturer_ref'        => $meta['manufacturer_ref'] ?? null,
            'operating_authorization' => $meta['operating_authorization'] ?? null,
        ];
    }

    /**
     * Estado da ENTREGA, em palavras que a secretária entende: em voo, EM DÚVIDA (sem
     * resposta — pedido pode ter chegado), entregue, falhou de vez ou ainda não enviada.
     *
     * @return array{state: string, label: string}
     */
    private function delivery(TissGuide $guide): array
    {
        $statuses = $guide->transmissions->map(fn (TissTransmission $t) => $t->status);

        $state = match (true) {
            $statuses->contains(TissTransmissionStatus::Acknowledged) => 'delivered',
            $statuses->contains(TissTransmissionStatus::Timeout)      => 'doubt',
            $statuses->contains(TissTransmissionStatus::Sending)      => 'in_flight',
            $statuses->contains(TissTransmissionStatus::Failed)       => 'failed',
            default                                                   => 'none',
        };

        return ['state' => $state, 'label' => __('surgery_tiss.delivery.' . $state)];
    }
}
