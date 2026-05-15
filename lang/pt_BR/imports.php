<?php

return [
    'status' => [
        'pending'    => 'Aguardando',
        'processing' => 'Processando',
        'done'       => 'Concluído',
        'failed'     => 'Falhou',
    ],

    'patients' => [
        'title'    => 'Importar Pacientes',
        'subtitle' => 'Importe pacientes em lote a partir de uma planilha CSV.',

        'upload_title' => 'Enviar arquivo CSV',
        'upload_hint'  => 'Suporta arquivos exportados do Feegow, Doctoralia, ProDoctor e qualquer planilha CSV padrão.',
        'drop_here'    => 'Clique ou arraste o arquivo aqui',
        'accepted_formats' => 'Formatos aceitos: .csv ou .txt — separador ; ou , — até 20 MB',
        'start_import' => 'Iniciar Importação',
        'uploading'    => 'Enviando...',
        'max_size'     => 'Máx. 20 MB',
        'processing'   => 'Processando importação...',
        'download_template' => 'Baixar Modelo CSV',
        'download_errors'   => 'Baixar erros',

        'column_guide_title' => 'Mapeamento de colunas',
        'required_columns'   => 'Obrigatórias',
        'optional_columns'   => 'Opcionais',
        'col_name'           => 'Nome completo do paciente',
        'or'                 => 'ou',
        'dedup_hint'         => 'Pacientes existentes (mesmo CPF ou nome+telefone) são pulados automaticamente, sem sobrescrever dados.',

        'history_title'  => 'Histórico de Importações',
        'rows'           => 'linhas',
        'col_file'       => 'Arquivo',
        'col_user'       => 'Enviado por',
        'col_date'       => 'Data',
        'col_status'     => 'Status',
        'col_imported'   => 'Importados',
        'col_skipped'    => 'Pulados',
        'col_errors'     => 'Erros',

        'validation' => [
            'file_required' => 'Selecione um arquivo CSV para importar.',
            'file_mimes'    => 'O arquivo deve ser .csv ou .txt.',
            'file_max'      => 'O arquivo não pode ultrapassar 20 MB.',
            'import_running' => 'Já existe uma importação em andamento. Aguarde a conclusão antes de iniciar outra.',
        ],

        'preview_title'       => 'Confirmar Importação',
        'preview_subtitle'    => 'Verifique o mapeamento de colunas antes de iniciar. Os dados abaixo são os primeiros registros do seu arquivo.',
        'preview_mapped'      => 'Colunas reconhecidas',
        'preview_unmapped'    => 'Colunas ignoradas',
        'preview_unmapped_hint' => 'Essas colunas não serão importadas pois não correspondem a nenhum campo do sistema.',
        'preview_sample'      => 'Amostra de dados',
        'preview_rows'        => ':count linhas detectadas',
        'preview_required_ok' => 'Colunas obrigatórias encontradas',
        'preview_missing'     => 'Colunas obrigatórias ausentes',
        'confirm_import'      => 'Confirmar e Importar',
        'cancel_import'       => 'Cancelar',
        'cancelled'           => 'Importação cancelada.',

        'plan_limit_title'   => 'Cota do plano',
        'plan_unlimited'     => 'ilimitado',
        'plan_used'          => ':used de :limit pacientes usados',
        'plan_remaining'     => ':remaining vagas disponíveis',
        'plan_full'          => 'Limite de pacientes atingido. Faça upgrade do plano para importar mais.',

        'result_title'       => 'Importação concluída',
        'result_imported'    => 'importados',
        'result_skipped'     => 'já existiam',
        'result_errors'      => 'com erro',
        'result_view_patients' => 'Ver Pacientes',
        'result_new_import'  => 'Nova Importação',
        'result_download_errors' => 'Baixar relatório de erros',
        'result_aborted'     => 'Importação interrompida:',
    ],
];
