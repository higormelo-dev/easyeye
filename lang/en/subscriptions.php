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
        'max_users'              => 'Maximum users',
        'max_patients'           => 'Maximum patients',
        'max_doctors'            => 'Maximum doctors',
        'max_storage_gb'         => 'Storage (GB)',
        'has_ai_exam_assistant'  => 'AI exam assistant',
        'has_ai_report_drafting' => 'AI report drafting',
        'has_api_integrator'     => 'API integrator access',
        'ai_monthly_credits'     => 'Monthly AI credits',
        'api_monthly_exam_sends' => 'API exam sends (monthly)',
    ],
];
