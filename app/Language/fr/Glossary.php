<?php

/**
 * Business glossary — ONE French term = ONE English term, everywhere.
 * Views and the other catalogs reuse these keys instead of re-translating a
 * business term. The browser catalog (public/assets/i18n/{fr,en}.json,
 * "glossary") carries the same values; tests/unit/I18nCatalogTest.php keeps
 * the two in sync.
 */
return [
    'payerProfile'                => 'Profil Payeur',
    'consumerProfile'             => 'Profil Consommateur',
    'payerProfileSegmentation'    => 'Segmentation Profil Payeur',
    'consumerProfileSegmentation' => 'Segmentation Profil Consommateur',
    'segmentation'                => 'Segmentation',
    'region'                      => 'Région',
    'division'                    => 'Division',
    'agency'                      => 'Agence',
    'status'                      => 'Statut',
    'meter'                       => 'Compteur',
    'meterType'                   => 'Type compteur',
    'treasurySegment'             => 'Trésorerie',
    'voltage'                     => 'Tension',
    'niuQuality'                  => 'Qualité NUI',
    'contactFilled'               => 'Contact renseigné',
    'activeContracts'             => 'Contrats actifs',
    'smartMeters'                 => 'Compteurs communicants',
    'notProvided'                 => 'Non renseigné',
    'subscriptionPeriod'          => 'Période (abonnement)',
];
