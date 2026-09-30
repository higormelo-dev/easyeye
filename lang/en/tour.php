<?php

declare(strict_types=1);

/*
 * Guided tour of the clinic panel (driver.js — App\Support\PanelTour and
 * resources/js/composables/usePanelTour.js). Sent as `tour.t`.
 *
 * `nav`: description of each sidebar item by its App\Support\PanelNavigation
 * `key` (the step title is the menu label itself). Items without a
 * description here are left out of the tour. Plain text (no HTML).
 * Relevant content change? Bump PanelTour::VERSION.
 */
return [
    'ui' => [
        'start'    => 'Take the guided tour',
        'next'     => 'Next',
        'previous' => 'Previous',
        'done'     => 'Finish',
        'close'    => 'Close the tour',
        // {{current}} and {{total}} are filled in by driver.js.
        'progress' => '{{current}} of {{total}}',
    ],

    'intro' => [
        'title'       => 'Welcome to EasyEye',
        'description' => 'We will show you, step by step, what each part of the system does. Use the arrow keys to move forward or back and Esc to leave.',
        // Touch screens (no keyboard).
        'description_touch' => 'We will show you, step by step, what each part of the system does. Use the buttons in the balloon to move forward or back and the X to leave.',
    ],

    'mobile_menu' => [
        'title'       => 'Menu',
        'description' => 'After the tour, tap this button to open the menu: it holds the current clinic and every area of the system. Next, what each one does.',
    ],

    'nav' => [
        'dashboard'              => 'The clinic\'s day at a glance: indicators, today\'s live schedule, shortcuts, the latest registered patients and, while required steps are pending, the progress of the initial setup.',
        'schedules'              => 'Doctors\' schedules: book, confirm, reschedule and follow the day\'s appointments, with a waiting list.',
        'patients'               => 'Patient records with visit history, medical records and documents.',
        'doctors'                => 'The clinic\'s doctors, with working hours, blocks and absences, and the details used in documents, such as the medical license.',
        'eye-images'             => 'Images of the exams taken on the ophthalmic equipment, organized by patient.',
        'ai'                     => 'AI assistant: follow usage and the credit balance (administrators also buy credits here) and, if you are a doctor, manage your prompts.',
        'stock'                  => 'Stock: products and lenses, stock in and out, purchases, suppliers, counts and reports.',
        'financial'              => 'Financial: management dashboard, cash flow and cash closing, TISS billing, doctor payouts, price table, denials and reports.',
        'settings-clinical'      => 'Clinic settings: rooms and equipment that can be booked with appointments and the call panel shown on the waiting-room TV.',
        'settings-attendance'    => 'Attendance settings: insurance plans and visit types used in the schedule.',
        'settings-users'         => 'Clinic users, access profiles and whether two-factor authentication is required for every user.',
        'settings-documents'     => 'Templates for clinical documents, such as prescriptions, reports and certificates.',
        'settings-ophthalmology' => 'Ophthalmic parameters: lists used in the patient record and the medical record, such as visual acuity, color vision, lenses and surgery types.',
        'my-payouts'             => 'Your payouts: closings made by the clinic, payments received and the PDF statement.',
    ],

    /*
     * Steps of the current screen, by route (they come after the welcome and
     * before the menu). Each step key is the element's data-tour; the tour
     * follows the order in which the elements appear on the screen (users can
     * reorder sections); elements the user does not see (profile, plan, data)
     * are left out automatically.
     */
    'pages' => [
        'panel.dashboard' => [
            'dashboard-customize' => [
                'title'       => 'Customize the dashboard',
                'description' => 'Choose the order of the sections on this screen (indicators, shortcuts, today\'s schedule, recent patients and, when there are any, stock alerts): drag by the handle or use the arrows. "Restore default" goes back to the original order. The order is saved for you.',
            ],
            'dashboard-live' => [
                'title'       => 'Live updates',
                'description' => 'The numbers and the schedule on this screen refresh on their own every 30 seconds. Here you see the time of the last update and the button to refresh right away.',
            ],
            'dashboard-welcome' => [
                'title'       => 'Welcome',
                'description' => 'A greeting with the clinic\'s name and, if you have access to patients, shortcuts to the patient list and to register a new patient.',
            ],
            'dashboard-activation' => [
                'title'       => 'Set up your clinic',
                'description' => 'Shows how much of the initial setup is done and the steps left, with the weight of each one. The card goes away once the required steps are done; optional ones do not hold it.',
            ],
            'dashboard-kpis' => [
                'title'       => 'Indicators',
                'description' => 'Active patients, appointments booked for today and the clinic\'s active doctors. Click an indicator to open the list, when you have access to that screen. "Surgeries today" is still being prepared ("Coming soon").',
            ],
            'dashboard-kpis-soon' => [
                'title'       => 'Upcoming indicators',
                'description' => 'Indicators being prepared, marked "Coming soon": they do not show numbers yet.',
            ],
            'dashboard-shortcuts-customize' => [
                'title'       => 'Choose shortcuts',
                'description' => 'Choose the shortcuts below: the eye shows or hides each one; drag by the handle or use the arrows to change the order. "Restore default" goes back to the original. Your choice is saved for you.',
            ],
            'dashboard-shortcuts' => [
                'title'       => 'Shortcuts',
                'description' => 'Quick access to the modules your profile can open: Eye Images for everyone, Schedule for those who see or book patients (management, doctors and front desk) and TISS Guides and Financial for management and financial staff. Items marked "Coming soon" are not available yet.',
            ],
            'dashboard-schedule-today' => [
                'title'       => 'Today\'s schedule',
                'description' => 'Today\'s appointments by time, with patient, status and, on larger screens, the doctor. The green icon means the patient has arrived; highlighted rows and the badge next to the title show appointments that are not finished yet. On busy days the list shows the first appointments and tells you the total. The button opens the full schedule, when you have access.',
            ],
            'dashboard-day-summary' => [
                'title'       => 'Day summary',
                'description' => 'Total appointments today and how many were attended, are in progress or waiting, and were cancelled or missed.',
            ],
            'dashboard-recent-patients' => [
                'title'       => 'Recent patients',
                'description' => 'The most recently registered patients and, on larger screens, each one\'s phone and code. Open each record ("View") or the full list ("See all"), when you have access to patients.',
            ],
            'dashboard-stock-alerts' => [
                'title'       => 'Stock alerts',
                'description' => 'Shows up when products are below the minimum stock or have lots that are expired or expiring in the next 30 days. Click an alert to see its products or "View stock" for the full list.',
            ],
        ],
    ],

    'layout' => [
        'sidebar-toggle' => [
            'title'       => 'Collapse or expand the menu',
            'description' => 'Collapses the side menu to icons only, freeing up screen space; use it again to expand. With the menu collapsed, hover over it to see the names.',
        ],
        'entity-switcher' => [
            'title'       => 'Current clinic',
            'description' => 'The top of the side menu shows which clinic you are in. If you work at more than one, switch clinics there. With the menu collapsed, it shows up when you expand the menu.',
        ],
        'locale' => [
            'title'       => 'Language',
            'description' => 'Choose the system language.',
        ],
        'theme' => [
            'title'       => 'Light or dark mode',
            'description' => 'Switch between the light and the dark theme.',
        ],
        'user-menu' => [
            'title'       => 'Your account',
            'description' => 'Edit your profile and sign out safely.',
        ],
        'ai-assistant' => [
            'title'       => 'Virtual assistant',
            'description' => 'Chat with the AI on any screen: ask clinical questions and create documents. In the patient\'s medical record and exams, it can also analyze the case or the exam if you turn on the context. Always check the answers before using them.',
        ],
        'help' => [
            'title'       => 'See the tour again',
            'description' => 'Use this button whenever you want to see the tour again. On the home screen (Dashboard), it also explains each part of the panel.',
        ],
    ],

    'outro' => [
        'title'       => 'All set!',
        'description' => 'Now you know where each area is. Have a great day!',
    ],
];
