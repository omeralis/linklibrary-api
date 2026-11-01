<?php

return [
    'verify' => [
        'subject' => 'Verify your e-mail address — :app',
        'line' => 'Please confirm your e-mail address to finish setting up your account.',
        'action' => 'Verify e-mail address',
        'ignore' => 'If you did not create an account, no further action is required.',
    ],
    'reset' => [
        'subject' => 'Reset your password — :app',
        'line' => 'We received a request to reset the password for your account.',
        'action' => 'Reset password',
        'expire' => 'This link expires in :count minutes.',
        'ignore' => 'If you did not request a password reset, you can ignore this e-mail.',
    ],
    'password_changed' => [
        'subject' => 'Your password was changed — :app',
        'line' => 'The password for your account was just changed and other sessions were signed out.',
        'contact' => 'If this was not you, reset your password immediately and contact :email.',
    ],
    'email_changed' => [
        'subject' => 'Your account e-mail was changed — :app',
        'line' => 'The e-mail address of your account was changed. This address will no longer receive account messages.',
    ],
    'invitation' => [
        'subject' => ':name shared a link with you on :app',
        'line' => ':name invited you to view a private link.',
        'action' => 'View invitation',
        'expires' => 'This invitation expires on :date.',
        'verify_note' => 'You will need to sign in (or create an account) with this e-mail address and verify it to accept.',
    ],
    'security_warning' => [
        'subject' => 'Security notice about one of your links — :app',
        'line_suspicious' => 'One of your saved links was flagged as suspicious. It has been removed from public discovery and cannot be shared until it is reviewed.',
        'line_malicious' => 'One of your saved links was flagged as malicious. It can no longer be opened, published or shared through the platform.',
        'action' => 'Review the link',
        'appeal' => 'If you believe this is a mistake, you can request a review.',
    ],
];
