<?php

declare(strict_types=1);

return [
    'registration' => [
        'accepted' => 'We have sent an email to that address. Please check your inbox to continue.',
    ],

    'notice' => [
        'subject' => 'Someone tried to register at :app with your email address',
        'attempt' => 'Someone tried to create an account with this email address, but you already have one.',
        'action' => 'Sign in',
        'next' => 'If that was you and you have forgotten your password, you can reset it from the sign-in page. If it was not you, there is nothing you need to do.',
        'next_without_password' => 'If that was you, you can sign in from the sign-in page. If it was not you, there is nothing you need to do.',
    ],
];
