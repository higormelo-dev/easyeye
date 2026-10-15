<?php

/*
 * Sugestão do campo "Retorno" do prontuário (Fase 6): o intervalo que a clínica escolheu para a consulta de retorno de
 * cada condição crônica ATIVA do paciente, em dias. Só aparece para o médico; nada é preenchido sozinho.
 */

return [
    'title'   => 'Sugestão dos protocolos de acompanhamento',
    'line'    => ':title (:eye): consulta a cada :interval :unit',
    'days'    => 'cerca de :days dias',
    'use'     => 'Usar :days dias',
    'in_use'  => 'Valor atual',
    'cap'     => 'Retorno acima de :days dias vale :days dias na lista de retornos (máximo validado do protocolo).',
    'cap_hit' => 'O valor passa de :days dias: na lista de retornos ele vale :days dias (máximo validado do protocolo).',
];
