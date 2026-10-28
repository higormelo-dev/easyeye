<?php

/*
 * Vocabulário do recall de pacientes (Fase 6): estados do prazo, situação da condição, ciclo de vida do
 * retorno e motivos. Os textos da TELA de Retornos entram nos grupos seguintes (`page`, `settings`...).
 */

return [
    'states' => [
        'upcoming' => 'Em dia',
        'due_soon' => 'A vencer',
        'due'      => 'Vencido',
        'overdue'  => 'Em atraso',
        'lost'     => 'Perdido de vista',
    ],

    'condition_status' => [
        'suggested'  => 'Sugerida',
        'active'     => 'Ativa',
        'discharged' => 'Alta',
        'rejected'   => 'Descartada',
    ],

    'condition_origin' => [
        'cid'        => 'Diagnóstico (CID)',
        'medication' => 'Medicação',
        'manual'     => 'Cadastro manual',
    ],

    'lifecycle' => [
        'open'       => 'Em aberto',
        'snoozed'    => 'Adiado',
        'dismissed'  => 'Dispensado',
        'closed'     => 'Encerrado',
        'needs_info' => 'Falta informação',
    ],

    'closed_reason' => [
        'completed'             => 'Concluído',
        'condition_ended'       => 'Condição encerrada',
        'protocol_deactivated'  => 'Protocolo desativado',
        'task_disabled'         => 'Tarefa desligada',
        'doctor_order_ended'    => 'Sem retorno pedido',
        'doctor_order_disabled' => 'Retorno pedido pelo médico desligado',
        'done_elsewhere'        => 'Feito em outro serviço',
        'patient_removed'       => 'Paciente removido',
        'course_ended'          => 'Tratamento encerrado ou pausado',
    ],

    'task' => [
        'doctor_order' => 'Retorno pedido pelo médico',
        'course'       => 'Próxima aplicação do tratamento',
        'consultation' => 'Consulta de retorno',
        'other'        => 'Acompanhamento',
        'exam'         => 'Exame: :exam',
    ],

    'contact_outcome' => [
        'reached'       => 'Falei com o paciente',
        'will_schedule' => 'Vai agendar',
        'no_answer'     => 'Não atendeu',
        'wrong_number'  => 'Número errado ou fora de serviço',
        'declined'      => 'Não quer voltar',
    ],

    'block_reason' => [
        'deceased'       => 'Óbito',
        'do_not_contact' => 'Pediu para não ser contatado',
        'moved'          => 'Mudou de cidade',
        'no_valid_phone' => 'Sem telefone válido',
        'other'          => 'Outro motivo',
    ],

    'events' => [
        'calculated'       => 'Retorno calculado',
        'cycle_completed'  => 'Paciente voltou: novo ciclo',
        'dates_changed'    => 'Datas recalculadas',
        'closed'           => 'Encerrado',
        'reopened'         => 'Reaberto',
        'suspended'        => 'Regra suspensa pela EasyEye',
        'resumed'          => 'Regra retomada',
        'contacted'        => 'Contato registrado',
        'snoozed'          => 'Adiado',
        'dismissed'        => 'Dispensado',
        'done_elsewhere'   => 'Feito em outro serviço',
        'scheduled'        => 'Agendado',
        'invite_queued'    => 'Convite por WhatsApp enviado para a fila',
        'invite_responded' => 'Paciente respondeu ao convite',
    ],
];
