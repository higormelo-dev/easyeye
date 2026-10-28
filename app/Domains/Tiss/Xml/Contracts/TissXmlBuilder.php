<?php

declare(strict_types=1);

namespace App\Domains\Tiss\Xml\Contracts;

use App\Domains\Tiss\Models\TissBatch;

interface TissXmlBuilder
{
    public function buildBatch(TissBatch $batch): string;
}
