<?php

use App\Domains\Tiss\Models\TissGlosa;
use App\Models\FinancialCashEntry;

// Remove os dados de demonstração do manual do financeiro.
FinancialCashEntry::withTrashed()->where('notes', '[demo-manual]')->forceDelete();
TissGlosa::withTrashed()
    ->where('glosa_description', 'like', '%consulta 12/08%')->forceDelete();
echo 'docsfin-clean:ok;';
