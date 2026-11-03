<?php

/**
 * Login / logout (app/Views/auth/login.php, AuthController) and the 403 page.
 */
return [
    'loginTitle'     => 'Connexion',
    'loginIntro'     => "Connectez-vous pour accéder à la plateforme d'extraction",
    'username'       => 'Identifiant',
    'password'       => 'Mot de passe',
    'submit'         => 'SE CONNECTER',
    'missingFields'  => 'Veuillez renseigner un identifiant et un mot de passe.',
    'invalid'        => 'Identifiant ou mot de passe incorrect.',
    'loggedOut'      => 'Vous avez été déconnecté.',

    'forbidden' => [
        'title'      => '403 — Accès refusé',
        'heading'    => 'Accès refusé.',
        'text'       => 'Vous êtes authentifié mais votre rôle ne vous accorde pas cette action.',
        'permission' => 'Permission requise :',
        'back'       => 'Retour au tableau de bord',
    ],
];
