<?php

declare(strict_types=1);

namespace App\Domains\Tiss\Actions;

use App\Domains\Tiss\Enums\TissGuideStatus;
use App\Domains\Tiss\Models\TissGuide;
use App\Domains\Tiss\Services\LogTissStatusTransitionService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class CancelTissGuideAction
{
    /** @var TissGuideStatus[] Statuses que não permitem cancelamento */
    private const IMMUTABLE_STATUSES = [
        TissGuideStatus::Sent,
        TissGuideStatus::Acknowledged,
        TissGuideStatus::Paid,
    ];

    public function __construct(
        private readonly LogTissStatusTransitionService $logStatusTransitionService,
    ) {
    }

    public function __invoke(TissGuide $guide, ?string $reason = null): TissGuide
    {
        if (in_array($guide->status, self::IMMUTABLE_STATUSES, true)) {
            throw new InvalidArgumentException(
                sprintf('Guia não pode ser cancelada. Status atual: %s.', $guide->status->value),
            );
        }

        if ($guide->status === TissGuideStatus::Cancelled) {
            return $guide;
        }

        return DB::transaction(function () use ($guide, $reason): TissGuide {
            $previousStatus = $guide->status->value;

            $guide->update(['status' => TissGuideStatus::Cancelled->value]);

            $this->logStatusTransitionService->record(
                entityId: (string) $guide->entity_id,
                contextType: 'guide',
                contextId: (string) $guide->id,
                currentStatus: TissGuideStatus::Cancelled->value,
                previousStatus: $previousStatus,
                reason: $reason ?? 'Guia cancelada.',
            );

            return $guide->fresh();
        });
    }
}
