<?php

// E-mail ao time do SaaS quando uma fila para de andar (queue:check-stalled).
return [
    'stalled' => [
        'subject'  => '[Alerta fila] :queue parada',
        'greeting' => 'Olá, :name!',
        'line'     => 'A fila :queue está com o mesmo job na frente há :minutes minutos: nenhum worker a está consumindo.',
        'hint'     => 'Confira se os workers estão rodando e se o --queue do crontab inclui esta fila (docs/internal/runbooks/filas-workers.md, seção 3). Este alerta se repete no máximo uma vez por hora.',
    ],
];
