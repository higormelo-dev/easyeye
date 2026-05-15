<?php

return [

    'meta' => [
        'title'          => config('app.name', 'EasyEye') . ' — Ophthalmology Clinic Management',
        'description'    => 'Complete management for ophthalmology clinics. Electronic health records, scheduling, TISS billing and more in one single system.',
        'og_title'       => config('app.name', 'EasyEye') . ' — Ophthalmology Clinic Management',
        'og_description' => 'Complete management for ophthalmology clinics. From scheduling to TISS billing, all integrated.',
    ],

    'nav' => [
        'features'     => 'Features',
        'how'          => 'How it works',
        'pricing'      => 'Pricing',
        'testimonials' => 'Testimonials',
        'faq'          => 'FAQ',
        'login'        => 'Sign in',
        'get_started'  => 'Get started free',
        'language'     => 'Language',
    ],

    'footer' => [
        'tagline'   => 'Clinic management system specialized in ophthalmology. Scheduling, EHR, TISS billing and financials in one single platform.',
        'product'   => 'Product',
        'system'    => 'System',
        'company'   => 'Company',
        'login'     => 'Sign in',
        'register'  => 'Create account',
        'help'      => 'Help center',
        'status'    => 'Platform status',
        'api'       => 'API & Integrations',
        'about'     => 'About us',
        'blog'      => 'Blog',
        'partners'  => 'Partners',
        'contact'   => 'Contact',
        'careers'   => 'Careers',
        'privacy'   => 'Privacy',
        'terms'     => 'Terms of use',
        'lgpd'      => 'LGPD',
        'copyright' => '© :year :name. All rights reserved.',
    ],

    'hero' => [
        'badge'               => 'New: TISS 3.06 integrated and approved',
        'title'               => 'Complete management for',
        'title_em'            => 'ophthalmology clinics',
        'subtitle'            => 'From scheduling to electronic health records with integrated TISS billing. Automate processes, reduce claim denials and focus on what really matters: your patients\' health.',
        'cta_primary'         => 'Get started free',
        'cta_secondary'       => 'Watch demo',
        'trust'               => 'More than <strong style="color:#fff;">:count clinics</strong> trust EasyEye',
        'card_today'          => 'Today',
        'card_appointments'   => ':count appointments scheduled',
        'card_compliance_lbl' => 'LGPD & CFM',
        'card_compliance_val' => 'Compliance guaranteed',
    ],

    'metrics' => [
        ['value' => '500+',   'label' => 'Active clinics'],
        ['value' => '50k+',   'label' => 'Appointments/month'],
        ['value' => '99.9%',  'label' => 'Uptime guaranteed'],
        ['value' => 'R$0',    'label' => 'Implementation fee'],
    ],

    'features' => [
        'label'    => 'Features',
        'title'    => 'Everything your clinic needs, in one place',
        'subtitle' => 'Built specifically for ophthalmology, with all the tools modern clinics demand.',
        'items'    => [
            ['icon' => 'bi-calendar3',     'color' => 'icon-teal',   'title' => 'Smart Scheduling',      'text' => 'Manage multiple doctors, rooms and resources. Automatic confirmation via WhatsApp and SMS, reducing no-shows by up to 40%.'],
            ['icon' => 'bi-file-medical',  'color' => 'icon-blue',   'title' => 'Electronic Health Record','text' => 'Ophthalmology-specific EHR with refraction, biomicroscopy, fundoscopy, visual fields and integrated reports.'],
            ['icon' => 'bi-receipt',       'color' => 'icon-mint',   'title' => 'TISS Billing',           'text' => 'TISS 3.06 claims generation, XML batches, electronic submission and return processing. Reduce denials with pre-validation.'],
            ['icon' => 'bi-graph-up-arrow','color' => 'icon-purple', 'title' => 'Financial Management',   'text' => 'Cash flow, accounts receivable, management reports and multiple integrated payment gateways.'],
            ['icon' => 'bi-shield-lock',   'color' => 'icon-orange', 'title' => 'CFM & LGPD Compliance',  'text' => 'Complete audit trail, EHR versioning, digital signature and full compliance with LGPD and CFM resolutions.'],
            ['icon' => 'bi-people',        'color' => 'icon-red',    'title' => 'Multi-clinic',           'text' => 'Manage multiple units with a single login. Consolidated reports and role-based access control per unit.'],
        ],
    ],

    'how' => [
        'label'                 => 'How it works',
        'title'                 => 'Simple onboarding, immediate results',
        'subtitle'              => 'Your clinic is up and running with EasyEye in less than a day. No installation, no servers, everything in the cloud.',
        'screenshot_alt'        => 'How EasyEye works',
        'screenshot_placeholder'=> 'System screenshot',
        'screenshot_hint'       => 'Add to public/site/images/how-it-works.png',
        'steps'                 => [
            ['title' => 'Create your account in minutes', 'text' => 'Quick registration, no hassle. Configure your clinic, add doctors and set appointment schedules.'],
            ['title' => 'Import your patients',           'text' => 'Import your patient base via CSV or register manually. History and records migrated safely.'],
            ['title' => 'Start seeing patients',          'text' => 'Your team trained in hours. Dedicated implementation support and ongoing service to grow with you.'],
        ],
    ],

    'compliance' => [
        'label'    => 'Security & Compliance',
        'title'    => 'Secure by design, compliant by default',
        'subtitle' => 'Built in compliance with CFM regulations, ANS TISS and the General Data Protection Law. Your data and your patients\' data are protected.',
        'badges'   => [
            ['icon' => 'bi-patch-check-fill',         'label' => 'CFM Resolution'],
            ['icon' => 'bi-shield-fill-check',        'label' => 'LGPD Compliant'],
            ['icon' => 'bi-file-earmark-check-fill',  'label' => 'TISS 3.06 ANS'],
            ['icon' => 'bi-lock-fill',                'label' => 'Encrypted data'],
            ['icon' => 'bi-cloud-check-fill',         'label' => 'Automatic backup'],
            ['icon' => 'bi-journal-check',            'label' => 'Audit trail'],
        ],
    ],

    'testimonials' => [
        'label' => 'Testimonials',
        'title' => 'What our clients say',
        'items' => [
            [
                'text'     => '"EasyEye transformed our clinic. TISS billing that used to take days now takes hours. We reduced claim denials by 60% in the first month."',
                'name'     => 'Dr. Ricardo Mendes',
                'role'     => 'Ophthalmologist — Clínica Visão SP',
                'initials' => 'DR',
                'stars'    => 5,
            ],
            [
                'text'     => '"The integrated scheduling with automatic confirmation reduced our no-show rate from 30% to under 8%. The team loved how easy it is to use."',
                'name'     => 'Dr. Ana Carvalho',
                'role'     => 'Director — Instituto Ocular BH',
                'initials' => 'AC',
                'stars'    => 5,
            ],
            [
                'text'     => '"We manage 3 units with EasyEye. The consolidated reports and unit-level access control gave us full visibility of the business."',
                'name'     => 'Paulo Souza',
                'role'     => 'Manager — Rede OftalmoClin RJ',
                'initials' => 'PS',
                'stars'    => 4,
            ],
        ],
    ],

    'pricing' => [
        'label'          => 'Pricing',
        'title'          => 'Plans for every clinic size',
        'subtitle'       => 'No implementation fees. Cancel anytime.',
        'trial_suffix'   => 'Try free for :days days.',
        'featured_badge' => 'Most popular',
        'contact_cta'    => 'Talk to an expert',
        'on_request'     => 'Contact us',
        'get_started'    => 'Get started free',
        'trial_text'     => ':days days free trial',
        'empty_title'    => 'Plans coming soon',
        'empty_subtitle' => 'We are preparing the best plans for your clinic. Get in touch to learn more.',
    ],

    'faq' => [
        'label' => 'FAQ',
        'title' => 'Frequently asked questions',
        'items' => [
            ['q' => 'How is my clinic data migrated?',        'a' => 'We offer CSV import for patients and history. Our implementation team helps migrate data from your previous system without interrupting your operations.'],
            ['q' => 'Does EasyEye work offline?',             'a' => 'EasyEye is a 100% cloud solution, ensuring access from any device. For contingency, we maintain a local cache of the day\'s schedules.'],
            ['q' => 'How does technical support work?',       'a' => 'We offer support via chat, email and phone depending on your plan. Professional plan customers receive priority support with a 4-business-hour SLA.'],
            ['q' => 'Is the system approved by ANS for TISS?','a' => 'Yes. EasyEye is approved for ANS TISS versions 3.05 and 3.06, with fully automated XML generation, submission and return processing.'],
            ['q' => 'Can I integrate with other systems?',    'a' => 'We provide a REST API for integration with ERPs, imaging systems (reports), laboratories and other clinical systems. Developer documentation available.'],
        ],
    ],

    'cta' => [
        'title'     => 'Ready to transform your ophthalmology clinic?',
        'subtitle'  => '14 days free, no credit card required. Set up in less than a day.',
        'primary'   => 'Create free account',
        'secondary' => 'Talk to an expert',
        'note'      => 'No implementation fee • Cancel anytime • Onboarding support',
    ],

];
