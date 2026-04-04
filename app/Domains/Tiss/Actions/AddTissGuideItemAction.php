<?php

declare(strict_types=1);

namespace App\Domains\Tiss\Actions;

use App\Domains\Tiss\Models\{TissGuide, TissGuideItem};
use Illuminate\Support\Facades\DB;

class AddTissGuideItemAction
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __invoke(TissGuide $guide, array $payload): TissGuideItem
    {
        return DB::transaction(function () use ($guide, $payload): TissGuideItem {
            $quantity    = (float) ($payload['quantity'] ?? 1);
            $unitAmount  = (float) ($payload['unit_amount'] ?? 0);
            $totalAmount = (float) ($payload['total_amount'] ?? ($quantity * $unitAmount));

            $item = TissGuideItem::query()->create([
                'entity_id'            => $guide->entity_id,
                'guide_id'             => $guide->id,
                'status'               => $payload['status'] ?? 'pending',
                'tuss_code'            => $payload['tuss_code'],
                'table_code'           => $payload['table_code'] ?? '22',
                'procedure_code'       => $payload['procedure_code'] ?? null,
                'description'          => $payload['description'] ?? 'PROCEDIMENTO',
                'quantity'             => $quantity,
                'unit_amount'          => $unitAmount,
                'total_amount'         => $totalAmount,
                'execution_date'       => $payload['execution_date'] ?? null,
                'authorization_number' => $payload['authorization_number'] ?? null,
                'metadata'             => $payload['metadata'] ?? null,
            ]);

            $guide->update([
                'total_amount' => (float) $guide->items()->sum('total_amount'),
            ]);

            return $item;
        });
    }
}
