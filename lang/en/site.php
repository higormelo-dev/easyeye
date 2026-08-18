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
        'contact'      => 'Contact',
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
        ['value' => '500+', 'label' => 'Active clinics'],
        ['value' => '50k+', 'label' => 'Appointments/month'],
        ['value' => '99.9%', 'label' => 'Uptime guaranteed'],
        ['value' => 'R$0', 'label' => 'Implementation fee'],
    ],

    'problems' => [
        'label'    => 'Life without EasyEye',
        'title'    => 'Is your clinic still losing time (and money) on this?',
        'subtitle' => 'Common problems in ophthalmology clinics still running on paper, loose spreadsheets and generic systems.',
        'items'    => [
            ['icon' => 'bi-file-earmark-x', 'title' => 'Paper charts or loose spreadsheets', 'text' => 'Patient history scattered around, hard to find at the next visit and at risk of being lost.'],
            ['icon' => 'bi-images', 'title' => 'Exams and images with no organization', 'text' => 'Photos and device exams spread across a USB drive, email or folders — nobody finds them fast.'],
            ['icon' => 'bi-calendar-x', 'title' => 'Manual scheduling, no-shows and rework', 'text' => 'Without automatic confirmation, no-shows wreck the team\'s productivity for the day.'],
            ['icon' => 'bi-clock-history', 'title' => 'Slow reports and documents', 'text' => 'Every certificate, prescription or report is written from scratch, with no template or standard.'],
            ['icon' => 'bi-receipt-cutoff', 'title' => 'Manual TISS billing full of denials', 'text' => 'Claims filled by hand, rework with insurers, and revenue that takes too long to land.'],
            ['icon' => 'bi-diagram-3', 'title' => 'Disconnected systems, no single patient view', 'text' => 'Scheduling, records and exams in different tools — the team wastes time cross-referencing.'],
        ],
        'bridge' => 'That\'s exactly what EasyEye solves.',
    ],

    'benefits' => [
        'label'    => 'Benefits',
        'title'    => 'What your clinic gains with EasyEye',
        'subtitle' => 'Every module was built for the real routine of an ophthalmology clinic — from the exam room to the front desk.',
        'items'    => [
            ['icon' => 'bi-images', 'color' => 'icon-teal', 'title' => 'Ophthalmology image manager', 'text' => 'Centralize and organize patient exams and images in one place, by eye and by date — no USB drives, no lost folders.'],
            ['icon' => 'bi-file-medical', 'color' => 'icon-blue', 'title' => 'Ophthalmology-specific record', 'text' => 'Clinical information, history and patient follow-up always organized and available during the visit.'],
            ['icon' => 'bi-calendar3', 'color' => 'icon-mint', 'title' => 'Scheduling', 'text' => 'Organize doctors, time slots and appointments with automatic confirmation and fewer no-shows.'],
            ['icon' => 'bi-hdd-network', 'color' => 'icon-purple', 'title' => 'Device integration', 'text' => 'Make it easy to send and store exams performed directly on the clinic\'s equipment.'],
            ['icon' => 'bi-file-earmark-medical', 'color' => 'icon-orange', 'title' => 'Reports & documents', 'text' => 'Create and keep reports, prescriptions and other clinical documents inside the system itself.'],
            ['icon' => 'bi-stars', 'color' => 'icon-red', 'title' => 'AI assistant', 'text' => 'Support for the doctor\'s routine and document drafting — always as decision support, never replacing clinical judgment.'],
            ['icon' => 'bi-building-gear', 'color' => 'icon-blue', 'title' => 'Clinic management', 'text' => 'Centralize the clinic\'s administrative and operational information in a single place.'],
            ['icon' => 'bi-clock-history', 'color' => 'icon-teal', 'title' => 'Patient history', 'text' => 'Visits, exams, images and documents brought together in a single history, accessible anytime.'],
        ],
    ],

    'features' => [
        'label'    => 'Features',
        'title'    => 'Everything your clinic needs, in one place',
        'subtitle' => 'Built specifically for ophthalmology, with all the tools modern clinics demand.',
        'items'    => [
            ['icon' => 'bi-calendar3', 'color' => 'icon-teal', 'title' => 'Smart Scheduling', 'text' => 'Manage multiple doctors, rooms and resources. Automatic confirmation via WhatsApp and SMS, reducing no-shows by up to 40%.'],
            ['icon' => 'bi-file-medical', 'color' => 'icon-blue', 'title' => 'Electronic Health Record', 'text' => 'Ophthalmology-specific EHR with refraction, biomicroscopy, fundoscopy, visual fields and integrated reports.'],
            ['icon' => 'bi-receipt', 'color' => 'icon-mint', 'title' => 'TISS Billing', 'text' => 'TISS 3.06 claims generation, XML batches, electronic submission and return processing. Reduce denials with pre-validation.'],
            ['icon' => 'bi-graph-up-arrow', 'color' => 'icon-purple', 'title' => 'Financial Management', 'text' => 'Cash flow, accounts receivable, management reports and multiple integrated payment gateways.'],
            ['icon' => 'bi-shield-lock', 'color' => 'icon-orange', 'title' => 'CFM & LGPD Compliance', 'text' => 'Complete audit trail, EHR versioning, digital signature and full compliance with LGPD and CFM resolutions.'],
            ['icon' => 'bi-people', 'color' => 'icon-red', 'title' => 'Multi-clinic', 'text' => 'Manage multiple units with a single login. Consolidated reports and role-based access control per unit.'],
        ],
    ],

    'how' => [
        'label'                  => 'How it works',
        'title'                  => 'Simple onboarding, immediate results',
        'subtitle'               => 'Your clinic is up and running with EasyEye in less than a day. No installation, no servers, everything in the cloud.',
        'screenshot_alt'         => 'How EasyEye works',
        'screenshot_placeholder' => 'System screenshot',
        'screenshot_hint'        => 'Add to public/site/images/how-it-works.png',
        'steps'                  => [
            ['title' => 'Create your account in minutes', 'text' => 'Quick registration, no hassle. Configure your clinic, add doctors and set appointment schedules.'],
            ['title' => 'Import your patients', 'text' => 'Import your patient base via CSV or register manually. History and records migrated safely.'],
            ['title' => 'Start seeing patients', 'text' => 'Your team trained in hours. Dedicated implementation support and ongoing service to grow with you.'],
        ],
    ],

    'demo' => [
        'label'    => 'See the system',
        'title'    => 'A look inside EasyEye',
        'subtitle' => 'A preview of the screens your team will use every day.',
        'tabs'     => [
            ['key' => 'prontuario', 'icon' => 'bi-file-medical', 'label' => 'Patient record', 'caption' => 'History, refraction, biomicroscopy and fundoscopy organized by visit.'],
            ['key' => 'agenda', 'icon' => 'bi-calendar3', 'label' => 'Scheduling', 'caption' => 'Multiple doctors and rooms in a single view, with status for every appointment.'],
            ['key' => 'imagens', 'icon' => 'bi-images', 'label' => 'Image manager', 'caption' => 'Exams and photos organized by eye, type and date — fast search per patient.'],
            ['key' => 'laudos', 'icon' => 'bi-file-earmark-medical', 'label' => 'Reports & documents', 'caption' => 'Ready-made templates for reports, prescriptions, certificates and referrals.'],
        ],
        'screenshot_placeholder' => 'Module preview',
    ],

    'differentiators' => [
        'label'    => 'Why EasyEye',
        'title'    => 'Why clinics choose EasyEye',
        'subtitle' => 'Not a generic management system adapted for healthcare — built for the ophthalmology routine from day one.',
        'items'    => [
            ['icon' => 'bi-eye', 'title' => 'Ophthalmology specialist', 'text' => 'Fields, reports and workflows designed for the ophthalmology practice — not a generic EMR adapted after the fact.'],
            ['icon' => 'bi-file-earmark-check-fill', 'title' => 'TISS 3.06 certified', 'text' => 'TISS claim generation, submission and return processing certified by ANS, with pre-validation to cut denials.'],
            ['icon' => 'bi-shield-fill-check', 'title' => 'CFM & LGPD compliance, native', 'text' => 'Audit trail, record versioning and digital signature built into the architecture — not bolted on.'],
            ['icon' => 'bi-stars', 'title' => 'AI as support, not as decision', 'text' => 'AI assistant with sources when available, final conduct always validated by the responsible physician.'],
            ['icon' => 'bi-headset', 'title' => 'Support that understands clinics', 'text' => 'A support team specialized in the ophthalmology routine, not generic IT helpdesk.'],
            ['icon' => 'bi-diagram-3-fill', 'title' => 'Multi-clinic with a single view', 'text' => 'Manage multiple units with one login and consolidated reports.'],
        ],

        'pro_callout' => [
            'eyebrow' => 'Exclusive to the Pro plan',
            'title'   => 'Pro goes beyond the patient record',
            'text'    => 'Pro plan subscribers get access to additional management tools no other plan offers.',
            'items'   => [
                [
                    'icon'  => 'bi-eye',
                    'badge' => 'Coming soon',
                    'title' => 'Full visual acuity testing suite',
                    'text'  => 'The main tests used in everyday ophthalmology, right inside the EasyEye ecosystem: ETDRS, Snellen, Ishihara, Landolt C, tumbling E, pediatric optotypes, contrast tests and more.',
                ],
                [
                    'icon'  => 'bi-box-seam',
                    'badge' => 'Coming soon',
                    'title' => 'Inventory management & control',
                    'text'  => 'Track and organize materials and supplies used in the clinic\'s operations, right inside the system.',
                ],
            ],
            'cta' => 'Explore the Pro plan',
        ],
    ],

    'compliance' => [
        'label'    => 'Security & Compliance',
        'title'    => 'Secure by design, compliant by default',
        'subtitle' => 'Built in compliance with CFM regulations, ANS TISS and the General Data Protection Law. Your data and your patients\' data are protected.',
        'badges'   => [
            ['icon' => 'bi-patch-check-fill', 'label' => 'CFM Resolution'],
            ['icon' => 'bi-shield-fill-check', 'label' => 'LGPD Compliant'],
            ['icon' => 'bi-file-earmark-check-fill', 'label' => 'TISS 3.06 ANS'],
            ['icon' => 'bi-lock-fill', 'label' => 'Encrypted data'],
            ['icon' => 'bi-cloud-check-fill', 'label' => 'Automatic backup'],
            ['icon' => 'bi-journal-check', 'label' => 'Audit trail'],
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
        'label'               => 'Pricing',
        'title'               => 'Plans for every clinic size',
        'subtitle'            => 'No implementation fees. Cancel anytime.',
        'trial_suffix'        => 'Try free for :days days.',
        'featured_badge'      => 'Most popular',
        'contact_cta'         => 'Talk to an expert',
        'on_request'          => 'Contact us',
        'get_started'         => 'Get started free',
        'trial_text'          => ':days days free trial',
        'empty_title'         => 'Plans coming soon',
        'empty_subtitle'      => 'We are preparing the best plans for your clinic. Get in touch to learn more.',
        'pro_exclusive_label' => 'Additional Pro features',
        'pro_exclusive_new'   => 'Coming soon',
    ],

    'faq' => [
        'label' => 'FAQ',
        'title' => 'Frequently asked questions',
        'items' => [
            ['q' => 'How is my clinic data migrated?', 'a' => 'We offer CSV import for patients and history. Our implementation team helps migrate data from your previous system without interrupting your operations.'],
            ['q' => 'Does EasyEye work offline?', 'a' => 'EasyEye is a 100% cloud solution, ensuring access from any device. For contingency, we maintain a local cache of the day\'s schedules.'],
            ['q' => 'How does technical support work?', 'a' => 'We offer support via chat, email and phone depending on your plan. Professional plan customers receive priority support with a 4-business-hour SLA.'],
            ['q' => 'Is the system approved by ANS for TISS?', 'a' => 'Yes. EasyEye is approved for ANS TISS versions 3.05 and 3.06, with fully automated XML generation, submission and return processing.'],
            ['q' => 'Can I integrate with other systems?', 'a' => 'We provide a REST API for integration with ERPs, imaging systems (reports), laboratories and other clinical systems. Developer documentation available.'],
        ],
    ],

    'cta' => [
        'title'     => 'Ready to transform your ophthalmology clinic?',
        'subtitle'  => '14 days free, no credit card required. Set up in less than a day.',
        'primary'   => 'Create free account',
        'secondary' => 'Talk to an expert',
        'note'      => 'No implementation fee • Cancel anytime • Onboarding support',
    ],

    'contact' => [
        'label'         => 'Contact',
        'headline_pre'  => 'Want to talk to',
        'headline_post' => 'Our team is ready to help you!',
        'title'         => 'Talk to our team',
        'subtitle'      => 'Ophthalmology management specialists ready to help you transform your clinic.',

        'sales' => [
            'title'   => 'Sales',
            'desc'    => 'Questions about plans, features and integrations. Our team knows clinical workflows inside and out.',
            'cta'     => 'Chat on WhatsApp',
            'hours'   => 'Mon–Fri, 8am to 6pm',
            'channel' => 'WhatsApp support',
        ],
        'support' => [
            'title'   => 'Technical Support',
            'desc'    => 'Chat and email support with plan-based SLA. Pro and Premium plans receive priority service.',
            'cta'     => 'Send email',
            'hours'   => 'Mon–Fri, 8am to 6pm',
            'channel' => 'suporte@easyeye.com.br',
        ],
        'trial' => [
            'title' => 'Start for Free',
            'desc'  => ':days days with no credit card required. Set up your clinic in less than a day and start seeing patients with digital records.',
            'cta'   => 'Create free account',
            'badge' => 'Most popular',
            'note'  => 'No implementation fee',
        ],

        'aside' => [
            'hours_title'  => 'Business hours',
            'hours_body'   => 'Monday to Friday, 8am to 6pm (Brasília time).',
            'chat_title'   => 'In-app chat',
            'chat_body'    => 'Active customers have access to chat support directly in the EasyEye dashboard.',
            'quote_text'   => 'EasyEye support resolved our issue in under an hour. Team is extremely knowledgeable about ophthalmology.',
            'quote_author' => 'Dr. Mariana Costa — Clínica Visão SP',
        ],

        'form' => [
            'title'          => 'Send us a message',
            'subtitle'       => 'Fill out the form and we will get back to you within 1 business day.',
            'name'           => 'Full name',
            'name_ph'        => 'Your name',
            'email'          => 'Email',
            'phone'          => 'WhatsApp (with area code)',
            'is_client'      => 'Are you a customer?',
            'is_client_opts' => ['Yes', 'No', 'Former customer'],
            'role'           => 'Role',
            'role_opts'      => ['Ophthalmologist', 'Clinic Manager', 'Administrative', 'IT / Technology', 'Other'],
            'segment'        => 'Type of practice',
            'segment_opts'   => ['Solo practice', 'Ophthalmology clinic', 'Clinic network', 'Hospital / Outpatient', 'Health plan', 'Other'],
            'select'         => 'Select',
            'terms'          => 'I have read and agree to the <a href="#">Privacy Policy</a> and authorize EasyEye to contact me.',
            'submit'         => 'Send message',
            'sending'        => 'Sending...',
            'success_title'  => 'Message sent!',
            'success_body'   => 'Our team will get back to you within 1 business day. Keep an eye on your inbox!',
        ],

        'trust_ssl'  => 'SSL Encryption',
        'trust_lgpd' => 'LGPD Compliant',
        'trust_cfm'  => 'CFM Approved',
        'trust_nps'  => '97% satisfaction',
    ],
];
