<?php

/*
 * Patient Portal — invitation and linking clinics to the account. Each clinic
 * keeps its own patient record; the patient joins the clinics into one account
 * by accepting each clinic's invitation (signed in and confirming the password).
 */
return [
    // Clinic with suspended service: the portal is view-only (never tell the
    // patient why — no payment/subscription talk).
    'read_only' => [
        'badge'          => 'View only',
        'notice'         => 'Online booking and other requests are unavailable right now; please contact the clinic. Your documents remain available to view and download.',
        'action_blocked' => 'This action is unavailable right now. Please contact the clinic.',
    ],

    'invitation' => [
        'already_used'     => 'This invitation has already been used. Sign in with your password.',
        'login_to_link'    => 'You already have a Patient Portal account. Sign in with your password to add :clinic to your account.',
        'linked'           => ':clinic added to your account.',
        'no_email'         => 'Patient has no e-mail on file — please contact the clinic.',
        'account_disabled' => 'Patient Portal access for this e-mail is disabled. Please contact the clinic.',
    ],

    'link' => [
        'page_title'      => 'Add clinic — Patient Portal',
        'title'           => 'Add clinic to your account',
        'intro'           => 'You were invited to see, in your Patient Portal account, the documents shared by:',
        'clinic_fallback' => 'Clinic',
        'account'         => 'Your account',
        'email_mismatch'  => 'This invitation was sent to :email, which is not this account\'s e-mail. For security it cannot be added here: sign out and open the invitation again to create access with that e-mail.',
        'submit'          => 'Add clinic',
        'not_you'         => 'Not your account? Sign out',
    ],
];
