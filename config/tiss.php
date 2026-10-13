<?php

return [
    'default_version' => env('TISS_DEFAULT_VERSION', '202603'),

    'require_schema_validation' => (bool) env('TISS_REQUIRE_SCHEMA_VALIDATION', false),

    'schema_base_path' => env('TISS_SCHEMA_BASE_PATH', storage_path('app/tiss/schemas')),

    // Nome do XSD de ENTRADA (schema principal — os demais arquivos do
    // diretório da versão são <xsd:import> dele, resolvidos pelo libxml a
    // partir do mesmo diretório) por versão TISS. Mapeamento explícito: o
    // nome real do arquivo distribuído pela ANS não segue uma fórmula
    // confiável a partir do código da versão (ex.: "202603" -> layout
    // "04.03.00" -> arquivo "tissV4_03_00.xsd") — achado via auditoria de
    // encerramento, EE-07 (o serviço procurava um "ansTISS.xsd" que nunca
    // existiu, então nunca validava nada).
    'schema_files' => [
        '202603' => 'tissV4_03_00.xsd',
    ],

    'transport' => [
        'driver'      => env('TISS_TRANSPORT_DRIVER', 'mock'), // mock|http
        'environment' => env('TISS_TRANSPORT_ENVIRONMENT', 'production'), // production|sandbox

        'http' => [
            'timeout_seconds'         => (int) env('TISS_HTTP_TIMEOUT_SECONDS', 30),
            'connect_timeout_seconds' => (int) env('TISS_HTTP_CONNECT_TIMEOUT_SECONDS', 10),
        ],

        // Prazo (s) em que uma transmissão 'sending' é considerada EM VOO de
        // verdade (lease de propriedade, contado de requested_at). Passado
        // esse prazo sem resposta gravada, a tentativa é tratada como
        // INTERROMPIDA (worker morto no meio, falha ao gravar o retorno) e o
        // próximo envio a retoma, reaproveitando a MESMA linha e a MESMA
        // chave de idempotência. Tem que ficar ACIMA do maior tempo que uma
        // tentativa viva pode levar: o $timeout do SendTissBatchJob (90 s) e
        // os timeouts HTTP acima (timeout + connect). Menos que isso deixa
        // dois workers enviando o MESMO lote ao mesmo tempo (achado via
        // revisão adversarial Codex: sem prazo, uma transmissão 'sending'
        // órfã bloqueava qualquer reenvio — até force=true — pra sempre).
        'lease_seconds' => (int) env('TISS_SEND_LEASE_SECONDS', 180),
    ],

    'returns' => [
        'auto_process' => (bool) env('TISS_AUTO_PROCESS_RETURNS', true),
    ],

    'glosa_appeal_deadline_days'    => (int) env('TISS_GLOSA_APPEAL_DEADLINE_DAYS', 30),
    'appeal_response_deadline_days' => (int) env('TISS_APPEAL_RESPONSE_DEADLINE_DAYS', 60),

    // Domínios administrativos exigidos pelo XML TISS 4.03 sem fonte de dado
    // por guia no projeto hoje (ver plano de conformidade — Fase B cobriria
    // captura real por guia). Valores abaixo são o default mais seguro pra
    // uma clínica oftalmológica ambulatorial, não um dado clínico real.
    'defaults' => [
        'regime_atendimento'       => env('TISS_DEFAULT_REGIME_ATENDIMENTO', '01'), // dm_regimeAtendimento: 01=Ambulatorial
        'tipo_consulta'            => env('TISS_DEFAULT_TIPO_CONSULTA', '1'), // dm_tipoConsulta: 1=Primeira (sem distinção 1ª/retorno hoje)
        'tipo_atendimento_sadt'    => env('TISS_DEFAULT_TIPO_ATENDIMENTO_SADT', '04'), // dm_tipoAtendimento: "05-Exames" foi inativado na v4.00.00 sem substituto óbvio
        'carater_atendimento_sadt' => env('TISS_DEFAULT_CARATER_ATENDIMENTO_SADT', '1'), // dm_caraterAtendimento: 1=Eletiva
        'cbo_oftalmologista'       => env('TISS_DEFAULT_CBO', '225265'), // dm_CBOS: usado quando o médico não tem CBO próprio cadastrado
    ],

    'idempotency' => [
        'ttl_hours' => (int) env('TISS_IDEMPOTENCY_TTL_HOURS', 48),
    ],

    'queues' => [
        'xml'     => env('TISS_QUEUE_XML', 'tiss-xml'),
        'send'    => env('TISS_QUEUE_SEND', 'tiss-send'),
        'returns' => env('TISS_QUEUE_RETURNS', 'tiss-returns'),

        'max_attempts' => [
            'xml'     => (int) env('TISS_QUEUE_XML_MAX_ATTEMPTS', 5),
            'send'    => (int) env('TISS_QUEUE_SEND_MAX_ATTEMPTS', 5),
            'returns' => (int) env('TISS_QUEUE_RETURNS_MAX_ATTEMPTS', 7),
        ],
    ],
];
