<?php
// Remove os dados de demonstração do manual do financeiro.
App\Models\FinancialCashEntry::withTrashed()->where('notes', '[demo-manual]')->forceDelete();
App\Domains\Tiss\Models\TissGlosa::withTrashed()
    ->where('glosa_description', 'like', '%consulta 12/08%')->forceDelete();
echo 'docsfin-clean:ok;';
