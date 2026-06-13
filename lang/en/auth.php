<?php

declare(strict_types=1);

return [
    'failed'   => 'These credentials do not match our records.',
    'password' => 'The provided password is incorrect.',
    'throttle' => 'Too many login attempts. Please try again in :seconds seconds.',

    'sign_in'         => 'Sign In',
    'sign_up'         => 'Sign Up',
    'log_out'         => 'Log Out',
    'forget_password' => 'Forgot your password?',
    'remember_me'     => 'Remember Me',
    'back_to_login'   => 'Back to login',
    'select_entity'   => 'Select company',
    'login_subtitle'  => 'Please enter below details to access the dashboard',
    'no_account_yet'  => 'Don\'t have an account yet?',

    'inactive'                 => 'User is not active',
    'integrator_invalid'       => 'Invalid integrator code or inactive',
    'token_not_provided'       => 'Token not provided',
    'integrator_inactive'      => 'Integrator inactive',
    'entity_inactive'          => 'Entity inactive',
    'token_valid'              => 'Token is valid',
    'token_renewed'            => 'Token automatically renewed',
    'token_invalid'            => 'Invalid token',
    'token_expired'            => 'Token expired',
    'user_integrator_inactive' => 'User inactive',
    'token_expired_inactivity' => 'Token expired due to inactivity',
    'token_scope_insufficient' => 'The token is not allowed to perform this operation',
    'idempotency_key_invalid'  => 'Invalid Idempotency-Key (use 8 to 128 chars: letters, digits, hyphen or underscore)',
    'idempotency_in_progress'  => 'A request with this Idempotency-Key is still being processed',
    'session_expiring_title'   => 'Session about to expire',
    'session_expiring_html'    => 'Your session will expire in <strong id="swal-session-countdown">2:00</strong> due to inactivity.',
    'session_stay'             => 'Stay connected',
    'session_logout'           => 'Log out now',

    'register' => [
        /* meta */
        'meta_title'       => ':app — Start for free',
        'meta_description' => 'Create your EasyEye account and get started for free. :days-day free trial, no credit card required.',

        /* left panel */
        'left_headline'         => 'Start transforming',
        'left_headline_em'      => 'your clinic today',
        'left_sub'              => 'Complete management for ophthalmology clinics. Up and running in less than a day.',
        'benefit_trial_title'   => ':days days free, no credit card',
        'benefit_trial_text'    => 'Try everything without commitment. Cancel anytime.',
        'benefit_setup_title'   => 'Ready in less than 1 day',
        'benefit_setup_text'    => 'No installation, no servers. 100% cloud-based.',
        'benefit_support_title' => 'Dedicated onboarding support',
        'benefit_support_text'  => 'Our team walks you through from day one.',
        'benefit_lgpd_title'    => 'CFM & LGPD compliant by default',
        'benefit_lgpd_text'     => 'Built in compliance with CFM, ANS and data protection law.',
        'testimonial_text'      => 'EasyEye transformed our clinic. TISS billing that used to take days now takes hours.',
        'testimonial_name'      => 'Dr. Ricardo Mendes',
        'testimonial_role'      => 'Ophthalmologist — Clínica Visão SP',

        /* mobile banner */
        'days_free'  => 'days free',
        'no_card'    => 'No credit card',
        'setup_fast' => 'Setup in under a day',

        /* card header */
        'step1_title'    => 'Create your account',
        'step1_subtitle' => ':days days free · no credit card required',
        'step2_title'    => 'Company details',
        'step2_subtitle' => 'Almost there! Set up your clinic to get started.',

        /* fields and actions */
        'title'              => 'Create your account',
        'step_personal'      => 'Your details',
        'step_company'       => 'Company & Plan',
        'name'               => 'Full name',
        'email'              => 'E-mail',
        'password'           => 'Password',
        'confirm_password'   => 'Confirm password',
        'company_name'       => 'Clinic / company name',
        'cnpj'               => 'CNPJ',
        'optional'           => 'optional',
        'choose_plan'        => 'Choose a plan',
        'trial_note'         => 'Start your free trial — no credit card required.',
        'next'               => 'Next',
        'back'               => 'Back',
        'create_account'     => 'Create account',
        'start_trial'        => 'Start free trial',
        'processing'         => 'Processing...',
        'already_registered' => 'Already have an account?',
        'log_in'             => 'Log in',
        'or'                 => 'or',
        'quick_start'        => 'Start with plan',

        /* additional fields */
        'phone' => 'Phone',

        /* left panel metrics */
        'metric_clinics' => 'clinics',

        /* JS validations */
        'email_taken'        => 'This e-mail is already registered.',
        'field_required'     => 'This field is required.',
        'passwords_mismatch' => 'Passwords do not match.',

        /* password strength */
        'strength_very_weak'   => 'Very weak',
        'strength_weak'        => 'Weak',
        'strength_fair'        => 'Fair',
        'strength_strong'      => 'Strong',
        'strength_very_strong' => 'Very strong',
    ],

    'forgot_password' => [
        'title'       => 'Reset Password',
        'description' => 'Forgot your password? No problem. Just let us know your email address and we will email you a password reset link.',
        'send_link'   => 'Email Password Reset Link',
    ],

    'reset_password' => [
        'title'            => 'New Password',
        'email'            => 'Email',
        'password'         => 'New Password',
        'confirm_password' => 'Confirm New Password',
        'submit'           => 'Reset Password',
    ],

    'verify_email' => [
        'title'       => 'Verify Email',
        'description' => 'Thanks for signing up! Before getting started, could you verify your email address by clicking on the link we just emailed to you? If you didn\'t receive the email, we will gladly send you another.',
        'link_sent'   => 'A new verification link has been sent to your email address.',
        'resend'      => 'Resend Verification Email',
    ],

    'panel_fp' => [
        'title'         => 'Recover your access',
        'subtitle'      => 'In just a few minutes you will be back in your clinic.',
        'step_1'        => 'Enter the email address linked to your account',
        'step_2'        => 'Receive the reset link in your inbox',
        'step_3'        => 'Create a new password and sign in',
        'security_note' => 'Link valid for 60 minutes',
        'link_expiry'   => 'Link expires in 60 min',
    ],

    'panel' => [
        'feature_schedule'   => 'Smart scheduling with automatic confirmation',
        'feature_record'     => 'Complete digital ophthalmology records',
        'feature_tiss'       => 'TISS 3.06 billing, ANS certified',
        'feature_compliance' => 'CFM & LGPD compliance built in',
        'quote_text'         => 'EasyEye transformed our clinic. TISS billing that used to take days now takes hours.',
        'quote_author'       => 'Dr. Ricardo Mendes — Clínica Visão SP',
    ],
];
