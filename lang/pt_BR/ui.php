<?php

declare(strict_types=1);

/*
 * Textos de interface dos componentes compartilhados do painel (modais,
 * menus). Enviados em toda página como `t_ui` (HandleInertiaRequests).
 */
return [
    'close'   => 'Fechar',
    'loading' => 'Carregando...',

    // Busca de diagnóstico CID-10 (Components/Panel/Cid10Picker.vue)
    'cid10' => [
        'placeholder'    => 'Buscar por código ou diagnóstico (ex: H40.1, glaucoma)…',
        'search_label'   => 'Buscar diagnóstico (CID-10)',
        'suggestions'    => 'Sugestões de diagnóstico',
        'most_used'      => 'Mais usados',
        'custom'         => 'Customizado',
        'create'         => "Cadastrar novo diagnóstico: ':term'",
        'primary'        => 'Diagnóstico principal',
        'mark_primary'   => 'Marcar como diagnóstico principal',
        'primary_toggle' => 'Diagnóstico principal: :item',
        'remove'         => 'Remover :item',
        'searching'      => 'Buscando…',
        'results_one'    => ':count resultado',
        'results_other'  => ':count resultados',
        'no_results'     => 'Nenhum diagnóstico encontrado.',
    ],
];
