<?php

declare(strict_types = 1);

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
    'session_expiring_title'   => 'Session about to expire',
    'session_expiring_html'    => 'Your session will expire in <strong id="swal-session-countdown">2:00</strong> due to inactivity.',
    'session_stay'             => 'Stay connected',
    'session_logout'           => 'Log out now',

    'register' => [
        'title'              => 'Create your account',
        'step_personal'      => 'Your data',
        'step_company'       => 'Company & Plan',
        'name'               => 'Name',
        'email'              => 'E-mail',
        'password'           => 'Password',
        'confirm_password'   => 'Confirm Password',
        'company_name'       => 'Company Name',
        'cnpj'               => 'CNPJ',
        'optional'           => 'optional',
        'choose_plan'        => 'Choose a plan',
        'trial_note'         => 'Start your free trial — no credit card required.',
        'next'               => 'Next',
        'back'               => 'Back',
        'create_account'     => 'Create account',
        'processing'         => 'Processing...',
        'already_registered' => 'Already registered?',
        'log_in'             => 'Log in',
        'email_taken'        => 'This e-mail is already registered.',
        'field_required'     => 'This field is required.',
        'passwords_mismatch' => 'Passwords do not match.',
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
];
