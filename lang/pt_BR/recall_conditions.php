<?php

/*
 * Condições crônicas do paciente na gaveta do cadastro (Fase 6): o que o sistema detectou, o que o médico decide e o
 * que cada condição gera de retorno. Só o médico vê. Plural com "a|b" (escolhe pela contagem :count).
 */

return [
    'title'   => 'Condições crônicas',
    'empty'   => 'Nenhuma condição registrada para este paciente.',
    'history' => 'Histórico (:count)',

    'eye' => [
        'both'  => 'Ambos os olhos (AO)',
        'right' => 'Olho direito (OD)',
        'left'  => 'Olho esquerdo (OE)',
    ],

    'started_on'      => 'Início do acompanhamento: :date',
    'started_unknown' => 'Início do acompanhamento não informado',

    'status_hint' => [
        'suggested' => 'Sugerida pelo sistema: só gera retorno depois que você confirmar.',
    ],

    'decided' => [
        'rejected'   => 'Descartada em :date',
        'discharged' => 'Alta em :date',
    ],

    'discharge_reason' => 'Motivo: :reason',

    'evidence' => [
        'cid_signed'             => 'CID :code em prontuário assinado (desde :date)',
        'cid_unsigned'           => 'CID :code em prontuário ainda não assinado (desde :date)',
        'medication'             => 'Medicação em uso: :name (desde :date)',
        'medication_unconfirmed' => 'Medicação informada, ainda não confirmada: :name (desde :date)',
        'manual'                 => 'Registrada pelo médico',
        'unknown_date'           => 'data desconhecida',
    ],

    'medications' => [
        'hydroxychloroquine' => 'hidroxicloroquina',
        'chloroquine'        => 'cloroquina',
    ],

    'next' => [
        'title'   => 'Próximos retornos',
        'due'     => 'vence em :date',
        'missing' => 'falta informar o início do acompanhamento',
    ],

    'protocol_inactive' => 'O protocolo não está ativo na clínica: esta condição não gera retornos.',

    'actions' => [
        'confirm'   => 'Confirmar',
        'reject'    => 'Descartar',
        'discharge' => 'Dar alta',
        'edit'      => 'Editar',
        'add'       => 'Registrar condição',
        'save'      => 'Salvar',
        'cancel'    => 'Cancelar',
        'saving'    => 'Salvando…',
    ],

    'form' => [
        'add_title'       => 'Registrar condição',
        'edit_title'      => 'Editar condição',
        'protocol'        => 'Protocolo',
        'protocol_choose' => 'Escolher…',
        'eye'             => 'Olho',
        'started_on'      => 'Início do acompanhamento',
        'started_hint'    => 'Opcional. Sem a data, alguns retornos ficam esperando essa informação.',
        'no_protocols'    => 'A clínica ainda não ativou nenhum protocolo. Ative em Retornos › Configurar retornos.',
        'edit_note'       => 'Ao corrigir, a condição passa a ser sua: o sistema não a altera mais.',
    ],

    'discharge' => [
        'title'       => 'Dar alta',
        'text'        => 'Os retornos desta condição se encerram. O paciente continua cadastrado.',
        'reason'      => 'Motivo da alta',
        'reason_hint' => 'Obrigatório, ao menos :min caracteres.',
        'submit'      => 'Dar alta',
    ],

    'done' => [
        'added'      => 'Condição registrada.',
        'saved'      => 'Condição salva.',
        'confirmed'  => 'Condição confirmada.',
        'rejected'   => 'Condição descartada.',
        'discharged' => 'Alta registrada.',
    ],

    'errors' => [
        'not_found'           => 'Condição ou paciente não encontrado.',
        'protocol_not_active' => 'A clínica não usa este protocolo (ou ele está parado). Ative-o em Configurar retornos.',
        'already_open'        => 'Este paciente já tem esta condição em aberto para este olho.',
        'not_open'            => 'Esta condição não está mais em aberto.',
        'not_suggested'       => 'Esta condição já foi decidida.',
        'not_active'          => 'Só uma condição ativa recebe alta.',
        'invalid_date'        => 'Data de início inválida (não pode ser futura).',
        'reason_required'     => 'Informe o motivo da alta.',
        'generic'             => 'Não foi possível salvar. Tente novamente.',
    ],
];
