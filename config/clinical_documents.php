<?php

/*
|--------------------------------------------------------------------------
| Documentos do prontuário — Fase 2 / Bloco B
|--------------------------------------------------------------------------
|
| Regras dos documentos emitidos no atendimento (receita, relatório, pedido
| de exames e atestados). Valem no SERVIDOR — a tela só repete os limites.
|
*/

return [
    /*
    | B3 — Data alternativa: a data IMPRESSA pode ir até N dias antes e N dias
    | depois de hoje. A data em que o documento foi emitido de fato continua
    | gravada (created_at + trilha de auditoria) e aparece no histórico.
    */
    'alternative_date' => [
        'days_before' => (int) env('DOCUMENT_DATE_DAYS_BEFORE', 30),
        'days_after'  => (int) env('DOCUMENT_DATE_DAYS_AFTER', 30),
    ],

    /*
    | B2 — Pedido de exames: o teto (20 exames, 5 indicações, 4 CIDs, 5 kits)
    | fica em App\Services\ProcedureSolicitationService (justificativa lá).
    */

    /*
    | B1 — Atestado de comparecimento com dilatação pupilar: "recomenda-se
    | não dirigir ou operar máquinas por N horas" (padrão 4 h; a midríase
    | farmacológica dura de 4 a 8 h, varia com o colírio).
    */
    'dilation_hours' => [
        'default' => 4,
        'min'     => 1,
        'max'     => 24,
    ],

    /* B3 — Modelos pessoais do médico: limite por médico e por tipo. */
    'personal_templates' => [
        'max_per_kind' => 100,
    ],
];
