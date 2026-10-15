<?php

declare(strict_types=1);

namespace App\Domains\Tiss\Surgical;

use App\Domains\Tiss\Actions\{AddTissGuideItemAction, CreateTissGuideAction, ResolveTissOperatorForCovenantAction};
use App\Domains\Tiss\Enums\{TissGuideStatus, TissGuideType, TissTransmissionStatus};
use App\Domains\Tiss\Models\{TissGuide, TissGuideItem, TissGuideLink};
use App\Domains\Tiss\Services\LogTissStatusTransitionService;
use App\Enums\Surgery\SurgicalCaseStatus;
use App\Exceptions\Surgery\SurgeryRuleException;
use App\Models\Surgery\SurgicalCase;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Ciclo de vida da guia TISS do caso cirúrgico (solicitação de internação e anexo de
 * OPME): gerar a partir do caso, atualizar, completar à mão, reabrir e cancelar.
 *
 * O CONTEÚDO da guia é um retrato (ver SurgicalGuideSnapshot): congelado na geração,
 * em duas camadas — `data` (derivada do caso) e `overrides` (o que a secretária
 * completou: TUSS do material, telefone do solicitante, data sugerida...). Atualizar
 * a partir do caso troca `data` e PRESERVA `overrides`.
 *
 * Ordem de locks: linha do CASO → linha da GUIA (numeração e transmissão vêm depois).
 * O caso é travado só para ler/serializar a geração (duas abas = uma guia); o envio e
 * o retorno não seguram trava do caso (ver SendSurgicalGuideService).
 */
class SurgicalGuideService
{
    /** Estados do caso em que a guia pode ser gerada/atualizada. */
    public const CASE_STATUSES = [
        SurgicalCaseStatus::Draft,
        SurgicalCaseStatus::AwaitingAuthorization,
        SurgicalCaseStatus::Authorized,
        SurgicalCaseStatus::PartiallyAuthorized,
        SurgicalCaseStatus::Denied,
    ];

    /** Guia ainda editável: rascunho, XML pronto que ninguém ofereceu à operadora, ou falha definitiva. */
    public const EDITABLE_STATUSES = [TissGuideStatus::Draft, TissGuideStatus::XmlGenerated, TissGuideStatus::Error];

    /** Campos da guia que a secretária completa (valor vazio = volta ao derivado do caso). */
    private const GUIDE_OVERRIDES = [
        'suggested_date'          => 'suggested_date',
        'referenced_guide_number' => 'referenced_guide_number',
        'professional_phone'      => 'professional.phone',
        'justification'           => 'justification',
        'specification'           => 'specification',
        'observation'             => 'observation',
    ];

    /** Campos de item completáveis: coluna ou metadata. */
    private const ITEM_COLUMNS = ['tuss_code', 'table_code', 'unit_amount'];

    private const ITEM_METADATA = ['manufacturer_option', 'anvisa_registration', 'manufacturer_ref', 'operating_authorization'];

    public function __construct(
        private readonly SurgicalGuideSource $source,
        private readonly ResolveTissOperatorForCovenantAction $operators,
        private readonly CreateTissGuideAction $createGuide,
        private readonly AddTissGuideItemAction $addItem,
        private readonly LogTissStatusTransitionService $history,
    ) {
    }

    // ── Gerar ───────────────────────────────────────────────────────────────

