<?php

/*
 * Clinic TISS credentials (Financial > TISS credentials): access to each payer's web service, per environment.
 * Also used by the submission block messages (tiss_send.credential_incomplete).
 */
return [
    'title'      => 'TISS credentials',
    'subtitle'   => "Access to each payer's web service, kept separate per environment. Passwords, tokens and certificates are encrypted and never sent back to the screen.",
    'new'        => 'New credential',
    'empty'      => 'No credentials yet. Start with the homologation one and test the connection before adding the production one.',
    'admin_only' => 'Only clinic administrators can see and change this screen. Every change and test is audited, without the secrets.',

    'transport' => [
        'simulated' => 'Submission in simulation mode (TISS_TRANSPORT_DRIVER=:driver): nothing goes out to the payers and the generated protocol starts with SIMULADO-, which is not an acceptance. Support turns on real submission after validating a payer in homologation.',
        'real'      => 'Real submission is on: batches go out with the :environment credential.',
    ],

    'environments' => [
        'sandbox'    => 'Homologation',
        'production' => 'Production',
    ],

    'missing' => [
        'endpoint' => 'web service address',
        'review'   => 'review by an administrator (the credential was created automatically by billing)',
    ],

    'columns' => [
        'operator'       => 'Payer',
        'environment'    => 'Environment',
        'endpoint'       => 'Submission address',
        'authentication' => 'Authentication',
        'status'         => 'Status',
        'last_test'      => 'Last test',
        'actions'        => 'Actions',
    ],

    'status' => [
        'ready'       => 'Ready',
        'incomplete'  => 'Incomplete: missing :missing',
        'inactive'    => 'Inactive',
        'placeholder' => 'Created by billing, no data yet',
        'ans'         => 'ANS :code',
        'default_url' => "payer's default",
    ],

    'auth' => [
        'login'       => 'Username and password',
        'token'       => 'Token',
        'certificate' => 'Certificate',
        'valid_to'    => 'valid until :date',
        'expired'     => 'expired on :date',
        'no_auth'     => 'No web service authentication: check with the payer whether it only allows by IP',
    ],

    'form' => [
        'title_new'                 => 'New TISS credential',
        'title_edit'                => 'Edit TISS credential',
        'covenant'                  => 'Insurance plan (payer)',
        'covenant_hint'             => "Lists only plans with an ANS registry. The credential applies to the plan's payer.",
        'covenant_placeholder'      => 'Search by name or ANS registry',
        'environment'               => 'Environment',
        'environment_hint'          => 'Homologation and production have separate credentials. Submission uses the one for the environment configured on the server.',
        'endpoint_submission'       => 'Batch submission address',
        'endpoint_submission_hint'  => "TISS web service URL given by the payer. Production requires https. Empty: uses the payer's default address, if any.",
        'endpoint_status'           => 'Connection test address (optional)',
        'endpoint_status_hint'      => 'Empty: the test uses the submission address.',
        'username'                  => 'Username',
        'password'                  => 'Password',
        'token'                     => 'Access token',
        'secret_saved'              => 'Saved. Leave blank to keep it.',
        'secret_not_saved'          => 'Nothing saved.',
        'remove_password'           => 'Delete the saved password',
        'remove_token'              => 'Delete the saved token',
        'certificate'               => 'Client digital certificate',
        'certificate_hint'          => 'Only if the payer requires it. A .pfx or .p12 file (with the key) or a .pem, .crt or .cer file with a separate key. Kept in private storage, outside the public folder.',
        'certificate_saved'         => 'Saved certificate: :subject (valid until :date). Upload another file to replace it.',
        'private_key'               => 'Private key (.pem or .key)',
        'private_key_hint'          => 'Only for a .pem, .crt or .cer certificate without the key in the same file.',
        'certificate_password'      => 'Certificate password',
        'certificate_password_hint' => 'Used to open the certificate and the key. Leave blank to keep the saved one.',
        'remove_certificate'        => 'Delete the saved certificate',
        'ssl_enabled'               => "Verify the payer server's certificate (recommended)",
        'active'                    => 'Active: used for submission',
        'save'                      => 'Save',
        'saving'                    => 'Saving…',
        'cancel'                    => 'Cancel',
        'close'                     => 'Close',
        'discard_confirm'           => 'Discard the changes to this credential?',
    ],

    'actions' => [
        'test'           => 'Test connection',
        'testing'        => 'Testing…',
        'edit'           => 'Edit',
        'delete'         => 'Remove',
        'confirm_delete' => 'Remove the credential for :operator (:environment)? The certificate files are deleted too. With real submission on, this payer has no submission in this environment until another credential is added.',
    ],

    'test' => [
        'note'    => 'The test makes a simple request to the address, without sending a batch or patient data. It confirms network, TLS and authentication; batch acceptance is only confirmed by a submission in homologation.',
        'never'   => 'Never tested',
        'at'      => ':result on :date',
        'results' => [
            'reachable'        => 'Connection OK (HTTP :status, :ms ms)',
            'responded'        => 'The server answered HTTP :status: network and TLS OK, but this address does not answer a simple request (common with SOAP)',
            'auth_rejected'    => 'Authentication rejected (HTTP :status). Check username, password, token or certificate',
            'server_error'     => "The payer's server answered with an error (HTTP :status)",
            'unreachable'      => 'No connection: :reason',
            'blocked'          => 'Address refused: :reason',
            'missing_endpoint' => 'No address: add the submission address',
            'redirect'         => 'The server redirected (HTTP :status). Submission does not follow redirects: register the final address',
            'failed'           => 'Could not build the connection from the saved data (unreadable certificate or secret). Save them again',
        ],
        'reasons' => [
            'timeout'      => 'timed out',
            'dns'          => 'address not found (DNS)',
            'refused'      => 'connection refused',
            'tls'          => 'TLS or certificate failure',
            'other'        => 'network error',
            'invalid'      => 'use http or https, without username and password in the address',
            'private'      => 'it points to the internal network',
            'unresolvable' => 'the name does not resolve to any IP',
        ],
    ],

    'errors' => [
        'covenant_not_found'       => 'Insurance plan not found in this clinic.',
        'covenant_without_ans'     => 'This plan has no ANS registry, so it does not go through TISS.',
        'duplicate'                => 'There is already a credential for this payer in this environment. Edit the existing one.',
        'certificate_unreadable'   => 'Could not open the certificate. Check the file and the password.',
        'private_key_required'     => 'Upload the certificate private key (or use a .pfx or .p12 file).',
        'private_key_mismatch'     => 'The private key does not open with this password or does not match the certificate.',
        'endpoint_invalid'         => 'Invalid address: use http or https, without username and password in the URL.',
        'endpoint_private'         => "Address refused: it points to the server's internal network.",
        'endpoint_unresolvable'    => 'Address refused: the name does not resolve to any IP.',
        'https_required'           => 'In production the address must be https.',
        'secret_reentry'           => 'The address changed: type it again (or mark it to be deleted). The saved value is not sent to a new address without confirmation.',
        'certificate_missing'      => 'The saved certificate was not found on the server. Upload the file again in Financial > TISS credentials.',
        'certificate_legacy'       => 'Certificate in an old format (RC2 or 3DES encryption) that the server cannot open. Export it again in a modern format (AES-256) or ask the issuer for an updated file.',
        'certificate_without_key'  => 'The file does not contain the certificate private key. Export the .pfx with the key.',
        'certificate_store_failed' => 'Could not store the certificate on the server. Try again.',
    ],

    'flash' => [
        'created' => 'TISS credential saved.',
        'updated' => 'TISS credential updated.',
        'deleted' => 'TISS credential removed.',
    ],
];
