<?php
return [
    'username' => 'ramu@rcpro.in',
    'password' => 'HostingMail00$',
    'from_name' => 'Ramu',
    'imap' => [
        'host' => 'rcpro.in',
        'port' => 993,
        // ssl, tls, or none
        'encryption' => 'ssl',
        // Set to false if your server uses a self-signed certificate.
        'validate_cert' => true,
        'default_folder' => 'INBOX',
    ],
    'smtp' => [
        'host' => 'rcpro.in',
        'port' => 465,
        // ssl, tls, or none
        'encryption' => 'ssl',
    ],
];