    /**
     * Gera a guia do tipo pedido — ou devolve a vigente (clique duplo, duas abas:
     * o caso é travado e o índice único parcial é a rede de segurança).
     *
     * @param array<string, mixed> $complement campos de GUIDE_OVERRIDES já informados na geração
     */
    public function generate(SurgicalCase $case, TissGuideType $type, array $complement = []): TissGuide
    {
        $this->assertSurgicalType($type);

        try {
            return DB::transaction(function () use ($case, $type, $complement): TissGuide {
                $locked = $this->lockCase($case);

                $this->assertEligible($locked, $type);

                $existing = $this->current($locked, $type);

                if ($existing !== null) {
                    return $existing;
                }

                $inpatient = $type === TissGuideType::OpmeAttachment ? $this->current($locked, TissGuideType::InpatientRequest) : null;
                $built     = $this->source->build($locked, $type, $inpatient);
                $contract  = ($this->operators)($locked->covenant, (string) $locked->entity_id);

                $guide = ($this->createGuide)([
                    'entity_id'               => (string) $locked->entity_id,
                    'operator_id'             => (string) $contract->operator_id,
                    'contract_id'             => (string) $contract->id,
                    'patient_id'              => (string) $locked->patient_id,
                    'doctor_id'               => (string) $locked->surgeon_doctor_id,
                    'guide_type'              => $type->value,
                    'status'                  => TissGuideStatus::Draft->value,
                    'attendance_date'         => now()->toDateString(),
                    'beneficiary_card_number' => $built['data']['beneficiary']['card'],
                    'beneficiary_name'        => $built['data']['beneficiary']['name'],
                    'clinical_indication'     => $built['data']['clinical_indication'],
                    'payload'                 => [
                        'surgical' => [
                            'data'        => $built['data'],
                            'overrides'   => $this->guideOverrides($complement),
                            'source_hash' => $built['source_hash'],
                        ],
                    ],
                    'items' => [],
                ]);

                $guide->update(['surgical_case_id' => (string) $locked->getKey()]);

                $this->replaceItems($guide, $built['items'], []);
                $this->linkGuides($locked);

                $this->history->record(
                    entityId: (string) $guide->entity_id,
                    contextType: 'guide',
                    contextId: (string) $guide->id,
                    currentStatus: TissGuideStatus::Draft->value,
                    reason: 'Guia gerada a partir do caso cirúrgico.',
                    payload: ['surgical_case_id' => (string) $locked->getKey(), 'guide_type' => $type->value],
                );

                return $guide->refresh();
            });
        } catch (UniqueConstraintViolationException) {
            // Outra requisição criou a guia entre a leitura e o INSERT (não passou pela
            // trava do caso): relê e devolve a vigente.
            $existing = $this->current($case, $type);

            if ($existing === null) {
                throw SurgeryRuleException::state('surgery_tiss.errors.generation_conflict');
            }

            return $existing;
        }
    }

    // ── Atualizar a partir do caso ──────────────────────────────────────────

    /**
     * Refaz o retrato a partir do caso (o pedido foi corrigido depois da geração).
     * Só guia editável e sem transmissão ativa/em dúvida; preserva o que foi completado.
     */
    public function refresh(TissGuide $guide): TissGuide
    {
        return DB::transaction(function () use ($guide): TissGuide {
            [$case, $locked] = $this->lockCaseAndGuide($guide);

            $this->assertEditable($locked);
            $this->assertEligible($case, $locked->guide_type);

            $inpatient = $locked->guide_type === TissGuideType::OpmeAttachment ? $this->current($case, TissGuideType::InpatientRequest) : null;
            $built     = $this->source->build($case, $locked->guide_type, $inpatient);

            $surgical                = (array) (($locked->payload ?? [])['surgical'] ?? []);
            $surgical['data']        = $built['data'];
            $surgical['source_hash'] = $built['source_hash'];

            $oldItems = $this->itemOverrides($locked);

            $locked->update([
                'beneficiary_card_number' => $built['data']['beneficiary']['card'],
                'beneficiary_name'        => $built['data']['beneficiary']['name'],
                'clinical_indication'     => $built['data']['clinical_indication'],
                'doctor_id'               => (string) $case->surgeon_doctor_id,
                'payload'                 => array_merge((array) $locked->payload, ['surgical' => $surgical]),
            ]);

            $this->replaceItems($locked, $built['items'], $oldItems);
            $this->linkGuides($case);
            $this->backToDraft($locked, 'Guia atualizada a partir do caso cirúrgico.');

            return $locked->refresh();
        });
    }

