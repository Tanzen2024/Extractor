<?php

/**
 * Shared interface text: layout, navigation, language switcher, common actions.
 * French (default): app/Language/fr/App.php.
 */
return [
    'tagline'      => 'The right action for every payer and consumer profile, to secure our revenue and win back our dormant customers.',
    'defaultTitle' => 'Dashboard',
    'rights'       => 'All rights reserved.',
    'version'      => 'Version {version} — Phase 1',

    'nav' => [
        'toggleMenu'     => 'Show / hide the menu',
        'dashboard'      => 'Dashboard',
        'administration' => 'ADMINISTRATION',
        'users'          => 'Users',
        'roles'          => 'Roles',
        'permissions'    => 'Permissions',
        'audit'          => 'Audit',
        'logout'         => 'Log out',
        'home'           => 'Home',
    ],

    'language' => [
        'label'  => 'Language',
        'choose' => 'Choose the language',
        'names'  => [
            'fr' => 'Français',
            'en' => 'English',
        ],
    ],

    'theme' => [
        'toDark'  => 'Switch to dark theme',
        'toLight' => 'Switch to light theme',
    ],

    'exportWidget' => 'Export in progress',

    'yes' => 'Yes',
    'no'  => 'No',

    'actions' => [
        'save'       => 'Save',
        'cancel'     => 'Cancel',
        'back'       => 'Back',
        'edit'       => 'Edit',
        'filter'     => 'Filter',
        'retry'      => 'Retry',
        'refresh'    => 'Refresh',
        'apply'      => 'Apply',
        'reset'      => 'Reset',
        'close'      => 'Close',
        'enable'     => 'Enable',
        'disable'    => 'Disable',
        'reactivate' => 'Reactivate',
    ],

    'status' => [
        'active'           => 'Active',
        'inactive'         => 'Inactive',
        'disabled'         => 'Disabled',
        'activeFeminine'   => 'Active',
        'inactiveFeminine' => 'Inactive',
    ],

    'datepicker' => [
        'placeholder'  => 'dd/mm/yyyy',
        'openCalendar' => 'Open the calendar',
    ],

    'errors' => [
        'loginRequired' => 'Please log in to access this page.',
    ],
];
