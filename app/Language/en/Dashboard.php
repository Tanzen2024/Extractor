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
    ],

    'kpi' => [
        'heading'     => 'Active Customers Overview',
        'actifs'      => 'Active customers',
        'nuiCorrects' => 'NUMBER OF CORRECT NIUs',
        'contacts'    => 'Contacts on file',
    ],

    // "Postpaid View" / "Prepaid View" sections and the scoped detail table.
    'sections' => [
        'nav'      => 'Jump to a view',
        'postpaid' => [
            'title'    => 'Postpaid View',
            'subtitle' => 'Postpaid Reports & Analytics',
        ],
        'prepaid'  => [
            'title'    => 'Prepaid View',
            'subtitle' => 'Prepaid Reports & Analytics',
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

    'charts' => [
        'byRegion'          => 'Breakdown by region',
        'byStatus'          => 'Breakdown by status',
        'bySegmentation'    => 'Breakdown by segmentation',
        'meterTypes'        => 'Meter types',
        'meterTypesHint'    => 'Customers by meter type',
        'meterTotal'        => 'Total meters',
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
