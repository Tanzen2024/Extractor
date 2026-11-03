<?php

/**
 * Shared interface text: layout, navigation, language switcher, common actions.
 * English: app/Language/en/App.php (same keys — tests/unit/I18nCatalogTest.php).
 */
return [
    'tagline'      => 'La bonne action à chaque profil de payeur et de consommateur, pour sécuriser nos recettes et réactiver nos clients dormants.',
    'defaultTitle' => 'Tableau de bord',
    'rights'       => 'Tous droits réservés.',
    'version'      => 'Version {version} — Phase 1',

    'nav' => [
        'toggleMenu'     => 'Afficher / masquer le menu',
        'dashboard'      => 'Dashboard',
        'administration' => 'ADMINISTRATION',
        'users'          => 'Utilisateur',
        'roles'          => 'Rôles',
        'permissions'    => 'Permissions',
        'audit'          => 'Audit',
        'logout'         => 'Déconnexion',
        'home'           => 'Accueil',
    ],

    'language' => [
        'label'  => 'Langue',
        'choose' => 'Choisir la langue',
        // Each language is named in its own language, in both catalogs.
        'names'  => [
            'fr' => 'Français',
            'en' => 'English',
        ],
    ],

    // Light / dark theme switch (navbar, public/assets/js/theme.js).
    'theme' => [
        'toDark'  => 'Passer au thème sombre',
        'toLight' => 'Passer au thème clair',
    ],

    'exportWidget' => 'Export en cours',

    'yes' => 'Oui',
    'no'  => 'Non',

    'actions' => [
        'save'       => 'Enregistrer',
        'cancel'     => 'Annuler',
        'back'       => 'Retour',
        'edit'       => 'Modifier',
        'filter'     => 'Filtrer',
        'retry'      => 'Réessayer',
        'refresh'    => 'Rafraîchir',
        'apply'      => 'Appliquer',
        'reset'      => 'Réinitialiser',
        'close'      => 'Fermer',
        'enable'     => 'Activer',
        'disable'    => 'Désactiver',
        'reactivate' => 'Réactiver',
    ],

    'status' => [
        'active'           => 'Actif',
        'inactive'         => 'Inactif',
        'disabled'         => 'Désactivé',
        'activeFeminine'   => 'Active',
        'inactiveFeminine' => 'Inactive',
    ],

    'datepicker' => [
        'placeholder'  => 'jj/mm/aaaa',
        'openCalendar' => 'Ouvrir le calendrier',
    ],

    'errors' => [
        'loginRequired' => 'Veuillez vous connecter pour accéder à cette page.',
    ],
];
