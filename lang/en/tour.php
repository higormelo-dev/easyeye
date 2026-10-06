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
        'dashboard'              => 'Your day at a glance: indicators, today\'s live schedule, shortcuts and recent patients — doctors only see their own (next patient and to-dos) and each profile only what it can open. While required steps are pending, the administrator sees the progress of the initial setup.',
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
            'dashboard-welcome' => [
                'title'       => 'Your workstation',
                'description' => 'The dashboard opens on your role\'s workstation — "My practice" (doctor), "Front desk", "Management", "Finance" or "Overview" — with today\'s date and your everyday actions: new appointment and new patient for the front desk and management, start appointment for doctors, new cash entry for finance. Only what you can open is shown.',
            ],
            'dashboard-live' => [
                'title'       => 'Live updates',
                'description' => 'Today\'s operation (schedule, waiting room, confirmations, today\'s cash) refreshes on its own every 30 seconds. The month figures and trends do not: they load when you open the dashboard (kept for up to 10 minutes) and the refresh button recalculates everything right away.',
            ],
            'dashboard-customize' => [
                'title'       => 'Customize the dashboard',
                'description' => 'Choose the order of the sections on this screen and hide the ones you do not use (the eye shows or hides each one) — each profile only sees its own sections. Drag by the handle or use the arrows; "Restore default" goes back to the original. Your choice is saved for you.',
            ],
            'dashboard-activation' => [
                'title'       => 'Set up your clinic',
                'description' => 'Shows how much of the initial setup is done and the steps left, with the weight of each one. The card goes away once the required steps are done; optional ones do not hold it.',
            ],
            'dashboard-next-patient' => [
                'title'       => 'Next patient',
                'description' => 'Doctors only: who has arrived and is waiting for you (first those ready for the appointment, then those dilating or in exams, in order of arrival) or, if nobody has arrived, the next booked time. "Start appointment" opens the appointment\'s medical record.',
            ],
            'dashboard-kpis' => [
                'title'       => 'Indicators',
                'description' => 'The numbers of your workstation. Doctor: today\'s appointments, who is waiting for you, your appointments and no-show rate this month, unreported exams and AI reports to review. Front desk: today\'s appointments, who is waiting, confirmed today, tomorrow\'s appointments, waiting list. Management and finance: the month to date compared with the SAME period of last month (e.g. Oct 1–6 × Sep 1–6) — the arrow shows the change and the color tells whether it is good (green) or bad (red): no-shows going up is bad, revenue going up is good. Click an indicator to open the list, when you have access to that screen.',
            ],
            'dashboard-finance-today' => [
                'title'       => 'Today\'s cash',
                'description' => 'Finance only: income and expenses paid today, the day balance and what is still due today. "Open cash book" takes you to the cash flow on today\'s date.',
            ],
            'dashboard-receivables' => [
                'title'       => 'Receivables',
                'description' => 'Today\'s position: pending income entries in the cash book (not yet due and overdue) and insurance claims submitted and awaiting payment (overdue ones shown separately). Each line opens the list with the same filter.',
            ],
            'dashboard-glosas' => [
                'title'       => 'Claim denials to handle',
                'description' => 'Open denials and those under appeal, highlighting the ones whose appeal deadline has passed or ends in the next few days. Click to open the denial queue already filtered.',
            ],
            'dashboard-trends' => [
                'title'       => 'Trends',
                'description' => 'Management: appointments attended × no-shows per day over the last 30 days and revenue × expenses over the last 6 months (the same chart as the BI). Finance: revenue × expenses and billed × received this month per insurer. "View data" shows the table with the numbers.',
            ],
            'dashboard-schedule-today' => [
                'title'       => 'Today\'s schedule',
                'description' => 'Today\'s appointments split into shift tabs (Morning until 1 PM, Afternoon until 6 PM and Evening), with times grouped by hour. The current shift opens on its own; the current hour is highlighted as "Now". Each row shows time, status, patient (with appointment type, insurer and arrival time) and, on larger screens, the doctor — doctors only see their own. The green icon means the patient has arrived. The button opens the full schedule on the tab\'s shift.',
            ],
            'dashboard-waiting-room' => [
                'title'       => 'Waiting room',
                'description' => 'Front desk only: who has arrived and is waiting now, in order of arrival, with the doctor, the status and how long they have been waiting (amber from 30 minutes, red from 1 hour).',
            ],
            'dashboard-day-summary' => [
                'title'       => 'Day summary',
                'description' => 'Today\'s booked, attended, still to be seen and no-shows/cancellations. Progress counts the attended over what still counts ("3 of 14 attended") — no-shows and cancellations are left out and shown separately —, with the breakdown by shift. Doctors only see their own ("My day").',
            ],
            'dashboard-doctors-today' => [
                'title'       => 'Appointments per doctor',
                'description' => 'Management only: per doctor, how many were attended out of today\'s expected, how many patients are in the clinic and the no-shows.',
            ],
            'dashboard-confirmations' => [
                'title'       => 'Confirmations',
                'description' => 'Front desk only: today\'s and tomorrow\'s appointments confirmed × not confirmed, the WhatsApp confirmation status of the remaining ones (awaiting reply, failed, not sent), appointments per shift and the "Call to confirm" list, with the phone number to dial. For today only times that have not passed are listed.',
            ],
            'dashboard-waitlist' => [
                'title'       => 'Waiting list',
                'description' => 'How many patients are waiting for a slot and the first ones on the list (same order as the Schedule panel), with doctor, preferred period and phone.',
            ],
            'dashboard-birthdays' => [
                'title'       => 'Today\'s birthdays',
                'description' => 'Clinic patients who have a birthday today, with their age and phone — only for those with access to Patients.',
            ],
            'dashboard-ai-waiting' => [
                'title'       => 'AI reports waiting for approval',
                'description' => 'Doctors only, when the clinic uses AI: AI analyses you requested or of your exams and records that are waiting for your review. "Review" opens the exam in Eye Images (or the AI screen), where you approve or reject it. With nothing pending it becomes an "all clear" line.',
            ],
            'dashboard-unsigned-records' => [
                'title'       => 'Unsigned medical records',
                'description' => 'Doctors only: your medical records from the last 30 days that have not been signed yet, with the total and the most recent ones. "Open" takes you to the record. With nothing pending it becomes an "all clear" line.',
            ],
            'dashboard-recent-patients' => [
                'title'       => 'Recent patients',
                'description' => 'For front desk and management, the most recently registered patients, with phone and code. For doctors, the last patients they saw, with the date of the last visit. Open each record ("View") or the full list ("See all").',
            ],
            'dashboard-stock-alerts' => [
                'title'       => 'Stock alerts',
                'description' => 'Shows up when products are below the minimum stock or have lots that are expired or expiring in the next 30 days. Click an alert to see its products or "View stock" for the full list.',
            ],
            'dashboard-shortcuts-customize' => [
                'title'       => 'Choose shortcuts',
                'description' => 'Choose the shortcuts below: the eye shows or hides each one; drag by the handle or use the arrows to change the order. "Restore default" goes back to the original. Your choice is saved for you.',
            ],
            'dashboard-shortcuts' => [
                'title'       => 'Shortcuts',
                'description' => 'Quick access to the modules your profile can open: Schedule and Patients for those who see or book patients, Eye Images for everyone and Financial, TISS Guides, Claim denials and BI for management and finance (who see them first). "Surgical Center" ("Coming soon") only shows up for management and front desk.',
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
