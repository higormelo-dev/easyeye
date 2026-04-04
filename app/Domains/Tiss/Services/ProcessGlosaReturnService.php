<?php

declare(strict_types=1);

namespace App\Domains\Tiss\Services;

use App\Domains\Tiss\Enums\TissGlosaStatus;
use App\Domains\Tiss\Models\{TissGlosa, TissGuide, TissGuideItem, TissReturn};
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ProcessGlosaReturnService
{
    public function __construct(
        private readonly LogTissStatusTransitionService $logStatusTransitionService,
    ) {
    }

    /**
     * @param array<int, array<string, mixed>> $glosas
     *
     * @return array{count:int,total_denied:float,affected_guides:array<int,string>}
     */
    public function process(TissReturn $return, array $glosas): array
    {
        if ($glosas === []) {
            return [
                'count'           => 0,
                'total_denied'    => 0.0,
                'affected_guides' => [],
            ];
        }

        return DB::transaction(function () use ($return, $glosas): array {
            $return->loadMissing(['protocol.batch.guides.items']);

            $batch  = $return->protocol?->batch;
            $guides = $batch?->guides ?? collect();

            $count            = 0;
            $totalDenied      = 0.0;
            $affectedGuideIds = [];

            foreach ($glosas as $glosaData) {
                $guide     = $this->resolveGuide($guides, $glosaData);
                $guideItem = $guide ? $this->resolveGuideItem($guide, $glosaData) : null;

                $amount = (float) ($glosaData['amount'] ?? 0);

                $glosa = TissGlosa::query()->updateOrCreate(
                    [
                        'entity_id'     => $return->entity_id,
                        'return_id'     => $return->id,
                        'protocol_id'   => $return->protocol_id,
                        'guide_id'      => $guide?->id,
                        'guide_item_id' => $guideItem?->id,
                        'glosa_code'    => (string) ($glosaData['code'] ?? 'GLS-SEM-CODIGO'),
                    ],
                    [
                        'operator_id'       => $return->operator_id,
                        'status'            => TissGlosaStatus::Open->value,
                        'glosa_description' => (string) ($glosaData['description'] ?? ''),
                        'amount'            => $amount,
                        'identified_at'     => now()->toDateString(),
                        'metadata'          => [
                            'guide_number_provider' => $glosaData['guide_number_provider'] ?? null,
                            'procedure_code'        => $glosaData['procedure_code'] ?? null,
                            'raw'                   => $glosaData,
                        ],
                    ],
                );

                $count++;
                $totalDenied += $amount;

                if ($guide) {
                    $affectedGuideIds[] = (string) $guide->id;

                    $currentDenied = (float) $guide->denied_amount;
                    $newDenied     = $currentDenied + $amount;

                    $guide->update([
                        'denied_amount' => $newDenied,
                        'paid_amount'   => max(0, (float) $guide->total_amount - $newDenied),
                    ]);
                }

                $this->logStatusTransitionService->record(
                    entityId: (string) $return->entity_id,
                    contextType: 'glosa',
                    contextId: (string) $glosa->id,
                    currentStatus: TissGlosaStatus::Open->value,
                    reason: 'Glosa registrada via XML de retorno.',
                    payload: [
                        'return_id'   => (string) $return->id,
                        'protocol_id' => (string) $return->protocol_id,
                    ],
                );
            }

            return [
                'count'           => $count,
                'total_denied'    => $totalDenied,
                'affected_guides' => array_values(array_unique($affectedGuideIds)),
            ];
        });
    }

    /**
     * @param Collection<int, TissGuide> $guides
     * @param array<string, mixed>       $glosaData
     */
    private function resolveGuide(Collection $guides, array $glosaData): ?TissGuide
    {
        $guideNumber = (string) ($glosaData['guide_number_provider'] ?? '');

        if ($guideNumber !== '') {
            $guide = $guides->first(fn (TissGuide $item): bool => (string) $item->guide_number_provider === $guideNumber);

            if ($guide instanceof TissGuide) {
                return $guide;
            }
        }

        if ($guides->count() === 1) {
            $single = $guides->first();

            return $single instanceof TissGuide ? $single : null;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $glosaData
     */
    private function resolveGuideItem(TissGuide $guide, array $glosaData): ?TissGuideItem
    {
        $procedureCode = (string) ($glosaData['procedure_code'] ?? '');
        $tussCode      = (string) ($glosaData['tuss_code'] ?? '');

        if ($procedureCode !== '') {
            $item = $guide->items->first(fn (TissGuideItem $guideItem): bool => (string) $guideItem->procedure_code === $procedureCode);

            if ($item instanceof TissGuideItem) {
                return $item;
            }
        }

        if ($tussCode !== '') {
            $item = $guide->items->first(fn (TissGuideItem $guideItem): bool => (string) $guideItem->tuss_code === $tussCode);

            if ($item instanceof TissGuideItem) {
                return $item;
            }
        }

        return null;
    }
}
