<?php

declare(strict_types=1);

namespace App\Domains\Tiss\Services;

use App\Domains\Tiss\Models\{TissEntityOperatorContract, TissVersion};
use RuntimeException;

class ResolveTissVersionService
{
    public function resolve(
        ?string $explicitVersionCode = null,
        ?TissEntityOperatorContract $contract = null,
    ): TissVersion {
        $explicitVersionCode ??= (string) config('tiss.default_version');

        if ($explicitVersionCode) {
            $version = TissVersion::query()
                ->where('code', $explicitVersionCode)
                ->where('active', true)
                ->first();

            if ($version) {
                return $version;
            }
        }

        if ($contract?->preferredVersion && $contract->preferredVersion->active) {
            return $contract->preferredVersion;
        }

        $version = TissVersion::query()
            ->where('active', true)
            ->orderByDesc('effective_from')
            ->orderByDesc('code')
            ->first();

        if (! $version) {
            throw new RuntimeException('Nenhuma versão TISS ativa foi configurada.');
        }

        return $version;
    }
}