    /** A guia ficou para trás do caso? (o caso mudou desde a geração/última atualização) */
    public function isStale(TissGuide $guide): bool
    {
        $case = $guide->surgicalCase;

        if ($case === null) {
            return false;
        }

        $inpatient = $guide->guide_type === TissGuideType::OpmeAttachment ? $this->current($case, TissGuideType::InpatientRequest) : null;
        $built     = $this->source->build($case, $guide->guide_type, $inpatient);

        return ! hash_equals((string) ((($guide->payload ?? [])['surgical'] ?? [])['source_hash'] ?? ''), $built['source_hash']);
    }

    // ── Completar à mão ─────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $data guia: chaves de GUIDE_OVERRIDES; itens: `items` = [{id, tuss_code?, table_code?, ...}]
     */
    public function complete(TissGuide $guide, array $data): TissGuide
    {
        return DB::transaction(function () use ($guide, $data): TissGuide {
            $locked = $this->lockGuide($guide);

            $this->assertEditable($locked);

            $surgical  = (array) (($locked->payload ?? [])['surgical'] ?? []);
            $overrides = (array) ($surgical['overrides'] ?? []);

            foreach (self::GUIDE_OVERRIDES as $field => $path) {
                if (! array_key_exists($field, $data)) {
                    continue;
                }

                $value = is_string($data[$field]) ? trim($data[$field]) : $data[$field];

                if ($value === null || $value === '') {
                    Arr::forget($overrides, $path);
                } else {
                    Arr::set($overrides, $path, $value);
                }
            }

            $surgical['overrides'] = $overrides;
            $locked->update(['payload' => array_merge((array) $locked->payload, ['surgical' => $surgical])]);

            foreach ((array) ($data['items'] ?? []) as $row) {
                $this->completeItem($locked, (array) $row);
            }

            $locked->update(['total_amount' => (float) $locked->items()->sum('total_amount')]);

            $this->backToDraft($locked, 'Guia completada à mão.');

            return $locked->refresh();
        });
    }

    // ── Reabrir / cancelar ──────────────────────────────────────────────────

    /** XML pronto (nunca oferecido) ou falha definitiva → rascunho, para editar e gerar o XML de novo. */
    public function reopen(TissGuide $guide): TissGuide
    {
        return DB::transaction(function () use ($guide): TissGuide {
            $locked = $this->lockGuide($guide);

            $this->assertEditable($locked);
            $this->backToDraft($locked, 'Guia reaberta para edição.');

            return $locked->refresh();
        });
    }

    public function cancel(TissGuide $guide, string $reason): TissGuide
    {
        return DB::transaction(function () use ($guide, $reason): TissGuide {
            $locked = $this->lockGuide($guide);

            // Guia em dúvida / confirmada pela operadora não se cancela: a operadora pode
            // ter o pedido (o cancelamento junto à operadora é outro processo).
            if ($this->isLocked($locked) || $locked->status === TissGuideStatus::Cancelled) {
                throw SurgeryRuleException::state('surgery_tiss.errors.guide_locked');
            }

            $previous = $locked->status->value;

            $locked->update(['status' => TissGuideStatus::Cancelled->value]);

            $this->history->record(
                entityId: (string) $locked->entity_id,
                contextType: 'guide',
                contextId: (string) $locked->id,
                currentStatus: TissGuideStatus::Cancelled->value,
                previousStatus: $previous,
                reason: mb_substr(trim($reason), 0, 500),
            );

            return $locked->refresh();
        });
    }

    // ── Consultas ───────────────────────────────────────────────────────────

    /**
     * Há transmissão que torna a guia imutável: em voo, SEM resposta (entrega em
     * dúvida) ou confirmada pela operadora. Só a falha definitiva (a operadora
     * respondeu com erro) a mantém editável.
     */
    public function isLocked(TissGuide $guide): bool
    {
        return $guide->transmissions()
            ->whereIn('status', [
                TissTransmissionStatus::Sending->value,
                TissTransmissionStatus::Timeout->value,
                TissTransmissionStatus::Acknowledged->value,
            ])
            ->exists();
    }

