<?php

declare(strict_types=1);

namespace App\Domains\Tiss\Actions;

use App\Domains\Tiss\Enums\TissGuideStatus;
use App\Domains\Tiss\Models\{TissEntityOperatorContract, TissGuide};
use App\Domains\Tiss\Services\{LogTissStatusTransitionService, ResolveTissVersionService};
use Illuminate\Support\Facades\DB;

class CreateTissGuideAction
{
    public function __construct(
        private readonly ResolveTissVersionService $resolveVersionService,
        private readonly AddTissGuideItemAction $addTissGuideItemAction,
        private readonly LogTissStatusTransitionService $logStatusTransitionService,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function __invoke(array $payload): TissGuide
    {
        return DB::transaction(function () use ($payload): TissGuide {
            $entityId = (string) ($payload['entity_id'] ?? session('selected_entity_id'));

            $contract = null;

            if (! empty($payload['contract_id'])) {
                $contract = TissEntityOperatorContract::query()
                    ->forEntity($entityId)
                    ->where('id', $payload['contract_id'])
                    ->first();
            }

            $version = $this->resolveVersionService->resolve(
                explicitVersionCode: $payload['version_code'] ?? null,
                contract: $contract,
            );

            $guide = TissGuide::query()->create([
                'entity_id'               => $entityId,
                'operator_id'             => $payload['operator_id'],
                'contract_id'             => $contract?->id,
                'version_id'              => $version->id,
                'patient_id'              => $payload['patient_id'] ?? null,
                'doctor_id'               => $payload['doctor_id'] ?? null,
                'schedule_id'             => $payload['schedule_id'] ?? null,
                'medical_record_id'       => $payload['medical_record_id'] ?? null,
                'guide_type'              => $payload['guide_type'] ?? ($contract?->default_guide_type ?? 'consultation'),
                'guide_number_provider'   => $payload['guide_number_provider'] ?? $this->nextGuideNumber($entityId, (string) $payload['operator_id']),
                'guide_number_operator'   => $payload['guide_number_operator'] ?? null,
                'external_attendance_id'  => $payload['external_attendance_id'] ?? null,
                'status'                  => $payload['status'] ?? TissGuideStatus::Draft->value,
                'attendance_date'         => $payload['attendance_date'] ?? now()->toDateString(),
                'execution_date'          => $payload['execution_date'] ?? null,
                'due_date'                => $payload['due_date'] ?? null,
                'beneficiary_card_number' => $payload['beneficiary_card_number'] ?? null,
                'beneficiary_name'        => $payload['beneficiary_name'] ?? null,
                'beneficiary_plan'        => $payload['beneficiary_plan'] ?? null,
                'authorization_number'    => $payload['authorization_number'] ?? null,
                'clinical_indication'     => $payload['clinical_indication'] ?? null,
                'payload'                 => $payload['payload'] ?? null,
                'errors'                  => null,
                'total_amount'            => 0,
                'denied_amount'           => 0,
                'paid_amount'             => 0,
            ]);

            foreach (($payload['items'] ?? []) as $itemPayload) {
                $this->addTissGuideItemAction->__invoke($guide, is_array($itemPayload) ? $itemPayload : []);
            }

            $guide->refresh();

            $this->logStatusTransitionService->record(
                entityId: (string) $guide->entity_id,
                contextType: 'guide',
                contextId: (string) $guide->id,
                currentStatus: (string) $guide->status->value,
                reason: 'Guia TISS criada.',
            );

            return $guide;
        });
    }

    private function nextGuideNumber(string $entityId, string $operatorId): string
    {
        $prefix = 'GUI-' . now()->format('Ym');

        $lastGuide = TissGuide::query()
            ->forEntity($entityId)
            ->where('operator_id', $operatorId)
            ->where('guide_number_provider', 'like', $prefix . '-%')
            ->orderByDesc('guide_number_provider')
            ->first();

        $next = $lastGuide
            ? ((int) substr((string) $lastGuide->guide_number_provider, strlen($prefix) + 1)) + 1
            : 1;

        return sprintf('%s-%06d', $prefix, $next);
    }
}
