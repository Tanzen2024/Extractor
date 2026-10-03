<?php

namespace App\Services\Export;

use App\Services\CustomersList\FilterCriteria;
use App\Services\CustomersList\QueryBuilder;
use App\Services\OracleExtractionService;
use Config\Oracle as OracleConfig;

/**
 * The historical export source: a live streaming SELECT on
 * CMS_RFC.TB_CUSTOMERS_LIST built by QueryBuilder (same WHERE as the
 * dashboard), with the export prefetch tuning. Kept ONLY for the CLI
 * measuring tools that inject Oracle on purpose (export:benchmark --source
 * oracle, export:perf): no user flow can reach it any more — user exports
 * read the snapshot (CustomerListExportService), Oracle is for the refresh.
 */
final class OracleRowSource implements RowSource
{
    private OracleExtractionService $oracle;
    private OracleConfig $config;
    private QueryBuilder $queryBuilder;

    public function __construct(
        ?OracleExtractionService $oracle = null,
        ?OracleConfig $config = null,
        ?QueryBuilder $queryBuilder = null,
    ) {
        $this->config       = $config ?? new OracleConfig();
        $this->oracle       = $oracle ?? new OracleExtractionService($this->config);
        $this->queryBuilder = $queryBuilder ?? new QueryBuilder($this->config);
    }

    public function stream(FilterCriteria $criteria, callable $onRow): int
    {
        $statement = $this->queryBuilder->exportStatement($criteria);

        return $this->oracle->stream($statement['sql'], $onRow, $statement['binds'], prefetch: $this->config->exportPrefetchRows);
    }

    public function label(): string
    {
        return 'oracle';
    }
}
