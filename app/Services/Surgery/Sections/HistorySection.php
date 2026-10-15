<?php

declare(strict_types=1);

namespace App\Services\Surgery\Sections;

use App\Enums\Surgery\SurgicalCaseStatus;
use App\Models\Surgery\SurgicalCase;
use App\Models\User;

/** Aba "Histórico": cada mudança de estado do caso, quem fez e por quê. */
final class HistorySection implements SurgeryShowSection
{
    public function key(): string
    {
        return 'history';
    }

    public function order(): int
    {
        return 90;
    }

    public function label(): string
    {
        return (string) __('surgery.tabs.history');
    }

    public function visible(SurgicalCase $case, array $can): bool
    {
        return true;
    }

    public function props(SurgicalCase $case, array $can): array
    {
        $entries = array_values((array) $case->status_history);
        $users   = User::query()->whereIn('id', array_filter(array_column($entries, 'by')))->pluck('name', 'id');

        return [
            'entries' => array_map(fn (array $e) => [
                'from'   => isset($e['from']) ? SurgicalCaseStatus::tryFrom($e['from'])?->label() : null,
                'to'     => SurgicalCaseStatus::tryFrom($e['to'])?->label() ?? $e['to'],
                'at'     => $e['at'] ?? null,
                'by'     => $users[$e['by'] ?? ''] ?? null,
                'reason' => $e['reason'] ?? null,
            ], array_reverse($entries)),
        ];
    }
}
