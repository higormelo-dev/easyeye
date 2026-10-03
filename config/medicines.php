<?php

/*
|--------------------------------------------------------------------------
| Catálogo global de medicamentos (receituário das clínicas)
|--------------------------------------------------------------------------
|
| Fontes oficiais:
| - Lista de preços CMED (PMC): publicada pela Secretaria Executiva da CMED
|   (Anvisa) algumas vezes por mês. O nome do arquivo muda a cada
|   publicação (lista_pmc_AAAAMMDD_*.xlsx) — o link é lido da página oficial.
| - Reserva: a mesma lista em CSV no portal de dados abertos da Anvisa
|   (endereço fixo, mas atualizado com atraso).
| - DADOS_ABERTOS_MEDICAMENTOS.csv: situação dos registros, diária.
| Ver CmedListDownloader e MedicineCatalogSyncService.
*/

return [
    'cmed' => [
        'page_url'          => env('CMED_PRICES_PAGE_URL', 'https://www.gov.br/anvisa/pt-br/assuntos/medicamentos/cmed/precos'),
        'fallback_list_url' => env('CMED_FALLBACK_LIST_URL', 'https://dados.anvisa.gov.br/dados/TA_PRECO_MEDICAMENTO.csv'),
        'open_data_url'     => env('ANVISA_OPEN_DATA_MEDICINES_URL', 'https://dados.anvisa.gov.br/dados/DADOS_ABERTOS_MEDICAMENTOS.csv'),

        // Só baixa destes hosts (links lidos de página externa: nunca segue
        // para outro domínio, nem por redirecionamento).
        'allowed_hosts' => ['www.gov.br', 'dados.anvisa.gov.br'],

        'timeout_seconds' => (int) env('CMED_DOWNLOAD_TIMEOUT', 300),

        // Tetos (a lista PMC tem ~13 MB em XLSX; os dados abertos ~8 MB).
        'page_max_bytes' => 5 * 1024 * 1024,
        'file_max_bytes' => 80 * 1024 * 1024,

        // Verificação semanal automática (só lê dados públicos).
        'sync_enabled' => (bool) env('CMED_SYNC_ENABLED', false),
    ],
];
