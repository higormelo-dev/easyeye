<?php

/*
 * Clinical review (Phase 6, ADR 0002): state/decision/acceptance labels, the invitation e-mail and
 * the public Reviewer page texts (`public`). The e-mail is ALWAYS sent in Portuguese (Reviewers are
 * Brazilian physicians); this file exists for parity, for the Manager and for a Reviewer who prefers
 * to read the page in English.
 */

return [
    'status' => [
        'pending'   => 'Awaiting opinion',
        'submitted' => 'Opinion received',
        'accepted'  => 'Accepted',
        'refused'   => 'Acceptance refused',
        'expired'   => 'Expired',
        'revoked'   => 'Revoked',
    ],

    'decision' => [
        'approved'          => 'I approve',
        'changes_requested' => 'I approve with changes',
        'rejected'          => 'I do not approve',
    ],

    'acceptance' => [
        'accepted' => 'Identity verified',
        'refused'  => 'Identity not verified',
    ],

    'mail' => [
        'subject'        => 'EasyEye — invitation for clinical review of return protocols',
        'greeting'       => 'Hello, :name.',
        'intro'          => 'EasyEye asks for your clinical review of the patient-return rules below before the clinics start using them.',
        'versions_title' => 'Versions to review:',
        'version_line'   => ':protocol — version :version',
        'action'         => 'Open the review',
        'expires'        => 'The link is personal, valid until :date, and must not be forwarded.',
        'pin'            => 'This invitation requires an access code, which EasyEye sent you through another channel.',
        'privacy'        => 'The page shows no patient data. When you submit your opinion, your identification data, IP address and the date and time are recorded as proof of the validation.',
        'unsolicited'    => 'If you were not expecting this e-mail you can ignore it: nobody can reach the review without the link.',
        'salutation'     => 'EasyEye team',
    ],

    'receipt' => [
        'subject'       => 'EasyEye — receipt for your clinical opinion',
        'intro'         => 'We received your opinion on :when. This is your receipt.',
        'decision_line' => ':protocol — version :version: :decision',
        'code'          => 'Submission verification code: :code',
        'next'          => 'EasyEye will check your CRM/state and RQE on the Federal Council of Medicine portal before releasing any rule. Please keep this e-mail.',
        'unrecognized'  => 'If you do not recognize this submission, reply to this e-mail urgently.',
    ],

    /*
     * Public Clinical Reviewer page (`/revisao-clinica`). The OFFICIAL text of the statement
     * the Reviewer signs is always the Portuguese one (resources/legal) — the translation only
     * helps reading.
     */
    'public' => [
        'layout' => [
            'skip'           => 'Skip to content',
            'tagline'        => 'Clinical review',
            'language'       => 'Language',
            'theme_dark'     => 'Use dark theme',
            'theme_light'    => 'Use light theme',
            'footer_privacy' => 'This page does not show patient data.',
        ],

        'show' => [
            'title'         => 'Clinical review of follow-up protocols',
            'greeting'      => 'Hello, :name.',
            'lead'          => 'EasyEye asks for your clinical review of the rules below before clinics start using them. Read each rule, say whether you agree and identify yourself at the end. You can stop and come back through the same link: what you type is saved automatically.',
            'valid_until'   => 'Personal link, valid until :date. Do not forward it to anyone else.',
            'privacy_title' => 'About your data.',
            'privacy_body'  => 'This page does not show patient data. When you submit your opinion, your name, CRM/state, RQE, e-mail, IP address and the date and time are recorded as proof of the validation.',
            'unavailable'   => 'One of the versions in this invitation is no longer under review and the opinion can no longer be submitted. EasyEye will get in touch.',
            'errors_title'  => ':count item left to fix|:count items left to fix',
            'review'        => 'Review and submit',
            'back'          => 'Go back and edit',
            'send'          => 'Confirm and submit the opinion',
            'sending'       => 'Submitting…',
            'confirm_title' => 'Check before submitting',
            'confirm_lead'  => 'Read the summary below. Once submitted, the opinion cannot be changed.',
        ],

        'item' => [
            'version'              => 'Version :version',
            'hash'                 => 'Code :hash',
            'unavailable'          => 'This version is no longer under review: you cannot decide on it.',
            'parent_validated'     => 'Derived from version :version, which has already been validated.',
            'parent_not_validated' => 'Derived from version :version, which has not been validated yet.',
            'decision_legend'      => 'Your decision on :title (version :version)',
            'decision_hint'        => [
                'approved'          => 'The rules can be used as they are.',
                'changes_requested' => 'I agree with the direction, but I ask for changes (explain below).',
                'rejected'          => 'I do not recommend using these rules.',
            ],
            'comment_required'      => 'Justification',
            'comment_optional'      => 'Comment (optional)',
            'comment_hint_required' => 'Explain the decision (at least :min characters).',
            'comment_hint_optional' => 'If you wish, leave a note.',
            'proposed_label'        => 'What you would change (optional)',
            'proposed_hint'         => 'For example: "visual field every 9 months". EasyEye prepares a new version and sends it to you for review.',
            'field_decision'        => 'Decision',
            'field_comment'         => 'Justification',
            'field_proposed'        => 'Suggested change',
        ],

        'attestation' => [
            'title'                 => 'Your identification and statement',
            'intro'                 => 'Type your professional details. EasyEye checks them on the Federal Council of Medicine (CFM) portal before releasing any rule.',
            'name'                  => 'Full name',
            'email'                 => 'E-mail',
            'crm_number'            => 'CRM (number)',
            'crm_uf'                => 'CRM state',
            'rqe'                   => 'RQE (number)',
            'specialty'             => 'Specialty',
            'select'                => 'Select',
            'conflict_title'        => 'Conflict of interest',
            'conflict_hint'         => 'For example: a financial tie to a manufacturer, health plan or competitor that could influence your review.',
            'conflict_no'           => 'I have no conflict of interest to declare',
            'conflict_yes'          => 'I have a conflict of interest to declare',
            'conflict_description'  => 'Describe the conflict of interest',
            'display_title'         => 'Identification to clinics',
            'display_hint'          => 'When a clinic adopts a validated rule, it can see who validated it.',
            'display_yes'           => 'I authorize showing my name and CRM/state to clinics',
            'display_no'            => 'I prefer that my identity is not shown to clinics',
            'statement_title'       => 'Clinical Reviewer statement',
            'statement_translation' => 'Translation for reading. The official text you sign is the Portuguese one; the code below identifies exactly that text.',
            'statement_code'        => 'Statement code: :code',
            'statement_official'    => 'View the official text (Portuguese)',
            'agreed'                => 'I have read and agree with the statement above',
        ],

        'summary' => [
            'decisions'        => 'Your decisions',
            'proposed'         => 'Suggested change:',
            'identity'         => 'Your identification',
            'crm'              => 'CRM / state',
            'conflict'         => 'Conflict of interest',
            'conflict_yes'     => 'Declared',
            'conflict_no'      => 'None',
            'display'          => 'Identity shown to clinics',
            'display_yes'      => 'Authorized',
            'display_no'       => 'Not authorized',
            'statement'        => 'Statement',
            'statement_agreed' => 'Read and accepted (code :code)',
            'final_title'      => 'One-time submission.',
            'final_body'       => 'When you confirm, the opinion, your identification, the IP address and the date and time are recorded and cannot be changed.',
        ],

        'draft' => [
            'auto'         => 'What you type is saved automatically on this server.',
            'saving'       => 'Saving the draft…',
            'saved'        => 'Draft saved at :time.',
            'error'        => 'The draft could not be saved right now. Your text is still on screen; we will try again.',
            'save_now'     => 'Save draft now',
            'ended'        => 'The link is no longer valid (session ended, invitation replaced or expired). What you typed on this screen will not be saved anymore — copy the text if you need it.',
            'ended_action' => 'Open the link page',
            'ended_short'  => 'The draft is no longer being saved.',
        ],

        'code' => [
            'title'   => 'Access code',
            'body'    => 'This invitation requires an access code. EasyEye sent it to you through another channel (phone or message) — it does not come in the e-mail.',
            'label'   => 'Access code',
            'hint'    => 'Letters and numbers; upper and lower case are the same.',
            'submit'  => 'Continue',
            'invalid' => 'Wrong code or temporarily blocked. Check the code and, if you got it wrong several times, wait a few minutes.',
        ],

        'done' => [
            'title'           => 'Opinion submitted',
            'thanks'          => 'Thank you, :name. We received your opinion.',
            'sent_at'         => 'Submitted on',
            'code'            => 'Verification code',
            'code_hint'       => 'Identifies this submission. Keep it: it is proof that the opinion was received.',
            'copy'            => 'Copy',
            'copied'          => 'Copied',
            'receipt'         => 'E-mail receipt',
            'receipt_sent'    => 'Sent to the e-mail you declared.',
            'receipt_pending' => 'It could not be sent yet. The code above works as the receipt.',
            'decisions'       => 'Your decisions',
            'version'         => 'version :version',
            'next_title'      => 'What now?',
            'next_body'       => 'EasyEye checks your CRM/state and RQE on the Federal Council of Medicine portal. Only after that check can the approved rules be released to clinics. If you asked for changes, you will receive a new invitation.',
            'close'           => 'You can close this page now.',
        ],

        'unavailable' => [
            'title'           => 'This link is not available',
            'body'            => 'The review could not be opened from this address.',
            'reason_expired'  => 'The invitation period may have ended.',
            'reason_replaced' => 'A newer link may have been sent in place of this one.',
            'reason_used'     => 'The opinion may already have been submitted.',
            'action'          => 'Look for the most recent e-mail from EasyEye or ask whoever invited you for a new invitation.',
        ],

        'errors' => [
            'decision_required'             => 'Choose a decision.',
            'comment_required'              => 'Explain the decision (at least 10 characters).',
            'comment_too_long'              => 'The comment is over the 3,000-character limit.',
            'proposed_too_long'             => 'The suggested change is over the 6,000-character limit.',
            'name_required'                 => 'Enter your full name.',
            'email_invalid'                 => 'Enter a valid e-mail.',
            'number_required'               => 'Enter the number.',
            'number_invalid'                => 'Enter only the number (dots and hyphens are accepted).',
            'uf_required'                   => 'Choose the CRM state.',
            'specialty_required'            => 'Enter the specialty.',
            'choice_required'               => 'Choose one of the options.',
            'conflict_description_required' => 'Describe the conflict of interest.',
            'agreed_required'               => 'You must agree with the statement to submit.',
            'not_open'                      => 'This invitation no longer accepts answers.',
            'session_expired'               => 'Your session has ended and the opinion was NOT submitted. What you typed is still on the screen: open the link from the e-mail again in another tab and submit from there (the saved draft comes back by itself).',
            'set_changed'                   => 'The versions in this invitation changed since you opened the page. We reloaded the content: check it and submit again.',
            'statement_changed'             => 'The statement was updated. Read the new text, tick that you agree and submit again.',
            'missing_decision'              => 'A decision is missing for one of the versions.',
            'statement_not_accepted'        => 'You must agree with the statement to submit.',
        ],

        'fields' => [
            'decisions'              => 'decisions',
            'decision'               => 'decision',
            'comment'                => 'comment',
            'proposed_changes'       => 'suggested change',
            'name'                   => 'full name',
            'email'                  => 'e-mail',
            'crm_number'             => 'CRM',
            'crm_uf'                 => 'CRM state',
            'rqe'                    => 'RQE',
            'specialty'              => 'specialty',
            'has_conflict'           => 'conflict of interest',
            'conflict_description'   => 'conflict description',
            'allow_identity_display' => 'identification to clinics',
            'agreed'                 => 'agreement with the statement',
            'statement'              => 'statement',
        ],
    ],
];
