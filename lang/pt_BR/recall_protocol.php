<?php

/*
 * Protocolos de retorno (recall) — Fase 6. Rótulos de estado/tarefa/exame e as
 * mensagens de validação do conteúdo (ProtocolContentSchema). O conteúdo clínico
 * em si (regras, justificativa, referências) NÃO é traduzido: é o texto exato que
 * o Revisor Clínico aprovou (ADR 0002).
 */

return [
    'states' => [
        'draft'      => 'Rascunho',
        'in_review'  => 'Em revisão',
        'validated'  => 'Validada',
        'released'   => 'Liberada',
        'superseded' => 'Substituída',
        'suspended'  => 'Suspensa',
        'declined'   => 'Recusada pelo Revisor',
        'withdrawn'  => 'Retirada',
        'retired'    => 'Aposentada',
    ],

    'task_kinds' => [
        'consultation'     => 'Consulta',
        'exam'             => 'Exame',
        'treatment_course' => 'Curso de tratamento',
    ],

    'exam_keys' => [
        'visual_field'       => 'Campo visual (campimetria)',
        'oct_optic_nerve'    => 'OCT de nervo óptico',
        'oct_macula'         => 'OCT de mácula',
        'retinography'       => 'Retinografia',
        'corneal_topography' => 'Topografia de córnea',
    ],

    'units' => [
        'days'   => 'dias',
        'weeks'  => 'semanas',
        'months' => 'meses',
    ],

    'units_one' => [
        'days'   => 'dia',
        'weeks'  => 'semana',
        'months' => 'mês',
    ],

    'evidence' => [
        'high'   => 'Evidência direta',
        'medium' => 'Evidência indireta',
        'low'    => 'Opinião / consenso',
    ],

    'errors' => [
        'not_an_object'              => 'Esperava um objeto.',
        'not_a_list'                 => 'Esperava uma lista.',
        'unknown_field'              => 'Campo desconhecido: :field.',
        'required'                   => 'Campo obrigatório.',
        'must_be_string'             => 'Deve ser um texto.',
        'must_be_integer'            => 'Deve ser um número inteiro.',
        'must_be_boolean'            => 'Deve ser sim ou não.',
        'invalid_utf8'               => 'Texto com caracteres inválidos.',
        'control_characters'         => 'Texto com caracteres de controle não permitidos.',
        'too_long'                   => 'Máximo de :max caracteres.',
        'out_of_range'               => 'Deve estar entre :min e :max.',
        'invalid_choice'             => 'Valor não permitido: :value.',
        'invalid_key'                => 'Chave inválida: use letras minúsculas, números e _ (2 a 40 caracteres, começando por letra).',
        'invalid_cid'                => 'CID inválido: :value (use o código sem ponto, ex.: H401).',
        'cid_conflict'               => 'O CID :value está em mais de uma lista (forte, fraco, exclusão).',
        'invalid_medication'         => 'Medicação desconhecida: :value.',
        'duplicate'                  => 'Valor repetido: :value.',
        'too_many'                   => 'Máximo de :max itens.',
        'too_few'                    => 'Informe ao menos :min item.',
        'exam_key_required'          => 'Informe o exame desta tarefa.',
        'exam_key_not_allowed'       => 'Só tarefas do tipo exame têm exame definido.',
        'interval_order'             => 'O mínimo deve ser menor ou igual ao padrão, e o padrão menor ou igual ao máximo.',
        'lost_not_after_grace'       => '"Perdido de vista" deve vir depois da tolerância.',
        'no_required_task'           => 'Ao menos uma tarefa deve ser obrigatória.',
        'no_entry_criteria'          => 'Informe ao menos um CID forte ou uma medicação de entrada.',
        'change_note_required'       => 'Informe o que mudou em relação à versão anterior.',
        'schema_version_unsupported' => 'Versão de esquema não suportada.',
    ],
];
