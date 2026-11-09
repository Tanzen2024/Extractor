<?php

/**
 * CUSTOMERS_LIST dashboard (app/Views/dashboard/index.php, DashboardController,
 * filter validation). Text drawn by dashboard.js lives in
 * public/assets/i18n/fr.json ("dashboard").
 */
return [
    'title'    => 'Segmentation Profil Payeur | Segmentation Profil Consommateur',

    'loadError' => 'Impossible de charger les données.',

    'filters' => [
        'title'    => 'Critères de sélection',
        'dateFrom' => 'Date début abonnement',
        'dateTo'   => 'Date fin abonnement',
        'advanced' => 'Filtres avancés',
    ],

    // "Vue Postpaid" / "Vue Prepaid" sections and the scoped detail table.
    'sections' => [
        'grandTotal'     => 'Total clients actifs',
        'grandTotalHint' => 'Postpaid + Prepaid, selon les filtres appliqués',
        'postpaid' => [
            'title'    => 'Vue Postpaid',
            'subtitle' => 'Segmentation des profils payeurs Postpaid',
        ],
        'prepaid'  => [
            'title'    => 'Vue Prepaid',
            'subtitle' => 'Segmentation des profils payeurs Prepaid',
        ],
        'detail'   => [
            'title'    => 'Détail des clients',
            'subtitle' => 'Liste et export des clients actifs selon les filtres appliqués',
        ],
        'scope'    => [
            'label'    => 'Périmètre du tableau et de l’export',
            'all'      => 'Tous',
            'postpaid' => 'Postpaid',
            'prepaid'  => 'Prepaid',
        ],
    ],

    // Freshness indicator; its state and figures are drawn by sync-status.js.
    'sync' => [
        'label'        => 'Fraîcheur des données',
        'history'      => 'Historique',
        'historyTitle' => 'Historique des synchronisations',
        'columns'      => [
            'start'    => 'Début',
            'duration' => 'Durée',
            'trigger'  => 'Origine',
            'status'   => 'Statut',
            'read'     => 'Lus',
            'added'    => 'Ajoutés',
            'modified' => 'Modifiés',
            'ignored'  => 'Inchangés',
            'removed'  => 'Retirés',
            'error'    => 'Erreur',
        ],
    ],

    // Analysis of the 6 Défaillant profile (Postpaid section): not profiles.
    'failure' => [
        'title'        => 'Décomposition du profil Défaillant',
        'subtitle'     => 'Répartition des clients défaillants selon leur comportement de paiement.',
        'filter'       => 'Composante Défaillant',
        'alwaysLate'   => 'Always Late',
        'sometimePaid' => 'Sometime Paid',
        'neverPaid'    => 'Never Paid',
    ],

    'charts' => [
        'bySegmentation'    => 'Répartition par segmentation',
        'segmentationHint'  => 'Nombre de clients par profil payeur. Cliquez sur un profil pour afficher ses clients.',
    ],

    'table' => [
        'search'         => 'Nom, contrat, code client, n° compteur…',
        'columns'        => 'Colonnes',
        'perPage'        => '{0} / page',
        'exportHint'     => 'La population exportée correspond exactement aux filtres appliqués',
        'exportCsv'      => 'Export CSV',
        'exportXlsx'     => 'Export Excel',
        'empty'          => 'Aucune ligne ne correspond aux filtres sélectionnés.',
        'resetFilters'   => 'Réinitialiser les filtres',
    ],

    'export' => [
        'title'   => 'Export des données',
        'results' => 'Résultats :',
        'rows'    => 'ligne(s)',
        'format'  => 'Format :',
        'launch'  => "Lancer l'export",
    ],

    // JSON messages of DashboardController / ExportJobController.
    'messages' => [
        'invalidFormat'       => 'Format invalide.',
        'fileGone'            => "Le fichier n'est plus disponible. Relancez l'export.",
        'snapshotUnavailable' => "Les données de référence ne sont pas disponibles actuellement (réf. {ref}). Réessayez plus tard ou contactez l'administrateur",
        'noFilter'            => 'Aucun filtre (tout le référentiel)',
        'jobFailed'           => "L'export a échoué côté serveur. Relancez-le ; si l'échec se répète, transmettez la référence {ref} à l'administrateur.",
        'jobAlreadyCancelled' => 'Cet export est déjà annulé.',
        'jobAlreadyDone'      => 'Cet export est déjà terminé.',
        'jobCancelledRunning' => "Export annulé. Le traitement s'arrête au prochain lot ; son fichier partiel sera supprimé.",
        'jobCancelledPending' => 'Export annulé avant son démarrage. Aucun fichier généré.',
        'jobFileGone'         => "Le fichier de cet export n'est plus disponible. Relancez l'export.",
    ],

    // Filter validation (App\Services\CustomersList\*), shown as-is to the user.
    'validation' => [
        'dateFrom'        => 'date de début',
        'dateTo'          => 'date de fin',
        'dateOrder'       => 'La date de début est postérieure à la date de fin.',
        'dateFormat'      => 'Format de {label} invalide (attendu AAAA-MM-JJ).',
        'dateInvalid'     => "La {label} n'est pas une date valide.",
        'invalidValue'    => 'Valeur de filtre invalide pour {label}.',
        'pageTooDeep'     => 'Page trop profonde : affinez vos filtres ou votre recherche pour atteindre ces lignes.',
    ],
];
