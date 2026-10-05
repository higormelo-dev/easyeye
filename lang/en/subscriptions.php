<?php

return [
    'access_blocked' => 'Your subscription is inactive. Please renew to continue using the system.',
    'access_limited' => 'Subscription payment overdue: access is limited. AI and the financial module are blocked until the payment is confirmed; schedule, patients and medical records remain available.',

    'status' => [
        'trial'     => 'Trial',
        'active'    => 'Active',
        'expired'   => 'Expired',
        'cancelled' => 'Cancelled',
        'past_due'  => 'Past due',
    ],

    // Automatic billing subscription still without its first payment (not "past due").
    'status_awaiting_first_payment' => 'Awaiting first payment',

    'billing_cycle' => [
        'monthly'    => 'Monthly',
        'quarterly'  => 'Quarterly',
        'semiannual' => 'Semiannual',
        'yearly'     => 'Yearly',
        'lifetime'   => 'Lifetime',
    ],

    // Price suffix per billing cycle ("R$ 299.90/month").
    'billing_period' => [
        'monthly'    => '/month',
        'quarterly'  => '/quarter',
        'semiannual' => '/6 months',
        'yearly'     => '/year',
        'lifetime'   => '/lifetime',
    ],

    // How the subscription is paid.
    'billing_mode' => [
        'gateway'       => 'Automatic billing',
        'complimentary' => 'Complimentary',
    ],

    'expired_page' => [
        'title'              => 'Subscription expired',
        'heading'            => 'Your subscription is inactive',
        'heading_trial'      => 'Your free trial has ended',
        'heading_payment'    => 'Waiting for payment',
        'blocked_trial'      => 'The free trial for :name has ended. Choose a plan to keep using EasyEye.',
        'blocked_payment'    => 'Access for :name comes back as soon as the subscription payment is confirmed.',
        'last_plan'          => 'Last plan',
        'choose_plan'        => 'Choose a plan to continue',
        'most_popular'       => 'Most popular',
        'unlimited'          => 'Unlimited',
        'upgrade_cta'        => 'Get started',
        'contact_support'    => 'Questions?',
        'contact_link'       => 'Talk to our team',
        'blocked_entity'     => 'Access for :name is blocked. Renew to keep using EasyEye.',
        'blocked_generic'    => 'Access to the system is blocked. Renew to keep using EasyEye.',
        'ended_on'           => 'ended on :date',
        'due_on'             => 'was due on :date',
        'no_plans'           => 'No plans available right now. Please contact support.',
        'monthly_equivalent' => 'works out to :price/month',
        'savings'            => 'Save :percent%',
        'logout'             => 'Log out',

        // Limited access (paying customer overdue within the dunning schedule).
        'heading_limited'           => 'Access limited due to overdue payment',
        'blocked_limited'           => 'The subscription payment for :name is overdue. Some features are blocked until the payment is confirmed.',
        'limited_blocked_title'     => 'Blocked until payment',
        'limited_blocked_ai'        => 'Artificial intelligence (analyses, assistant and credits)',
        'limited_blocked_financial' => 'Financial module (cash flow, TISS billing, denials, payouts and financial reports)',
        'limited_allowed'           => 'Schedule, patients and medical records remain available.',
        'limited_deadline'          => 'Without payment, access to the panel will be suspended on :date.',
        'back_to_panel'             => 'Back to the panel',

        // Open charge with a payment link.
        'payment_title' => 'Open charge',
        'payment_due'   => ':amount — due on :date',
        'payment_hint'  => 'Access is restored automatically as soon as the payment is confirmed.',
        'pay_now'       => 'Pay now',
        'opens_new_tab' => '(opens in a new tab)',
        // No link to the charge (the gateway does not return one, or it has not arrived yet).
        'no_link' => 'The payment link for this charge is not available here. To pay, please talk to our team.',
        // Roles without billing access (only admin, finance and owner see amount and link).
        'ask_admin' => 'To settle the payment, contact your clinic administrator.',
    ],

    // Notice at the top of the panel (AppLayout) — subscription status.
    'banner' => [
        'pay_now'     => 'Pay now',
        'choose_plan' => 'Talk to sales',
        // Trial ending, seen by whoever pays: subscribe inside the system.
        'subscribe_now' => 'Subscribe now',
        // Payment notice without the charge link: the way forward is talking to the team.
        'contact'             => 'Talk to our team',
        'no_link'             => 'The link for this charge is not available here; to pay, please talk to our team.',
        'dismiss'             => 'Dismiss notice',
        'opens_new_tab'       => '(opens in a new tab)',
        'first_payment_title' => 'Payment pending',
        'first_payment_body'  => 'The first subscription charge is due on :date. Without payment, access to the panel is suspended at the end of that day.',
        'overdue_title'       => 'Payment overdue',
        'overdue_body'        => 'The subscription payment is overdue :days. On :limited_date, AI and the financial module will be blocked; on :blocked_date, access to the panel will be suspended.',
        'limited_title'       => 'Limited access',
        'limited_body'        => 'Payment overdue :days: AI and the financial module are blocked. Schedule, patients and medical records remain available. On :blocked_date, access to the panel will be suspended.',
        'days_overdue'        => 'by :days day|by :days days',
        'days_overdue_today'  => 'since today',
        'trial_title'         => 'Free trial ending',
        'trial_body'          => 'Your free trial ends on :date (:days). Choose a plan to keep using EasyEye.',
        'trial_days_left'     => ':days day left|:days days left',
        'trial_today'         => 'ends today',
        // Roles without billing access (only admin, finance and owner see amount and link).
        'ask_admin' => 'Ask your clinic administrator to settle the payment.',
    ],

    'feature_not_included'  => 'The ":feature" feature is not available on your current plan. Upgrade to continue.',
    'feature_limit_reached' => 'The ":feature" limit has been reached (:limit). Upgrade your plan to continue.',

    'features' => [
        'max_users'                 => 'Maximum users',
        'max_patients'              => 'Maximum patients',
        'max_doctors'               => 'Maximum doctors',
        'max_storage_gb'            => 'Storage (GB)',
        'has_ai_exam_assistant'     => 'AI exam assistant',
        'has_ai_report_drafting'    => 'AI report drafting',
        'has_ai_consensus'          => 'Intelligent consistency review',
        'has_ai_eye_image_analysis' => 'AI ocular image analysis',
        'has_ai_chat_assistant'     => 'Virtual AI assistant (floating chat)',
        'has_api_integrator'        => 'Ophthalmic equipment integration',
        'has_inventory_module'      => 'Inventory module',
        'ai_monthly_credits'        => 'Monthly AI credits',
        'api_monthly_exam_sends'    => 'Integrator exam sends (monthly)',
        'plan_upgrade_required'     => 'Your plan does not include equipment integration. Upgrade to continue.',

        /* Display texts for pricing cards */
        'max_doctors_unlimited'    => 'Unlimited doctors',
        'max_doctors_count'        => 'Up to :n doctor|Up to :n doctors',
        'max_patients_unlimited'   => 'Unlimited patients',
        'max_patients_count'       => 'Up to :n patient|Up to :n patients',
        'max_users_unlimited'      => 'Unlimited users',
        'max_users_count'          => 'Up to :n user|Up to :n users',
        'max_storage_unlimited'    => 'Unlimited storage',
        'max_storage_count'        => ':n GB storage|:n GB storage',
        'ai_credits_none'          => 'No AI credits',
        'ai_credits_count'         => ':n AI credit per month|:n AI credits per month',
        'api_exam_sends_unlimited' => 'Unlimited exams within plan storage',
        'api_exam_sends_count'     => 'Up to :n integrator exam send per month|Up to :n integrator exam sends per month',
        'generic_unlimited'        => 'Unlimited :label',
        'generic_count'            => ':label: :n',
    ],

    'pricing_credit_note' => [
        'title'         => 'AI in your plan: what uses credits',
        'intro'         => 'The AI features in your plan use the same credit balance, shared by the doctors in your clinic.',
        'actions_title' => 'Analysis and drafts',
        'actions_body'  => 'Exam analysis and report drafts use credits when these features are included in your plan.',
        'chat_title'    => 'Questions and writing in the assistant',
        'chat_body'     => 'Every question or writing request in the virtual assistant also uses this balance. Conversations are not unlimited.',
        'usage_title'   => 'Variable usage',
        'usage_body'    => 'One request may use more than one credit, depending on the task and the processing required.',
        'renewal_title' => 'Plan allowance',
        'renewal_body'  => 'The plan allowance is granted when the paid subscription becomes active and renewed every month, also on quarterly, semiannual and yearly plans. Unused credits from this allowance do not carry over.',
        'topup'         => 'Purchased extra credits carry over, never expire and are used after the plan allowance. Without enough credits, only AI features become unavailable until you top up or the allowance renews.',
        'trial_note'    => 'The plan allowance is not granted during the trial period or on complimentary subscriptions.',
        'medical_note'  => 'AI provides support. The doctor must review the generated content.',
    ],
];
