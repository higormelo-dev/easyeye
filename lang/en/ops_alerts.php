<?php

// E-mail to the SaaS team when the production configuration has something simulated or off (ops:check-production-config).
return [
    'config' => [
        'subject'  => '[Config alert] :count simulated or disabled item(s) in production',
        'greeting' => 'Hello, :name!',
        'intro'    => 'The production configuration has these items simulated or turned off:',
        'hint'     => 'Each item comes from the server .env (docs/internal/runbooks/alertas-config-producao.md). After fixing it, run php artisan config:cache. The alert for each item repeats at most once a week.',
    ],

    'findings' => [
        'turnstile_off'      => 'Sign-up captcha is off: TURNSTILE_SITE_KEY and TURNSTILE_SECRET_KEY are missing, so /register approves without a captcha.',
        'tiss_simulated'     => 'TISS is in simulation mode (TISS_TRANSPORT_DRIVER is not http): no batch reaches the insurer and, in production, sending is blocked.',
        'whatsapp_simulated' => 'WhatsApp is simulated (WHATSAPP_DRIVER is not gupshup): no message is really sent.',
        'whatsapp_sandbox'   => 'WhatsApp is in sandbox (GUPSHUP_SANDBOX=true): the connection test uses the sandbox template.',
        'mail_not_sending'   => 'E-mail goes only to the log (MAIL_MAILER=log or array): no e-mail is sent, not even this alert.',
        'ai_fake'            => 'AI is in fake mode (AI_PROVIDER_RUNTIME is not real): answers do not come from a provider.',
    ],
];
