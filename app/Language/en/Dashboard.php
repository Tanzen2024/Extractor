<?php

/**
 * CUSTOMERS_LIST dashboard — see app/Language/fr/Dashboard.php.
 */
return [
    'title'    => 'Payer Profile Segmentation | Consumer Profile Segmentation',

    'loadError' => 'Unable to load the data.',

    'filters' => [
        'title'    => 'Selection criteria',
        'dateFrom' => 'Subscription start date',
        'dateTo'   => 'Subscription end date',
        'advanced' => 'Advanced filters',
    ],

    // "Postpaid View" / "Prepaid View" sections and the scoped detail table.
    'sections' => [
        'grandTotal'     => 'Total active customers',
        'grandTotalHint' => 'Postpaid + Prepaid, under the applied filters',
        'postpaid' => [
            'title'    => 'Postpaid View',
            'subtitle' => 'Postpaid payer-profile segmentation',
        ],
        'prepaid'  => [
            'title'    => 'Prepaid View',
            'subtitle' => 'Prepaid payer-profile segmentation',
        ],
        'detail'   => [
            'title'    => 'Customer details',
            'subtitle' => 'List and export of the active customers matching the applied filters',
        ],
        'scope'    => [
            'label'    => 'Scope of the table and export',
            'all'      => 'All',
            'postpaid' => 'Postpaid',
            'prepaid'  => 'Prepaid',
        ],
    ],

    // Freshness indicator; its state and figures are drawn by sync-status.js.
    'sync' => [
        'label'        => 'Data freshness',
        'history'      => 'History',
        'historyTitle' => 'Synchronisation history',
        'columns'      => [
            'start'    => 'Start',
            'duration' => 'Duration',
            'trigger'  => 'Origin',
            'status'   => 'Status',
            'read'     => 'Read',
            'added'    => 'Added',
            'modified' => 'Modified',
            'ignored'  => 'Unchanged',
            'removed'  => 'Removed',
            'error'    => 'Error',
        ],
    ],

    // Analysis of the 6 Défaillant profile (Postpaid section): not profiles.
    'failure' => [
        'title'        => 'Défaillant profile breakdown',
        'subtitle'     => 'Défaillant customers by payment behaviour.',
        'filter'       => 'Défaillant component',
        'alwaysLate'   => 'Always Late',
        'sometimePaid' => 'Sometime Paid',
        'neverPaid'    => 'Never Paid',
    ],

    'charts' => [
        'bySegmentation'    => 'Breakdown by segmentation',
        'segmentationHint'  => 'Customers per payer profile. Click a profile to list its customers.',
    ],

    'table' => [
        'search'         => 'Name, contract, customer code, meter no.…',
        'columns'        => 'Columns',
        'perPage'        => '{0} / page',
        'exportHint'     => 'The exported population matches the applied filters exactly',
        'exportCsv'      => 'Export CSV',
        'exportXlsx'     => 'Export Excel',
        'empty'          => 'No rows match the selected filters.',
        'resetFilters'   => 'Reset filters',
    ],

    'export' => [
        'title'   => 'Data export',
        'results' => 'Results:',
        'rows'    => 'row(s)',
        'format'  => 'Format:',
        'launch'  => 'Start export',
    ],

    'messages' => [
        'invalidFormat'       => 'Invalid format.',
        'fileGone'            => 'The file is no longer available. Start the export again.',
        'snapshotUnavailable' => 'The reference data is currently unavailable (ref. {ref}). Please try again later or contact the administrator',
        'noFilter'            => 'No filters (full dataset)',
        'jobFailed'           => 'The export failed on the server. Start it again; if it keeps failing, send reference {ref} to the administrator.',
        'jobAlreadyCancelled' => 'This export has already been cancelled.',
        'jobAlreadyDone'      => 'This export has already finished.',
        'jobCancelledRunning' => 'Export cancelled. Processing stops at the next batch; its partial file will be deleted.',
        'jobCancelledPending' => 'Export cancelled before it started. No file was generated.',
        'jobFileGone'         => 'The file of this export is no longer available. Start the export again.',
    ],

    'validation' => [
        'dateFrom'        => 'start date',
        'dateTo'          => 'end date',
        'dateOrder'       => 'The start date is after the end date.',
        'dateFormat'      => 'Invalid {label} format (expected YYYY-MM-DD).',
        'dateInvalid'     => 'The {label} is not a valid date.',
        'invalidValue'    => 'Invalid filter value for {label}.',
        'pageTooDeep'     => 'Page too deep: narrow your filters or search to reach these rows.',
    ],
];
