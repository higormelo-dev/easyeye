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

    // Sugestão de posologia por IA (botão e lote). Inclui o raciocínio dos
    // modelos gpt-5* — abaixo de ~1500 eles podem esgotar pensando e não responder.
    'posology_ai' => [
        'max_output_tokens' => (int) env('MEDICINE_POSOLOGY_AI_MAX_OUTPUT_TOKENS', 2000),
    ],

    /*
    | Posologia sugerida gerada por IA em lote (Manager → Medicamentos):
    | uma chamada de IA por grupo de itens iguais (princípio ativo +
    | concentração + forma), em fila, com progresso por WebSocket.
    */
    'posology_batch' => [
        // Teto de chamadas (grupos) por lote; o restante fica para o próximo.
        'max_groups' => (int) env('MEDICINE_POSOLOGY_BATCH_MAX_GROUPS', 200),

        // Pausa entre chamadas (throttle simples para o limite do provedor).
        'delay_ms' => (int) env('MEDICINE_POSOLOGY_BATCH_DELAY_MS', 500),

        // Novas tentativas de um grupo em erro transitório (demora/sobrecarga/429).
        'retries'               => (int) env('MEDICINE_POSOLOGY_BATCH_RETRIES', 2),
        'retry_backoff_seconds' => [5, 15],

        // Falhas seguidas que encerram o lote (provedor fora do ar: não gasta à toa).
        'max_consecutive_failures' => (int) env('MEDICINE_POSOLOGY_BATCH_MAX_CONSECUTIVE_FAILURES', 5),

        // Cada job processa grupos por no máximo isto e agenda a continuação
        // (fica abaixo do retry_after de 90 s da fila).
        'job_time_budget_seconds' => (int) env('MEDICINE_POSOLOGY_BATCH_JOB_BUDGET', 45),
    ],
];
