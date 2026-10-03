<?php

namespace App\Commands;

use App\Services\CustomersList\DashboardService;
use App\Services\CustomersList\FilterCriteria;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Oracle as OracleConfig;
use Throwable;

/**
 * Pre-computes the dashboard's payloads for the ACTIVE SNAPSHOT version, so
 * the first visit after a refresh does not pay for the first queries:
 * unfiltered, and with the first-load filter (the active statuses, see
 * dashboard-defaults.js). Reads the snapshot's DuckDB index only — never
 * Oracle. Cached per version: run it right after `customers:refresh`.
 *
 *   php spark dashboard:warm
 */
class WarmDashboardCache extends BaseCommand
{
    protected $group       = 'Export';
    protected $name        = 'dashboard:warm';
    protected $description  = 'Warms the CUSTOMERS_LIST dashboard cache of the active snapshot (unfiltered + first-load filter).';

    public function run(array $params): int
    {
        $service = new DashboardService();

        try {
            CLI::write('Snapshot : ' . $service->engine()->version());

            $allowed  = array_column($service->filterOptions()['statuses'] ?? [], 'value');
            $sets     = [
                'sans filtre'            => FilterCriteria::none(),
                'statuts actifs (défaut)' => FilterCriteria::fromArray(['statuses' => array_values(array_intersect((new OracleConfig())->activeStatuses, $allowed))]),
            ];

            foreach ($sets as $label => $criteria) {
                $t = microtime(true);
                $service->stats($criteria, true);
                $service->count($criteria, '', true);
                $service->segmentationCounts($criteria, true);
                CLI::write("{$label} : " . $this->ms($t), 'green');
            }
        } catch (Throwable $e) {
            CLI::error('Warm-up KO: ' . $e->getMessage());

            return EXIT_ERROR;
        }

        return EXIT_SUCCESS;
    }

    private function ms(float $start): string
    {
        return round((microtime(true) - $start) * 1000) . ' ms';
    }
}
