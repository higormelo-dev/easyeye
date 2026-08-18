<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Snappy PDF / Image Configuration
    |--------------------------------------------------------------------------
    |
    | This option contains settings for PDF generation.
    |
    | Enabled:
    |
    |    Whether to load PDF / Image generation.
    |
    | Binary:
    |
    |    The file path of the wkhtmltopdf / wkhtmltoimage executable.
    |
    | Timeout:
    |
    |    The amount of time to wait (in seconds) before PDF / Image generation is stopped.
    |    Setting this to false disables the timeout (unlimited processing time).
    |
    | Options:
    |
    |    The wkhtmltopdf command options. These are passed directly to wkhtmltopdf.
    |    See https://wkhtmltopdf.org/usage/wkhtmltopdf.txt for all options.
    |
    | Env:
    |
    |    The environment variables to set while running the wkhtmltopdf process.
    |
    */

    // BUGFIX: o default era fixo em env('WKHTML_PDF_BINARY') apontando
    // /usr/local/bin/wkhtmltopdf (instalado só no container Docker). Rodando
    // no host (php artisan serve), o binário não existe → exit 127 e TODA
    // emissão de PDF do prontuário (laudos, receituários, atestados) dava 500.
    // Agora: env explícito > binário do sistema (container, 0.12.6 patched) >
    // fallback vendor h4cc (host, 0.12.4). Auto-adapta sem trocar .env.
    'pdf' => [
        'enabled' => true,
        'binary'  => env('WKHTML_PDF_BINARY')
            ?: (is_executable('/usr/local/bin/wkhtmltopdf')
                ? '/usr/local/bin/wkhtmltopdf'
                : base_path('vendor/h4cc/wkhtmltopdf-amd64/bin/wkhtmltopdf-amd64')),
        'timeout' => false,
        'options' => [],
        'env'     => [],
    ],

    'image' => [
        'enabled' => true,
        'binary'  => env('WKHTML_IMG_BINARY')
            ?: (is_executable('/usr/local/bin/wkhtmltoimage')
                ? '/usr/local/bin/wkhtmltoimage'
                : base_path('vendor/h4cc/wkhtmltoimage-amd64/bin/wkhtmltoimage-amd64')),
        'timeout' => false,
        'options' => [],
        'env'     => [],
    ],
];
