<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default Queue Connection Name
    |--------------------------------------------------------------------------
    |
    | Laravel's queue supports a variety of backends via a single, unified
    | API, giving you convenient access to each backend using identical
    | syntax for each. The default queue connection is defined below.
    |
    */

    'default' => env('QUEUE_CONNECTION', 'database'),

    /*
    |--------------------------------------------------------------------------
    | Queue Connections
    |--------------------------------------------------------------------------
    |
    | Here you may configure the connection options for every queue backend
    | used by your application. An example configuration is provided for
    | each backend supported by Laravel. You're also free to add more.
    |
    | Drivers: "sync", "database", "beanstalkd", "sqs", "redis", "null"
    |
    */

    'connections' => [
        'sync' => [
            'driver' => 'sync',
        ],

        'database' => [
            'driver'     => 'database',
            'connection' => env('DB_QUEUE_CONNECTION'),
            'table'      => env('DB_QUEUE_TABLE', 'jobs'),
            'queue'      => env('DB_QUEUE', 'default'),
            // Tem que ficar ACIMA do maior $timeout entre os jobs desta
            // conexão (hoje: 900s em ProcessCovenantImportJob /
            // ProcessMedicineImportJob) — abaixo disso, o job vira
            // "disponível de novo" e OUTRO worker pega a MESMA linha
            // enquanto a 1ª tentativa, legítima, ainda está rodando (achado
            // via auditoria de encerramento, EE-09). 90s (o antigo default)
            // só cobria o --timeout da linha de comando do worker, nunca o
            // timeout maior que os próprios jobs declaram (que tem
            // precedência — ver Illuminate\Queue\Worker::timeoutForJob()).
            'retry_after'  => (int) env('DB_QUEUE_RETRY_AFTER', 960),
            'after_commit' => false,
        ],

        'beanstalkd' => [
            'driver'       => 'beanstalkd',
            'host'         => env('BEANSTALKD_QUEUE_HOST', 'localhost'),
            'queue'        => env('BEANSTALKD_QUEUE', 'default'),
            'retry_after'  => (int) env('BEANSTALKD_QUEUE_RETRY_AFTER', 90),
            'block_for'    => 0,
            'after_commit' => false,
        ],

        'sqs' => [
            'driver'       => 'sqs',
            'key'          => env('AWS_ACCESS_KEY_ID'),
            'secret'       => env('AWS_SECRET_ACCESS_KEY'),
            'prefix'       => env('SQS_PREFIX', 'https://sqs.us-east-1.amazonaws.com/your-account-id'),
            'queue'        => env('SQS_QUEUE', 'default'),
            'suffix'       => env('SQS_SUFFIX'),
            'region'       => env('AWS_DEFAULT_REGION', 'us-east-1'),
            'after_commit' => false,
        ],

        'redis' => [
            'driver'     => 'redis',
            'connection' => env('REDIS_QUEUE_CONNECTION', 'default'),
            'queue'      => env('REDIS_QUEUE', 'default'),
            // Mesma regra da conexão 'database' acima: SEMPRE acima do maior
            // $timeout entre os jobs (900s hoje). No Redis a reserva é um
            // ZSET (queues:{fila}:reserved) com score = agora + retry_after;
            // vencida, o job volta pra fila e OUTRO worker (o Supervisor sobe
            // 2) pega o MESMO job enquanto o 1º, legítimo, ainda roda. Este
            // era o único default que o EE-09 deixou em 90s — e é a conexão
            // que o .env.example seleciona (QUEUE_CONNECTION=redis).
            'retry_after'  => (int) env('REDIS_QUEUE_RETRY_AFTER', 960),
            'block_for'    => null,
            'after_commit' => false,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Job Batching
    |--------------------------------------------------------------------------
    |
    | The following options configure the database and table that store job
    | batching information. These options can be updated to any database
    | connection and table which has been defined by your application.
    |
    */

    'batching' => [
        'database' => env('DB_CONNECTION', 'sqlite'),
        'table'    => 'job_batches',
    ],

    /*
    |--------------------------------------------------------------------------
    | Failed Queue Jobs
    |--------------------------------------------------------------------------
    |
    | These options configure the behavior of failed queue job logging so you
    | can control how and where failed jobs are stored. Laravel ships with
    | support for storing failed jobs in a simple file or in a database.
    |
    | Supported drivers: "database-uuids", "dynamodb", "file", "null"
    |
    */

    'failed' => [
        'driver'   => env('QUEUE_FAILED_DRIVER', 'database-uuids'),
        'database' => env('DB_CONNECTION', 'sqlite'),
        'table'    => 'failed_jobs',
    ],
];
