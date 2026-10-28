<?php

declare(strict_types=1);

namespace App\Services;

class RasterMemoryBudget
{
    /** Limit new decoder surfaces separately from the worker's existing heap. */
    public function available(?string $phpLimit = null, ?int $currentUsage = null): int
    {
        $limit          = trim($phpLimit ?? (string) ini_get('memory_limit'));
        $usage          = $currentUsage ?? memory_get_usage(true);
        $operationLimit = 268435456;

        if ($limit === '-1') {
            return $operationLimit;
        }

        $bytes = (int) $limit;
        $bytes *= match (strtolower(substr($limit, -1))) {
            'g' => 1073741824, 'm' => 1048576, 'k' => 1024, default => 1,
        };

        return max(0, min($operationLimit, $bytes - $usage - 16777216));
    }
}
