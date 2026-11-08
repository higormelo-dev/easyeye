<?php

// Pedidos de titulares (LGPD arts. 18–19) — Panel/Compliance/LgpdRequests.
return [
    'page_title'            => 'Pedidos de titulares (LGPD)',
    'subtitle'              => 'Registre pedidos recebidos por e-mail, telefone ou balcão e responda dentro do prazo. Confirmação e acesso completos: até 15 dias do pedido (LGPD art. 19, II).',
    'breadcrumb_dashboard'  => 'Dashboard',
    'breadcrumb_compliance' => 'Compliance & Auditoria',
    'btn_new'               => 'Registrar pedido',
    'open_count'            => 'Em aberto: :count',
    'overdue_count'         => 'Vencidos: :count',
    'empty'                 => 'Nenhum pedido registrado. Pedidos do portal do paciente aparecem aqui automaticamente.',
    'overdue'               => 'Vencido',
    'no_patient'            => 'Sem paciente vinculado',
    'responded'             => 'Respondido em :date por :name',
    'showing_from'          => 'Exibindo',
    'showing_of'            => 'de',
    'showing_suffix'        => 'pedidos',
    'pagination_label'      => 'Paginação dos pedidos',
    'previous'              => 'Anterior',
    'next'                  => 'Próxima',

    'columns' => [
        'requested_at' => 'Recebido em',
        'requester'    => 'Titular',
        'type'         => 'Direito',
        'deadline'     => 'Prazo',
        'status'       => 'Situação',
        'actions'      => 'Ações',
    ],

    'actions' => [
        'start'    => 'Iniciar análise',
        'complete' => 'Concluir',
        'reject'   => 'Recusar',
        'export'   => 'Baixar dados do paciente',
    ],

    'form' => [
        'title'                   => 'Registrar pedido de titular',
        'requester_name'          => 'Nome de quem pediu',
        'requester_email'         => 'E-mail para resposta',
        'requester_document'      => 'CPF',
        'requester_document_hint' => 'Opcional. Ajuda a confirmar a identidade e localizar o cadastro.',
        'request_type'            => 'Direito solicitado',
        'requested_at'            => 'Recebido em',
        'requested_at_hint'       => 'O prazo conta da data em que o pedido chegou.',
        'patient'                 => 'Paciente',
        'patient_placeholder'     => 'Buscar por nome, CPF ou código',
        'patient_hint'            => 'Opcional. Vincule para baixar os dados do paciente.',
        'description'             => 'O que foi pedido',
        'description_hint'        => 'Resuma o pedido e por onde chegou. Não cole prontuário nem senhas.',
        'optional'                => '(opcional)',
        'save'                    => 'Registrar',
        'cancel'                  => 'Cancelar',
        'discard_confirm'         => 'Descartar o que foi preenchido?',
    ],

    'respond' => [
        'complete_title' => 'Concluir pedido',
        'reject_title'   => 'Recusar pedido',
        'complete_label' => 'Resposta dada ao titular',
        'reject_label'   => 'Justificativa da recusa',
        'complete_hint'  => 'O que foi feito e como os dados foram entregues. Fica registrado como prova do atendimento.',
        'reject_hint'    => 'Ex.: identidade não confirmada; dado com guarda obrigatória por lei (indique qual).',
        'confirm'        => 'Confirmar',
        'cancel'         => 'Cancelar',
    ],

    'types' => [
        'access'         => 'Confirmação e acesso (art. 18, I e II)',
        'correction'     => 'Correção (art. 18, III)',
        'anonymization'  => 'Anonimização, bloqueio ou eliminação de dados desnecessários (art. 18, IV)',
        'portability'    => 'Portabilidade (art. 18, V)',
        'deletion'       => 'Eliminação de dados tratados com consentimento (art. 18, VI)',
        'information'    => 'Informação sobre compartilhamento (art. 18, VII)',
        'revoke_consent' => 'Revogação do consentimento (art. 18, IX)',
        'opposition'     => 'Oposição ao tratamento (art. 18, § 2º)',
    ],

    'statuses' => [
        'pending'     => 'Pendente',
        'in_progress' => 'Em análise',
        'completed'   => 'Concluído',
        'rejected'    => 'Recusado',
        'cancelled'   => 'Cancelado',
    ],

    'attributes' => [
        'requester_name'     => 'nome de quem pediu',
        'requester_email'    => 'e-mail para resposta',
        'requester_document' => 'CPF',
        'request_type'       => 'direito solicitado',
        'requested_at'       => 'data de recebimento',
        'patient_id'         => 'paciente',
        'description'        => 'descrição do pedido',
        'action'             => 'ação',
        'text'               => 'texto da resposta',
    ],

    'flash' => [
        'created'  => 'Pedido registrado. Prazo: :date.',
        'start'    => 'Pedido em análise.',
        'complete' => 'Pedido concluído.',
        'reject'   => 'Pedido recusado.',
    ],

    'errors' => [
        'not_open'    => 'Este pedido já foi encerrado.',
        'not_pending' => 'Só um pedido pendente pode entrar em análise.',
    ],

    'compliance_card' => [
        'title' => 'Pedidos de titulares (LGPD)',
        'text'  => 'Acesso, correção, eliminação e demais direitos do art. 18, com prazo e resposta registrados.',
        'link'  => 'Abrir pedidos',
    ],
];
