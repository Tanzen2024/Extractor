<?php

/**
 * Login / logout and the 403 page — see app/Language/fr/Auth.php.
 */
return [
    'loginTitle'     => 'Sign in',
    'loginIntro'     => 'Sign in to access the extraction platform',
    'username'       => 'Username',
    'password'       => 'Password',
    'submit'         => 'SIGN IN',
    'missingFields'  => 'Please enter a username and a password.',
    'invalid'        => 'Incorrect username or password.',
    'loggedOut'      => 'You have been logged out.',

    'forbidden' => [
        'title'      => '403 — Access denied',
        'heading'    => 'Access denied.',
        'text'       => 'You are signed in, but your role does not allow this action.',
        'permission' => 'Required permission:',
        'back'       => 'Back to the dashboard',
    ],
];
