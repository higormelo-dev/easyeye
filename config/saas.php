<?php

/**
 * Identidade do proprietário do SaaS — usada apenas como FALLBACK quando
 * a entidade (clínica) não preenche dados obrigatórios de localização.
 *
 * Em operação normal, todos os documentos clínicos devem usar os dados da
 * própria clínica. Este config existe para evitar PDFs com cabeçalho vazio
 * em ambiente legado / migração / clínicas recém-onboardadas.
 *
 * Campos seguem padrão ISO p/ permitir internacionalização:
 *   - country: ISO 3166-1 alpha-2 (BR, US, PT, AR…)
 *   - locale:  IETF BCP 47 (pt_BR, en_US, es_ES…)
 */
return [
    'owner' => [
        'name'    => env('SAAS_OWNER_NAME', config('app.name')),
        'city'    => env('SAAS_OWNER_CITY'),
        'state'   => env('SAAS_OWNER_STATE'),
        'country' => env('SAAS_OWNER_COUNTRY', 'BR'),
        'locale'  => env('SAAS_OWNER_LOCALE', 'pt_BR'),
    ],
];
