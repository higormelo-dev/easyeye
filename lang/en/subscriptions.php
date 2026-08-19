<?php

return [
    'access_blocked' => 'Your subscription is inactive. Please renew to continue using the system.',

    'status' => [
        'trial'     => 'Trial',
        'active'    => 'Active',
        'expired'   => 'Expired',
        'cancelled' => 'Cancelled',
        'past_due'  => 'Past due',
    ],

    'billing_cycle' => [
        'monthly'  => 'Monthly',
        'yearly'   => 'Yearly',
        'lifetime' => 'Lifetime',
    ],

    'expired_page' => [
        'title'           => 'Subscription expired',
        'heading'         => 'Your subscription is inactive',
        'grace_period'    => 'You still have access until :date (grace period). Renew to keep your access.',
        'last_plan'       => 'Last plan',
        'choose_plan'     => 'Choose a plan to continue',
        'most_popular'    => 'Most popular',
        'unlimited'       => 'Unlimited',
        'upgrade_cta'     => 'Get started',
        'contact_support' => 'Questions? Contact us:',
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
        'ai_monthly_credits'        => 'Monthly AI credits',
        'api_monthly_exam_sends'    => 'Integrator exam sends (monthly)',
        'plan_upgrade_required'     => 'Your plan does not include equipment integration. Upgrade to continue.',

        /* Display texts for pricing cards */
        'max_doctors_unlimited'    => 'Unlimited doctors',
        'max_doctors_count'        => 'Up to :n doctor(s)',
        'max_patients_unlimited'   => 'Unlimited patients',
        'max_patients_count'       => 'Up to :n patients',
        'max_users_unlimited'      => 'Unlimited users',
        'max_users_count'          => 'Up to :n users',
        'max_storage_unlimited'    => 'Unlimited storage',
        'max_storage_count'        => ':n GB storage',
        'ai_credits_none'          => 'No AI credits',
        'ai_credits_count'         => ':n AI credits/month',
        'api_exam_sends_unlimited' => 'Unlimited exams within plan storage',
        'api_exam_sends_count'     => 'Up to :n integrator exam sends/month',
        'generic_unlimited'        => 'Unlimited :label',
        'generic_count'            => ':label: :n',
    ],

    'pricing_credit_note' => [
        'title' => 'How do AI credits work?',
        'body'  => 'Each credit corresponds to 1 exam analysis or 1 AI-generated report draft. Credits are renewed monthly and do not carry over between cycles.',
        'topup' => 'Once the plan limit is reached, you can purchase <strong>top-up credit packs</strong> without interrupting your workflow.',
    ],
];
