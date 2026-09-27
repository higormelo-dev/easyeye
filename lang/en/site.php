<?php

return [
    'meta' => [
        // The Inertia title callback (site.js) already prefixes "EasyEye — ".
        'title'          => 'Ophthalmology clinic management',
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
        'menu'         => 'Menu',
        'skip'         => 'Skip to content',
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

    // /privacidade and /termos pages (current version from term_versions).
    'legal' => [
        'privacy_title'       => 'Privacy Policy',
        'terms_title'         => 'Terms of Use',
        'privacy_description' => 'EasyEye Privacy Policy: how we handle personal data of clinics, professionals and patients.',
        'terms_description'   => 'Terms of Use of the EasyEye platform.',
        'version'             => 'Version :version · effective since :date',
        'unavailable_title'   => 'Document being published',
        'unavailable_text'    => 'The official version of this document has not been published here yet. To get it now, write to',
        'back_home'           => 'Back to home',
    ],

    'hero' => [
        'badge'    => 'New: TISS 3.06 integrated and approved',
        'title'    => 'Complete management for',
        'title_em' => 'ophthalmology clinics',
        'subtitle' => 'From scheduling to electronic health records with integrated TISS billing. Automate processes, reduce claim denials and focus on what really matters: your patients\' health.',
        // Microcopy under the buttons, only when a plan has a free trial (days come from the database).
        'cta_note'      => ':days-day free trial · no credit card',
        'cta_primary'   => 'Get started free',
        'cta_secondary' => 'See inside the system',
        'trust'         => 'More than <strong style="color:#fff;">:count clinics</strong> trust EasyEye',
        // Initials of the testimonial authors (Dr. Ricardo Mendes, Dr. Ana Carvalho, Paulo Souza, Dr. Mariana Costa).
        'trust_initials' => ['RM', 'AC', 'PS', 'MC'],
        // Real crop of the patient record (public/site/images/hero-prontuario.webp), no patient data.
        'visual_alt'   => 'EasyEye ophthalmology record with visual acuity, tonometry and dynamic and static refraction per eye (OD and OS)',
        'card_top_lbl' => 'Images per eye',
        'card_top_val' => 'OCT, retinography and biometry',
        'card_bot_lbl' => 'CFM and LGPD',
        'card_bot_val' => 'Signed, versioned records',
    ],

    'metrics' => [
        ['value' => '500+', 'amount' => 500, 'suffix' => '+', 'label' => 'Active clinics'],
        ['value' => '50k+', 'amount' => 50, 'suffix' => 'k+', 'label' => 'Appointments/month'],
        ['value' => '99.9%', 'amount' => 99.9, 'decimals' => 1, 'suffix' => '%', 'label' => 'Uptime guaranteed'],
        ['value' => 'R$0', 'amount' => 0, 'prefix' => 'R$', 'label' => 'Implementation fee'],
    ],

    'problems' => [
        'label'    => 'Life without EasyEye',
        'title'    => 'Is your clinic still losing time (and money) on this?',
        'subtitle' => 'Common problems in ophthalmology clinics still running on paper, loose spreadsheets and generic systems.',
        'items'    => [
            ['icon' => 'ti-calendar-x', 'title' => 'Manual scheduling, no-shows and rework', 'text' => 'Without automatic confirmation, no-shows wreck the team\'s productivity for the day.'],
            ['icon' => 'ti-files', 'title' => 'Records and exams scattered around', 'text' => 'History on paper or spreadsheets and device exams on a USB drive, email or folders: hard to find at the next visit and at risk of being lost.'],
            ['icon' => 'ti-receipt-off', 'title' => 'Manual TISS billing full of denials', 'text' => 'Claims filled by hand, rework with insurers, and revenue that takes too long to land.'],
            ['icon' => 'ti-topology-star-3', 'title' => 'Disconnected systems, no single patient view', 'text' => 'Scheduling, records and exams in different tools — the team wastes time cross-referencing.'],
        ],
        'bridge' => 'That\'s exactly what EasyEye solves.',
    ],

    'audiences' => [
        'label'        => 'Features',
        'title'        => 'Everything your clinic needs, in one place',
        'subtitle'     => 'Every module was built for the real routine of an ophthalmology clinic — from the exam room to the front desk.',
        'available_in' => 'Available on :plans',
        'groups'       => [
            [
                'key'      => 'recepcao',
                'icon'     => 'ti-calendar-check',
                'title'    => 'Front desk and scheduling',
                'audience' => 'For whoever runs the clinic\'s day',
                'items'    => [
                    ['text' => 'Several doctors, rooms and devices in one schedule, with a waiting list and blocked slots.'],
                    ['text' => 'Automatic confirmation via WhatsApp and SMS, reducing no-shows by up to 40%.'],
                    ['text' => 'The patient\'s history in one place: visits, exams, images and documents.'],
                ],
            ],
            [
                'key'      => 'consultorio',
                'icon'     => 'ti-stethoscope',
                'title'    => 'Exam room',
                'audience' => 'For the ophthalmologist',
                'items'    => [
                    ['text' => 'Ophthalmology record with refraction, biomicroscopy, fundoscopy and visual fields.'],
                    ['text' => 'Images and exams organized by eye and by date — no USB drives, no lost folders.'],
                    ['text' => 'Integration with the clinic\'s devices to send and store exams.', 'feature' => 'has_api_integrator'],
                    ['text' => 'Ready-made templates for reports, prescriptions and certificates.'],
                    ['text' => 'AI assistant for drafting reports — always as support; the final call is the doctor\'s.', 'feature' => 'has_ai_report_drafting'],
                ],
            ],
            [
                'key'      => 'faturamento',
                'icon'     => 'ti-receipt',
                'title'    => 'Billing and management',
                'audience' => 'For whoever handles insurers and cash',
                'items'    => [
                    ['text' => 'TISS 3.06 claims, XML batches, electronic submission and returns, with pre-validation to reduce denials.'],
                    ['text' => 'Cash flow, accounts receivable, management reports and integrated payment gateways.'],
                    ['text' => 'Multiple units with a single login, consolidated reports and role-based access.'],
                ],
                'flow_label' => 'TISS flow in EasyEye',
                'flow'       => ['Claim', 'Pre-validation', 'XML batch', 'Submission', 'Return and denials'],
            ],
        ],
    ],

    'how' => [
        'label'          => 'How it works',
        'title'          => 'Simple onboarding, immediate results',
        'subtitle'       => 'Your clinic is up and running with EasyEye in less than a day. No installation, no servers, everything in the cloud.',
        'screenshot_alt' => 'EasyEye "Set up your clinic" checklist with the onboarding steps marked as done',
        'steps'          => [
            ['title' => 'Create your account in minutes', 'text' => 'Quick registration, no hassle. Configure your clinic, add doctors and set appointment schedules.'],
            ['title' => 'Import your patients', 'text' => 'Import your patient base via CSV or register manually. History and records migrated safely.'],
            ['title' => 'Start seeing patients', 'text' => 'Your team trained in hours. Dedicated implementation support and ongoing service to grow with you.'],
        ],
    ],

    'demo' => [
        'label'      => 'See the system',
        'title'      => 'A look inside EasyEye',
        'subtitle'   => 'A preview of the screens your team will use every day.',
        'fictitious' => 'Fictitious data.',
        'enlarge'    => 'Enlarge image',
        'tabs'       => [
            ['key' => 'prontuario', 'icon' => 'ti-report-medical', 'label' => 'Patient record', 'caption' => 'Visual acuity, tonometry, refraction, biomicroscopy and fundoscopy per eye.'],
            ['key' => 'imagens', 'icon' => 'ti-photo', 'label' => 'Image manager', 'caption' => 'Exams by date and type — OCT, biometry, retinography — tagged OD, OS and OU.'],
            ['key' => 'agenda', 'fictitious' => true, 'icon' => 'ti-calendar', 'label' => 'Scheduling', 'caption' => 'The day\'s schedule with time, visit type, insurer and status of each appointment.'],
            ['key' => 'laudos', 'icon' => 'ti-file-text', 'label' => 'Reports and documents', 'caption' => 'Ready-made templates for reports, certificates and specialized exams, with header and signature.'],
        ],
    ],
    'metrics_context' => 'Data based on internal research with active users and system monitoring for the year 2026.',

    'differentiators' => [
        'label'    => 'Why EasyEye',
        'title'    => 'Why clinics choose EasyEye',
        'subtitle' => 'Not a generic management system adapted for healthcare — built for the ophthalmology routine from day one.',
        'items'    => [
            ['icon' => 'ti-eye', 'title' => 'Built for ophthalmology', 'text' => 'Fields, reports and workflows designed for the ophthalmology practice — not a generic record adapted after the fact.'],
            ['icon' => 'ti-file-certificate', 'title' => 'TISS 3.06 certified', 'text' => 'TISS claim generation, submission and return processing certified by ANS, with pre-validation to cut denials.'],
            ['icon' => 'ti-layout-grid', 'title' => 'Everything in one system', 'text' => 'Scheduling, records, images, documents and finances in one place, with no disconnected tools.'],
            ['icon' => 'ti-shield-check', 'title' => 'CFM and LGPD compliance built into the architecture', 'text' => 'Audit trail, record versioning and digital signature built into the architecture — not bolted on.'],
        ],
        'proof_title' => 'How compliance works in practice',
        'proof'       => [
            ['icon' => 'ti-history', 'label' => 'Audit trail of every change'],
            ['icon' => 'ti-lock', 'label' => 'Record locked after signature'],
            ['icon' => 'ti-versions', 'label' => 'Record version history'],
            ['icon' => 'ti-eye-check', 'label' => 'Sensitive data access logging'],
            ['icon' => 'ti-file-check', 'label' => 'Patient LGPD consents'],
            ['icon' => 'ti-cloud-lock', 'label' => 'Encrypted data and automatic backup'],
        ],
    ],

    'testimonials' => [
        'label'   => 'Testimonials',
        'title'   => 'What our clients say',
        'context' => 'Real stories from clinic managers and ophthalmologists. Results may vary depending on the size of the clinic.',
        'rating'  => ':stars out of 5 stars',
        'items'   => [
            [
                'text'     => '"EasyEye transformed our clinic. TISS billing that used to take days now takes hours. We reduced claim denials by 60% in the first month."',
                'name'     => 'Dr. Ricardo Mendes',
                'role'     => 'Ophthalmologist — Clínica Visão SP',
                'initials' => 'RM',
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
        // Modules not gated by plan (checked in the routes: only inventory is per plan).
        'included_all_label' => 'On every plan',
        'included_all'       => 'Scheduling, ophthalmology record, image manager, reports and documents, TISS billing and finances.',
        // Plans after the first list only what they add.
        'everything_in' => 'Everything in :plan, plus:',
        // Optotypes do not exist in the product yet: always "Coming soon", only on the Premium card.
        'upcoming_label' => 'Coming soon to Premium',
        'upcoming'       => [
            ['icon' => 'ti-eye', 'title' => 'Full visual acuity testing suite', 'badge' => 'Coming soon'],
        ],
    ],

    'faq' => [
        'label' => 'FAQ',
        'title' => 'Frequently asked questions',
        'items' => [
            ['q' => 'How is my clinic data migrated?', 'a' => 'We offer CSV import for patients and history. Our implementation team helps migrate data from your previous system without interrupting your operations.'],
            // Content fix — the old answer claimed a contingency local
            // cache that never existed in the product (confirmed in code:
            // no service worker/localStorage/IndexedDB stores
            // schedule/record data for offline use, only a UI preference
            // such as the schedule view mode). Answer corrected to match
            // actual behavior.
            ['q' => 'Does EasyEye work offline?', 'a' => 'EasyEye is a 100% cloud solution — it works on any device with a browser and an internet connection. There is no offline mode at the moment: without a connection, you cannot access patient records, the schedule, or any other system data.'],
            ['q' => 'How does technical support work?', 'a' => 'We offer support by email and WhatsApp (on specific plans). Pro and Premium plans get priority support, with a 4-business-hour SLA.'],
            ['q' => 'Is the system approved by ANS for TISS?', 'a' => 'Yes. EasyEye is approved for ANS TISS versions 3.05 and 3.06, with fully automated XML generation, submission and return processing.'],
            ['q' => 'Can I integrate with other systems?', 'a' => 'We provide a REST API for integration with ERPs, imaging systems (reports), laboratories and other clinical systems. Developer documentation available.'],
        ],
    ],

    'cta' => [
        'title' => 'Ready to transform your ophthalmology clinic?',
        // With a free trial (days from the database) or without one.
        'subtitle_trial' => ':days days free, no credit card required. Set up in less than a day.',
        'subtitle'       => 'No credit card required. Set up in less than a day.',
        'primary'        => 'Create free account',
        'secondary'      => 'Talk to an expert',
        'note'           => 'No implementation fee • Cancel anytime • Onboarding support',
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
            'title' => 'Technical Support',
            'desc'  => 'Email support with a plan-based SLA. Pro and Premium plans get priority.',
            'cta'   => 'Send email',
            'hours' => 'Mon–Fri, 8am to 6pm',
            // Email comes from config('mail.support_address') (contact.support prop).
        ],
        'trial' => [
            'title'         => 'Start for free',
            'desc'          => ':days days with no credit card required. Set up your clinic in less than a day and start seeing patients with digital records.',
            'desc_no_trial' => 'No credit card required. Set up your clinic in less than a day and start seeing patients with digital records.',
            'cta'           => 'Create free account',
            'badge'         => 'Most popular',
            'note'          => 'No implementation fee',
        ],

        'aside' => [
            'quote_text'   => 'EasyEye support resolved our issue in under an hour. Team is extremely knowledgeable about ophthalmology.',
            'quote_author' => 'Dr. Mariana Costa — Clínica Visão SP',
        ],

        'form' => [
            'title'          => 'Send us a message',
            'subtitle'       => 'Fill out the form and we will get back to you within 1 business day.',
            'name'           => 'Full name',
            'name_ph'        => 'Your name',
            'email'          => 'Email',
            'email_ph'       => 'you@example.com',
            'phone'          => 'WhatsApp (with area code)',
            'phone_ph'       => '(00) 00000-0000',
            'message'        => 'Message',
            'message_ph'     => 'How can we help?',
            'message_hint'   => 'Up to 5,000 characters. Do not include patient data.',
            'is_client'      => 'Are you a customer?',
            'is_client_opts' => ['Yes', 'No', 'Former customer'],
            'role'           => 'Role',
            'role_opts'      => ['Ophthalmologist', 'Clinic Manager', 'Administrative', 'IT / Technology', 'Other'],
            'segment'        => 'Type of practice',
            'segment_opts'   => ['Solo practice', 'Ophthalmology clinic', 'Clinic network', 'Hospital / Outpatient', 'Health plan', 'Other'],
            'select'         => 'Select',
            'terms'          => 'I have read and agree to the <a href="/privacidade" target="_blank">Privacy Policy</a> and authorize EasyEye to contact me.',
            'submit'         => 'Send message',
            'sending'        => 'Sending...',
            'success_title'  => 'Message sent!',
            'success_body'   => 'Our team will get back to you within 1 business day. Keep an eye on your inbox!',
            'mail_subject'   => 'New message from the EasyEye website',
            'errors'         => [
                'required'   => 'Fill out this field.',
                'email'      => 'Enter a valid email address.',
                'terms'      => 'Confirm that you have read and agree to the terms before sending.',
                'invalid'    => 'Check the value entered in this field.',
                'validation' => 'Review the highlighted fields and send again.',
                'server'     => 'We could not confirm your message was sent. Your details have been kept. Please try again shortly.',
                'network'    => 'We could not confirm your message was sent. Check your connection and try again. Your details have been kept.',
                'timeout'    => 'Sending took longer than expected and could not be confirmed. Your details have been kept so you can try again.',
                'session'    => 'Your session has expired. Copy your message and details before refreshing the page and trying again.',
                'rate_limit' => 'Too many attempts in a short time. Wait one minute and try again. Your details have been kept.',
            ],
        ],

        'trust_ssl'  => 'SSL Encryption',
        'trust_lgpd' => 'LGPD Compliant',
        'trust_cfm'  => 'CFM Approved',
        'trust_nps'  => '97% satisfaction',
    ],
];
