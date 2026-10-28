<?php

declare(strict_types=1);

/*
 * Relatório de primeira consulta (Fase 1 / B3) — tela
 * Panel/Reports/FirstConsultations e exportação.
 */
return [
    'title'            => 'Relatório de primeira consulta',
    'subtitle'         => 'Pacientes cuja primeira consulta atendida na clínica foi no período',
    'subtitle_mine'    => 'Pacientes cuja primeira consulta atendida na clínica foi com você no período',
    'from_label'       => 'De',
    'until_label'      => 'Até',
    'doctor_label'     => 'Médico',
    'doctor_all'       => 'Todos os médicos',
    'filter'           => 'Filtrar',
    'export_csv'       => 'Exportar CSV',
    'export_xlsx'      => 'Exportar Excel',
    'period'           => 'Período: :from a :until',
    'total'            => 'Novos pacientes',
    'by_doctor'        => 'Por médico',
    'col_date'         => 'Data',
    'col_patient'      => 'Paciente',
    'col_patient_code' => 'Código',
    'col_doctor'       => 'Médico',
    'col_covenant'     => 'Convênio',
    'col_type'         => 'Tipo',
    'no_covenant'      => 'Particular / sem convênio',
    'urgent_badge'     => 'Pronto atendimento',
    'empty'            => 'Nenhuma primeira consulta no período.',
    'how_it_counts'    => 'Conta a primeira consulta com situação "Atendido" de cada paciente na clínica (inclui histórico importado e pronto atendimento).',
    'period_too_long'  => 'Escolha um período de até :days dias.',
    'sheet_name'       => 'Primeira consulta',
    'datetime_format'  => 'd/m/Y H:i',
];
