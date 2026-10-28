<?php

return [
    /*
     * Dias que o CSV original e o CSV de erros de uma importação de pacientes ficam no disco (ambos têm dado de
     * paciente — LGPD). Depois disso o comando imports:prune-patient-files os apaga e o histórico fica sem arquivos.
     */
    'retention_days' => (int) env('PATIENT_IMPORT_RETENTION_DAYS', 30),

    /*
     * Limite de nomes de convênio gravados em `preview.covenant_summary` (JSON do import). O relatório completo sai do
     * arquivo sob demanda; acima disso o resumo vem com `truncated = true`.
     */
    'max_covenant_summary_items' => 500,

    /*
     * Teto de nomes de convênio DISTINTOS coletados por passagem no arquivo (prévia, relatório e processamento). Um CSV de
     * até 20 MB pode ter centenas de milhares de nomes distintos (coluna errada, texto livre) e a coleta guardaria todos
     * em memória antes do corte de `max_covenant_summary_items`. Acima do teto para de coletar NOVOS nomes (os já
     * coletados seguem contando linhas) e o resumo vem com `truncated` e `names_capped` = true.
     */
    'max_covenant_names' => 1000,
];
