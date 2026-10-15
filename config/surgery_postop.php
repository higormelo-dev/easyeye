<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Pós-operatório da cirurgia (Fase 4 / M5)
|--------------------------------------------------------------------------
*/
return [
    /*
    | Retornos planejados, em dias a partir da cirurgia. O padrão da
    | oftalmologia é D1, D7 e D30. Só estes três são marcos de avaliação
    | (PostOpMilestone); mudar os dias NÃO muda o nome do marco.
    */
    'offsets' => [
        'd1'  => 1,
        'd7'  => 7,
        'd30' => 30,
    ],

    /* Dias seguintes ao alvo que a busca percorre atrás de um horário livre do cirurgião. */
    'search_days' => 21,

    /* Tentativas de gravar o horário quando outro usuário o toma no meio-tempo. */
    'slot_attempts' => 25,

    /* Minutos até um retorno "em andamento" de um worker morto poder ser reivindicado de novo. */
    'claim_lease_minutes' => 5,

    /* Retorno urgente por complicação grave: dias de busca (0 = só hoje/amanhã). */
    'urgent_search_days' => 2,

    /* PIO (mmHg) a partir da qual a avaliação mostra aviso de hipertensão ocular. */
    'iop_attention' => 30,

    /* Indicadores: abaixo disto a amostra é sinalizada como pequena. */
    'small_sample' => 10,
];
