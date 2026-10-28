<?php

declare(strict_types=1);

/*
 * Segurança clínica — Fase 2 / Bloco A: alergias, medicações em uso, alertas
 * de risco ocular e checagem de alergia na receita. Enviado às telas do painel
 * como `t_clinical` (HandleInertiaRequests). As regras clínicas (princípios
 * ativos, classes, fontes) ficam em config/clinical_safety.php.
 */
return [
    'categories' => [
        'medication'   => 'Medicamento',
        'eye_drop'     => 'Colírio',
        'anesthetic'   => 'Anestésico',
        'antiseptic'   => 'Antisséptico / iodo',
        'latex'        => 'Látex',
        'preservative' => 'Conservante (ex.: cloreto de benzalcônio)',
        'food'         => 'Alimento',
        'other'        => 'Outro',
    ],

    'severities' => [
        'mild'     => 'Leve',
        'moderate' => 'Moderada',
        'severe'   => 'Grave / anafilaxia',
    ],
    'severity_unknown' => 'Gravidade não informada',

    'allergy_statuses' => [
        'reported'  => 'Informada pelo paciente',
        'confirmed' => 'Confirmada pelo médico',
        'discarded' => 'Descartada',
    ],

    'medication_statuses' => [
        'active'    => 'Em uso',
        'suspended' => 'Suspensa',
        'discarded' => 'Descartada',
    ],

    'medication_sources' => [
        'patient'               => 'Informada pelo paciente',
        'external_prescription' => 'Receita de outro profissional',
        'clinic'                => 'Registrada pelo médico',
    ],

    // Alerta fixo (cabeçalho do prontuário, Agenda, pronto atendimento, receituário).
    'banner' => [
        'label'          => 'Segurança clínica do paciente',
        'allergy_alert'  => 'ALERGIA',
        'allergies'      => 'Alergias',
        'no_record'      => 'Sem registro de alergias',
        'no_record_hint' => 'Ninguém perguntou ainda — pergunte ao paciente e registre.',
        'denied'         => 'Nega alergias',
        'denied_detail'  => 'Nega alergias — registrado por :name em :date',
        'risks'          => 'Atenção às medicações em uso',
        'review'         => 'Revisar alergias e medicações',
        'source'         => 'Fonte',
        'free_text'      => 'citado no texto livre',
        'unconfirmed'    => 'não confirmada',
        'severe'         => 'grave',
        'show_sources'   => 'Ver fontes',
        'hide_sources'   => 'Ocultar fontes',
    ],

    'allergies' => [
        'title'             => 'Alergias',
        'add'               => 'Adicionar alergia',
        'category'          => 'Categoria',
        'substance'         => 'Substância',
        'substance_ph'      => 'Ex.: dipirona, penicilina, látex, cloreto de benzalcônio',
        'active_ingredient' => 'Princípio ativo',
        'reaction'          => 'Reação',
        'reaction_ph'       => 'Ex.: urticária, falta de ar, edema de glote',
        'severity'          => 'Gravidade',
        'notes'             => 'Observação',
        'status'            => 'Situação',
        'save'              => 'Salvar',
        'cancel'            => 'Cancelar',
        'edit'              => 'Corrigir',
        'confirm'           => 'Confirmar',
        'discard'           => 'Descartar',
        'discard_title'     => 'Descartar alergia',
        'discard_reason'    => 'Motivo do descarte (opcional)',
        'discard_confirm'   => 'Descartar alergia',
        'deny'              => 'Paciente nega alergias',
        'undeny'            => 'Desfazer "nega alergias"',
        'registered'        => 'Registrada por :name em :date',
        'confirmed'         => 'Confirmada por :name em :date',
        'discarded'         => 'Descartada por :name em :date',
        'history'           => 'Histórico (descartadas)',
        'empty'             => 'Nenhuma alergia ativa.',
        'reception_hint'    => 'Você registra como "informada pelo paciente"; o médico confirma ou descarta no atendimento.',
        'doctor_only'       => 'Só o médico com cadastro ativo confirma, corrige ou descarta.',
        'duplicate'         => 'Esta alergia já está registrada para o paciente — corrija a existente.',
        'discarded_locked'  => 'Alergia descartada não pode ser alterada — registre de novo, se for o caso.',
        'deny_with_active'  => 'O paciente tem alergia ativa: descarte-a antes de marcar "nega alergias".',
        'register_as'       => 'Registrar como',
        'saved_now'         => 'Gravado na hora (não depende de salvar o cadastro).',
        'pending_hint'      => 'Vai junto com o cadastro do paciente, como informada pelo paciente.',
        'pending_remove'    => 'Remover :name',
        'sensitive'         => 'Dado de saúde sensível (LGPD) — tudo fica registrado na auditoria.',
        'load_error'        => 'Não foi possível carregar as alergias e medicações.',
        'save_error'        => 'Não foi possível salvar. Tente de novo.',
        // Item do catálogo CMED (opcional) — o princípio ativo do catálogo
        // entra na checagem da receita (pega outras marcas da substância).
        'catalog_label'   => 'Item do catálogo (opcional)',
        'catalog_ph'      => 'Buscar no catálogo (CMED): nome comercial ou princípio ativo',
        'catalog_hint'    => 'Vincular o item do catálogo leva o princípio ativo para a checagem da receita (outras marcas da mesma substância também alertam).',
        'catalog_results' => 'Itens do catálogo',
        'catalog_empty'   => 'Nenhum item do catálogo encontrado — a substância digitada continua valendo.',
        'catalog_linked'  => 'Vinculada ao catálogo',
        'catalog_unlink'  => 'Desvincular do catálogo',
        'ingredient_line' => 'Princípio ativo: :name',
        'from_catalog'    => 'do catálogo',
        // Cadastro rápido (lista de espera e pronto atendimento).
        'quick_label'       => 'Alergias (opcional)',
        'quick_ph'          => 'Ex.: dipirona, penicilina, látex',
        'quick_hint'        => 'Separe por vírgula ou ponto e vírgula. Entram como "informadas pelo paciente"; o médico confirma no atendimento. Em branco = "sem registro de alergias".',
        'quick_deny'        => 'Paciente nega alergias',
        'quick_deny_locked' => 'Apague as alergias digitadas para marcar "nega alergias".',
        'quick_conflict'    => 'Informe as alergias ou marque "nega alergias" — não os dois.',
        'quick_too_many'    => 'No cadastro rápido, informe até :max alergias (as demais, no cadastro completo).',
        'quick_item_length' => 'Cada alergia deve ter entre :min e :max caracteres (":item").',
    ],

    'medications' => [
        'title'             => 'Medicações em uso',
        'add'               => 'Adicionar medicação',
        'name'              => 'Medicamento',
        'name_ph'           => 'Buscar no catálogo (CMED) ou digitar o nome',
        'active_ingredient' => 'Princípio ativo',
        'posology'          => 'Posologia / observação',
        'started_on'        => 'Início',
        'ended_on'          => 'Fim',
        'source'            => 'Fonte',
        'confirm'           => 'Confirmar',
        'suspend'           => 'Suspender',
        'reactivate'        => 'Reativar',
        'discard'           => 'Descartar',
        'manage'            => 'Editar lista',
        'edit'              => 'Corrigir',
        'save'              => 'Salvar',
        'cancel'            => 'Cancelar',
        'unconfirmed'       => 'Não confirmada pelo médico',
        'empty'             => 'Nenhuma medicação na lista.',
        'history'           => 'Suspensas e descartadas',
        'doctor_only'       => 'Só o médico com cadastro ativo confirma, suspende ou descarta.',
        'duplicate'         => 'Esta medicação já está na lista do paciente.',
        'discarded_locked'  => 'Medicação descartada não pode ser alterada.',
        'free_text'         => 'Observações sobre medicações (texto livre desta consulta)',
        'free_text_hint'    => 'A lista acima é do paciente e vale para as próximas consultas; ao salvar, o prontuário guarda um retrato dela.',
        'catalog'           => 'Catálogo',
        'free_name'         => 'Nome livre',
        'use_typed'         => 'Usar ":name" (nome livre)',
        'searching'         => 'Buscando…',
        'since'             => 'desde :date',
        'until'             => 'até :date',
    ],

    'risk' => [
        'title'           => 'Alertas de risco ocular',
        'free_text_label' => 'texto livre da consulta de :date',
        // Medicação suspensa que segue alertando (risco persiste após parar).
        'suspended_label' => ':name (suspensa em :date)',
    ],

    // Checagem de alergia na receita (A4).
    'prescription' => [
        'conflict_message' => 'A receita bate com alergia ativa do paciente. Para seguir, informe o motivo.',
        'reason_required'  => 'Informe o motivo para emitir mesmo assim (mínimo de 5 caracteres).',
        'modal_title'      => 'Alergia do paciente na receita',
        'modal_intro'      => 'O que você está prescrevendo bate com alergia registrada:',
        'reason'           => 'Motivo para prescrever mesmo assim',
        'reason_ph'        => 'Ex.: alergia descartada em teste; formulação sem conservante',
        'proceed'          => 'Prescrever mesmo assim',
        'choose_other'     => 'Voltar e escolher outro',
        'match_ingredient' => 'mesmo princípio ativo',
        'match_class'      => 'mesma classe',
        'match_text'       => 'citado no texto da receita',
        'match_brand'      => 'nome comercial no texto',
        'match_eye_drops'  => 'colírio (pode conter o conservante)',
        'matched'          => 'Na receita',
        'reaction'         => 'Reação',
        'audit_note'       => 'O motivo fica registrado no documento e na auditoria.',
        'item_warning'     => 'Alergia registrada',
        'item_reason'      => 'Motivo (obrigatório para adicionar)',
    ],

    // Retrato gravado no prontuário + PDF + histórico da consulta.
    'snapshot' => [
        'allergies'         => 'Alergias (no momento da consulta)',
        'medications'       => 'Medicações em uso (no momento da consulta)',
        'none_medications'  => 'Nenhuma na lista estruturada.',
        'allergies_current' => 'cadastro atual — prontuário anterior ao registro estruturado de alergias',
        'unconfirmed'       => 'informada',
    ],
];
