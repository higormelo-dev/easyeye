<?php

// E-mail to the SaaS team when a queue stops moving (queue:check-stalled).
return [
    'stalled' => [
        'subject'  => '[Queue alert] :queue stalled',
        'greeting' => 'Hello, :name!',
        'line'     => 'The :queue queue has had the same job at the front for :minutes minutes: no worker is consuming it.',
        'hint'     => 'Check that the workers are running and that the crontab --queue includes this queue (docs/internal/runbooks/filas-workers.md, section 3). This alert repeats at most once an hour.',
    ],
];
