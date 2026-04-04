<?php

declare(strict_types = 1);

namespace App\Services\Financial;

use App\Models\FinancialCashEntry;
use Illuminate\Support\Facades\DB;

class CashFlowService
{
    public function create(array $data): FinancialCashEntry
    {
        return DB::transaction(function () use ($data): FinancialCashEntry {
            $data['entity_id'] = (string) session('selected_entity_id');
            $data['active'] = $data['active'] ?? true;

            return FinancialCashEntry::query()->create($data);
        });
    }

    public function update(FinancialCashEntry $entry, array $data): FinancialCashEntry
    {
        return DB::transaction(function () use ($entry, $data): FinancialCashEntry {
            $entry->update($data);

            return $entry->fresh();
        });
    }

    public function summary(string $entityId, string $from, string $to): array
    {
        $base = FinancialCashEntry::query()
            ->where('entity_id', $entityId)
            ->whereBetween('entry_date', [$from, $to])
            ->whereNull('deleted_at')
            ->where('status', '!=', 'cancelled');

        $income = (float) (clone $base)->where('type', 'income')->sum('amount');
        $expense = (float) (clone $base)->where('type', 'expense')->sum('amount');

        return [
            'income' => $income,
            'expense' => $expense,
            'balance' => $income - $expense,
        ];
    }
}

