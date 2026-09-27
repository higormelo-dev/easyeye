<?php

/*
 * Códigos sequenciais de registros (SDL-, PAC-, EIQ-) e identificadores
 * recebidos pela API de integradores.
 */
return [
    'ambiguous_identifier' => [
        'schedule'  => 'O identificador informado corresponde a mais de um agendamento. Envie o UUID ou o código completo (SDL-XXXXXXXXXX).',
        'patient'   => 'O identificador informado corresponde a mais de um paciente. Envie o UUID ou o código completo (PAC-XXXXXXXXXX).',
        'equipment' => 'O identificador informado corresponde a mais de um equipamento deste integrador. Envie o UUID do equipamento.',
    ],

    'unexpected_unique_conflict' => 'Não foi possível gravar o agendamento por um conflito de dados inesperado. Importe a linha novamente; se o erro persistir, contate o suporte.',
];