    /** Guia vigente do tipo (nem cancelada nem negada). */
    public function current(SurgicalCase $case, TissGuideType $type): ?TissGuide
    {
        return TissGuide::query()
            ->forEntity((string) $case->entity_id)
            ->where('surgical_case_id', $case->getKey())
            ->where('guide_type', $type->value)
            ->whereNotIn('status', [TissGuideStatus::Cancelled->value, TissGuideStatus::Denied->value])
            ->first();
    }

    // ── Internos ────────────────────────────────────────────────────────────

    private function assertSurgicalType(TissGuideType $type): void
    {
        if (! $type->isSurgicalRequest()) {
            throw SurgeryRuleException::invalid('surgery_tiss.errors.type_not_supported');
        }
    }

    private function assertEligible(SurgicalCase $case, TissGuideType $type): void
    {
        $case->loadMissing('covenant');

        if (! $case->requiresInsurer()) {
            throw SurgeryRuleException::state('surgery_tiss.errors.not_insurer');
        }

        if (! in_array($case->status, self::CASE_STATUSES, true)) {
            throw SurgeryRuleException::state('surgery_tiss.errors.case_status', ['status' => $case->status->label()]);
        }

        if ($type === TissGuideType::InpatientRequest && ! $case->regime->requiresInpatientRequest()) {
            throw SurgeryRuleException::state('surgery_tiss.errors.regime_ambulatory');
        }

        if ($type === TissGuideType::OpmeAttachment && ! $case->needs_opme) {
            throw SurgeryRuleException::state('surgery_tiss.errors.no_opme');
        }
    }

    private function assertEditable(TissGuide $locked): void
    {
        if (! in_array($locked->status, self::EDITABLE_STATUSES, true) || $this->isLocked($locked)) {
            throw SurgeryRuleException::state('surgery_tiss.errors.guide_locked');
        }
    }

    private function lockCase(SurgicalCase $case): SurgicalCase
    {
        return SurgicalCase::query()->whereKey($case->getKey())->lockForUpdate()->firstOrFail();
    }

