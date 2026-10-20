<?php

/*
|--------------------------------------------------------------------------
| Gerenciador de Imagens — Fase 5 (laudos de exames)
|--------------------------------------------------------------------------
|
| Captura pelo navegador (câmera), pedal USB (mapeamento de tecla), bloco de
| imagens embutido no laudo e link do médico solicitante. Valem no SERVIDOR
| — a tela só repete os limites.
|
*/

return [
    /*
    | Interpretador dos scripts de visão (Panorama/Auto-alinhar). Servidor sem
    | root para instalar OpenCV no Python do sistema: aponte para o python de
    | um venv (ex.: /var/lib/nginx/vision-venv/bin/python). `-I` do script
    | ignora PYTHONPATH/site do usuário, então precisa ser o python do venv.
    */
    'vision_python' => env('EYE_IMAGE_VISION_PYTHON', 'python3'),

    /*
    | Captura de foto/vídeo curto da lâmpada de fenda direto pelo navegador
    | (getUserMedia/MediaRecorder) — vira um exame pelo MESMO pipeline da
    | importação manual (ExternalExamImportService).
    */
    'capture' => [
        'max_video_seconds' => (int) env('EYE_IMAGE_CAPTURE_MAX_VIDEO_SECONDS', 20),
        'max_video_kb'      => (int) env('EYE_IMAGE_CAPTURE_MAX_VIDEO_KB', 20480),
    ],

    /*
    | Bloco de imagens embutido no laudo assinado — colunas por folha
    | (1/2/4/6, igual ao pedido de melhorias) e pareamento OD/OE.
    */
    'image_report' => [
        'allowed_columns' => [1, 2, 4, 6],
        'default_columns' => 2,

        /*
        | Teto de bytes BRUTOS (antes do base64, que infla ~33%) somados de
        | TODAS as imagens de um laudo — ReportImageBlockBuilder::build() usa
        | como orçamento e para de incluir imagens no meio da lista se
        | estourar (mesma filosofia de "nunca quebra" já aplicada a exame
        | excluído/vídeo/imagem ilegível neste arquivo). Upload permite até
        | 10 MB por imagem (ImportExternalExamRequest) e o laudo permite até
        | 50 imagens: sem este teto, essa combinação esgotava os 512 MB de
        | memory_limit (.docker/php/custom.ini) e quebrava a geração do PDF
        | pra clínica e pro médico solicitante externo (achado via revisão Codex).
        */
        'max_embedded_bytes' => (int) env('EYE_IMAGE_REPORT_MAX_EMBEDDED_BYTES', 20 * 1024 * 1024),
    ],

    /*
    | Link assinado (stateless, mesmo padrão de PatientPortalInvitation) que
    | o médico solicitante usa pra ver o laudo sem precisar de conta.
    */
    'external_doctor_link' => [
        'expires_days' => (int) env('EYE_IMAGE_EXTERNAL_LINK_DAYS', 7),
    ],

    /*
    | Pedal USB: "age como teclado" — não é integração de hardware, é
    | mapear tecla a ação (capturar / avançar). Nunca pode colidir com os
    | atalhos já existentes do visualizador (Escape/setas).
    */
    'pedal' => [
        'allowed_keys'        => ['Enter', ' ', 'PageDown', 'PageUp'],
        'default_capture_key' => 'Enter',
        'default_advance_key' => ' ',
    ],
];
