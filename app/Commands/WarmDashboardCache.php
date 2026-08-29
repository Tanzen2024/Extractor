<?php

namespace App\Commands;

use App\Services\CustomersList\DashboardService;
use App\Services\CustomersList\FilterCriteria;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Throwable;

/**
 * Pre-computes the dashboard's cacheable payloads so the first real visit
 * after a cache expiry doesn't pay for a cold full-table scan.
 *
 *   php spark dashboard:warm
 *
 * Reasonable as a cron entry a little more often than the cache TTLs
 * (e.g. every 30 min): "cd /path/to/app && php spark dashboard:warm".
 */
class WarmDashboardCache extends BaseCommand
{
    protected $group       = 'Export';
    protected $name        = 'dashboard:warm';
    protected $description  = 'Warms the CUSTOMERS_LIST dashboard cache (filter-options + unfiltered stats/count).';

    public function run(array $params): int
    {
        $service = new DashboardService();

        try {
            $t = microtime(true);
            $service->filterOptions(true);
            CLI::write('filter-options: ' . $this->ms($t), 'green');

            $none = FilterCriteria::none();

            $t = microtime(true);
            $service->stats($none, true);
            CLI::write('stats (unfiltered): ' . $this->ms($t), 'green');

            $t = microtime(true);
            $service->count($none, '', true);
            CLI::write('count (unfiltered): ' . $this->ms($t), 'green');
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