    private function lockGuide(TissGuide $guide): TissGuide
    {
        return TissGuide::query()
            ->forEntity((string) $guide->entity_id)
            ->whereKey($guide->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * Caso primeiro, guia depois (a ordem de todo código que segura os dois).
     *
     * @return array{0: SurgicalCase, 1: TissGuide}
     */
    private function lockCaseAndGuide(TissGuide $guide): array
    {
        $caseId = (string) $guide->surgical_case_id;

        if ($caseId === '') {
            throw SurgeryRuleException::state('surgery_tiss.errors.guide_without_case');
        }

        $case = SurgicalCase::query()->whereKey($caseId)->lockForUpdate()->firstOrFail();

        return [$case, $this->lockGuide($guide)];
    }

    /** Edição invalida o XML pronto: volta a rascunho e solta o documento (o próximo envio gera outro). */
    private function backToDraft(TissGuide $locked, string $reason): void
    {
        $previous = $locked->status->value;

        $locked->update([
            'status'          => TissGuideStatus::Draft->value,
            'xml_document_id' => null,
            'errors'          => null,
        ]);

        if ($previous !== TissGuideStatus::Draft->value) {
            $this->history->record(
                entityId: (string) $locked->entity_id,
                contextType: 'guide',
                contextId: (string) $locked->id,
                currentStatus: TissGuideStatus::Draft->value,
                previousStatus: $previous,
                reason: $reason,
            );
        }
    }

    /**
     * Itens da guia a partir do caso, reaplicando o que o usuário completou
     * (chaveado pelo item de origem).
     *
     * @param list<array<string, mixed>>          $items
     * @param array<string, array<string, mixed>> $keptOverrides source_id => overrides
     */
    private function replaceItems(TissGuide $guide, array $items, array $keptOverrides): void
    {
        $guide->items()->get()->each->delete();

        foreach ($items as $item) {
            $sourceId  = (string) ($item['metadata']['source_id'] ?? '');
            $overrides = $keptOverrides[$sourceId] ?? [];

            foreach (self::ITEM_COLUMNS as $column) {
                if (array_key_exists($column, $overrides)) {
                    $item[$column] = $overrides[$column];
                }
            }

            foreach (self::ITEM_METADATA as $key) {
                if (array_key_exists($key, $overrides)) {
                    $item['metadata'][$key] = $overrides[$key];
                }
            }

            if ($overrides !== []) {
                $item['metadata']['overrides'] = $overrides;
            }

            ($this->addItem)($guide, $item);
        }

        $guide->update(['total_amount' => (float) $guide->items()->sum('total_amount')]);
    }

    /**
     * O que o usuário completou em cada item atual, por item de origem.
     *
     * @return array<string, array<string, mixed>>
     */
    private function itemOverrides(TissGuide $guide): array
    {
        $kept = [];

        foreach ($guide->items()->get() as $item) {
            $meta = (array) ($item->metadata ?? []);

            if (filled($meta['source_id'] ?? null) && ! empty($meta['overrides'])) {
                $kept[(string) $meta['source_id']] = (array) $meta['overrides'];
            }
        }

        return $kept;
    }

    /** @param array<string, mixed> $row */
    private function completeItem(TissGuide $locked, array $row): void
    {
        /** @var TissGuideItem|null $item */
        $item = $locked->items()->whereKey($row['id'] ?? null)->first();

        if ($item === null) {
            throw SurgeryRuleException::invalid('surgery_tiss.errors.item_not_found');
        }

        $meta      = (array) ($item->metadata ?? []);
        $overrides = (array) ($meta['overrides'] ?? []);
        $columns   = [];

        foreach (self::ITEM_COLUMNS as $column) {
            if (! array_key_exists($column, $row)) {
                continue;
            }

            $value = is_string($row[$column]) ? trim($row[$column]) : $row[$column];

            if ($value === null || $value === '') {
                unset($overrides[$column]);

                continue;
            }

            $overrides[$column] = $value;
            $columns[$column]   = $value;
        }

        foreach (self::ITEM_METADATA as $key) {
            if (! array_key_exists($key, $row)) {
                continue;
            }

            $value = is_string($row[$key]) ? trim($row[$key]) : $row[$key];

            if ($value === null || $value === '') {
                unset($overrides[$key]);

                continue;
            }

            $overrides[$key] = $value;
            $meta[$key]      = $value;
        }

        $meta['overrides'] = $overrides;

        $quantity = (float) $item->quantity;
        $unit     = (float) ($columns['unit_amount'] ?? $item->unit_amount);

        $item->update([
            ...$columns,
            'total_amount' => round($quantity * $unit, 2),
            'metadata'     => $meta,
        ]);
    }

    /**
     * Valores de GUIDE_OVERRIDES já na forma aninhada do retrato.
     *
     * @param array<string, mixed> $complement
     *
     * @return array<string, mixed>
     */
    private function guideOverrides(array $complement): array
    {
        $overrides = [];

        foreach (self::GUIDE_OVERRIDES as $field => $path) {
            $value = is_string($complement[$field] ?? null) ? trim($complement[$field]) : ($complement[$field] ?? null);

            if ($value !== null && $value !== '') {
                Arr::set($overrides, $path, $value);
            }
        }

        return $overrides;
    }

    /** Internação ↔ anexo de OPME do mesmo caso (TissGuideLink: pai = internação, filho = anexo). */
    private function linkGuides(SurgicalCase $case): void
    {
        $inpatient = $this->current($case, TissGuideType::InpatientRequest);
        $opme      = $this->current($case, TissGuideType::OpmeAttachment);

        if ($inpatient === null || $opme === null) {
            return;
        }

        TissGuideLink::query()->firstOrCreate([
            'entity_id'       => (string) $case->entity_id,
            'parent_guide_id' => (string) $inpatient->id,
            'child_guide_id'  => (string) $opme->id,
            'link_type'       => 'opme_attachment',
        ]);
    }
}
