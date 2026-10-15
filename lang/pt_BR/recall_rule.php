<?php

/*
 * Textos COMPARTILHADOS de exibição de uma regra de protocolo de retorno: usados pelo
 * Manager, pela página do Revisor Clínico e pela tela de adoção da clínica — o mesmo
 * componente (RecallRuleCard) mostra a regra nos três lugares, então o que o Revisor
 * aprova é o que a clínica lê. Plural com "a|b" (escolhe pela contagem :count).
 */

return [
    'entry' => [
        'title'            => 'Quando o paciente entra neste protocolo',
        'strong'           => 'CID que ativa a condição',
        'strong_hint'      => 'O paciente entra como condição ativa quando um destes CID está em prontuário assinado.',
        'weak'             => 'CID que só sugere',
        'weak_hint'        => 'Gera apenas uma sugestão para o médico confirmar; não gera retorno nem convite.',
        'exclude'          => 'CID que não entra',
        'exclude_hint'     => 'Vale o código mais específico: um prefixo mais longo, em outra lista, decide.',
        'medications'      => 'Medicações',
        'medications_hint' => 'O uso confirmado da medicação ativa a condição.',
        'none'             => 'Nenhum',
    ],

    'tasks' => [
        'title'            => 'O que acompanhar e de quanto em quanto tempo',
        'interval_default' => 'Padrão: :value :unit',
        'range'            => 'A clínica pode usar de :min a :max :unit',
        'lead'             => 'Convite :count dia antes do vencimento|Convite :count dias antes do vencimento',
        'lead_zero'        => 'Convite só no vencimento',
        'grace'            => 'Tolerância de :count dia depois do vencimento|Tolerância de :count dias depois do vencimento',
        'grace_zero'       => 'Sem tolerância',
        'lost'             => 'Perdido de vista após :count dia de atraso|Perdido de vista após :count dias de atraso',
        'starts_after'     => 'Começa :count mês depois do início da condição|Começa :count meses depois do início da condição',
        'required'         => 'Obrigatória',
        'optional'         => 'Opcional (a clínica escolhe)',
        'recurs'           => 'Repete',
        'once'             => 'Uma única vez',
        'empty'            => 'Nenhuma tarefa.',
    ],

    'doctor_order' => [
        'title'  => 'Retorno pedido pelo médico',
        'cap'    => 'O retorno que o médico pede no prontuário não passa do intervalo máximo validado; acima disso, o sistema usa o máximo e sinaliza.',
        'no_cap' => 'O retorno que o médico pede no prontuário vale como está, mesmo acima do intervalo máximo validado.',
    ],

    'rationale' => ['title' => 'Justificativa'],

    'change_note' => ['title' => 'O que mudou nesta versão'],

    'references' => [
        'title'        => 'Referências',
        'none'         => 'Nenhuma referência.',
        'year_unknown' => 's/d',
    ],

    'medication_names' => [
        'hydroxychloroquine' => 'Hidroxicloroquina',
        'chloroquine'        => 'Cloroquina',
    ],

    'yes'         => 'Sim',
    'no'          => 'Não',
    'empty_value' => '—',

    'diff' => [
        'title'    => 'O que mudou em relação à versão anterior',
        'none'     => 'Nenhuma diferença nas regras (só pode ter mudado o texto de apresentação).',
        'first'    => 'Primeira versão: não há versão anterior para comparar.',
        'added'    => 'Acrescentado',
        'removed'  => 'Retirado',
        'changed'  => 'Alterado',
        'from_to'  => 'de :from para :to',
        'sections' => [
            'cid.strong'   => 'CID que ativa a condição',
            'cid.weak'     => 'CID que só sugere',
            'cid.exclude'  => 'CID que não entra',
            'medication'   => 'Medicação',
            'task'         => 'Tarefa',
            'doctor_order' => 'Retorno pedido pelo médico',
            'reference'    => 'Referência',
            'rationale'    => 'Justificativa',
            'change_note'  => 'O que mudou nesta versão',
        ],
        'fields' => [
            'kind'                => 'tipo',
            'exam_key'            => 'exame',
            'interval.unit'       => 'unidade do intervalo',
            'interval.default'    => 'intervalo padrão',
            'interval.min'        => 'intervalo mínimo',
            'interval.max'        => 'intervalo máximo',
            'lead_days'           => 'dias de convite antes do vencimento',
            'grace_days'          => 'dias de tolerância',
            'lost_after_days'     => 'dias para perdido de vista',
            'starts_after_months' => 'começa após (meses)',
            'recurs'              => 'repete',
            'required'            => 'obrigatória',
            'cap_to_max'          => 'limitar ao máximo validado',
            'year'                => 'ano',
            'evidence'            => 'tipo de evidência',
            'note'                => 'observação',
        ],
    ],

    'examples' => [
        'title'      => 'Exemplo de datas',
        'intro'      => 'Se o último atendimento foi em :date, o sistema calcula:',
        'intro_hint' => 'É o mesmo cálculo que o sistema faz para o paciente; serve só para conferir se os prazos fazem sentido.',
        'task'       => 'Tarefa',
        'due'        => 'Vence em',
        'invite'     => 'Convite a partir de',
        'grace'      => 'Tolerância até',
        'lost'       => 'Perdido de vista após',
        'starts'     => 'Começa em',
        'date_label' => 'Data do último atendimento',
    ],
];
